<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Training\Data\TrainingMigrationFieldMappingDraft;
use App\Domains\People\Training\Enums\MigrationWorkflow;
use App\Domains\People\Training\Enums\MigrationWriter;
use App\Domains\People\Training\Exceptions\InvalidTrainingMigrationMappingException;
use App\Domains\People\Training\Models\TrainingMigrationFieldMapping;
use App\Domains\People\Training\Models\TrainingMigrationMappingSignoff;
use App\Domains\People\Training\Models\TrainingMigrationSource;
use App\Domains\People\Training\Models\TrainingMigrationWriterWindow;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The field and code mapping register and the authoritative writer windows
 * (0015-b): where each legacy field lands, how overlapping records are
 * deduplicated, and which system may write a workflow between two dates.
 *
 * Both registers are append-only (a correction is a new row) and stop taking
 * rows once HR signs the mapping set, so the register an import reads is the
 * one that was signed. A writer window is refused when it overlaps a window
 * of the same workflow and company held by the other writer: no cutover day
 * has two authoritative writers. Windows of the same writer may overlap, and
 * an open-ended window covers every day from its start.
 *
 * authoritativeWriter() is what the later import and cutover lanes consult;
 * it takes no actor because it is the rule an import obeys, not a page.
 */
final class TrainingMigrationMappingStore
{
    public const VIEW = TrainingMigrationSourceStore::VIEW;

    public const MANAGE = TrainingMigrationSourceStore::MANAGE;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly CompanyAttribution $companies,
    ) {}

    /** Append one field (and optionally code) mapping. */
    public function map(User $actor, int $companyEntityId, TrainingMigrationFieldMappingDraft $draft): TrainingMigrationFieldMapping
    {
        $tenantId = $this->authorize($actor, $companyEntityId, self::MANAGE);
        $attributes = $this->validatedMapping($draft);

        return DB::transaction(function () use ($tenantId, $companyEntityId, $actor, $draft, $attributes): TrainingMigrationFieldMapping {
            $this->requireUnsigned($tenantId, $companyEntityId);
            $source = TrainingMigrationSource::query()->forCompany($tenantId, $companyEntityId)->whereKey($draft->sourceId)->first()
                ?? throw new InvalidTrainingMigrationMappingException('Migration source was not found in this company.');

            return TrainingMigrationFieldMapping::query()->create([
                'tenant_id' => $tenantId, 'company_entity_id' => $companyEntityId,
                'training_migration_source_id' => $source->id,
                'created_by_user_id' => $actor->getKey(),
            ] + $attributes);
        });
    }

    /**
     * Append a window during which $writer is the authoritative writer of
     * $workflow. Refused when a window of the other writer for the same
     * workflow shares at least one day with it.
     */
    public function declareWriter(
        User $actor,
        int $companyEntityId,
        MigrationWorkflow $workflow,
        MigrationWriter $writer,
        DateTimeImmutable $startsOn,
        ?DateTimeImmutable $endsOn = null,
    ): TrainingMigrationWriterWindow {
        $tenantId = $this->authorize($actor, $companyEntityId, self::MANAGE);
        $from = $startsOn->format('Y-m-d');
        $to = $endsOn?->format('Y-m-d');
        if ($to !== null && $to < $from) {
            throw new InvalidTrainingMigrationMappingException('A writer window cannot end before it starts.');
        }

        return DB::transaction(function () use ($tenantId, $companyEntityId, $actor, $workflow, $writer, $from, $to): TrainingMigrationWriterWindow {
            $this->requireUnsigned($tenantId, $companyEntityId);

            // Two windows share a day when each starts no later than the other
            // ends; an open-ended window never ends. The start compare is
            // whereDate (SQLite stores date columns with a time part); the
            // nullable end is read back as a date and compared here, because
            // the company-scope guard refuses an OR anywhere in the query.
            $clash = TrainingMigrationWriterWindow::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('workflow', $workflow->value)
                ->where('writer', '!=', $writer->value)
                ->when($to !== null, fn (Builder $query) => $query->whereDate('starts_on', '<=', $to))
                ->orderBy('starts_on')
                ->lockForUpdate()
                ->get()
                ->first(static fn (TrainingMigrationWriterWindow $window): bool => $window->ends_on === null || $window->ends_on->toDateString() >= $from);
            if ($clash !== null) {
                throw new InvalidTrainingMigrationMappingException(sprintf(
                    '%s is already the authoritative writer of %s from %s%s; two writers cannot share a day.',
                    $clash->writer->label(), $workflow->label(), $clash->starts_on->toDateString(),
                    $clash->ends_on === null ? ' onwards' : ' to '.$clash->ends_on->toDateString(),
                ));
            }

            return TrainingMigrationWriterWindow::query()->create([
                'tenant_id' => $tenantId, 'company_entity_id' => $companyEntityId,
                'workflow' => $workflow, 'writer' => $writer,
                'starts_on' => $from, 'ends_on' => $to,
                'created_by_user_id' => $actor->getKey(),
            ]);
        });
    }

    /** Append the one sign-off a company's mapping set gets; both registers close with it. */
    public function signMappings(User $actor, int $companyEntityId, ?string $note = null): TrainingMigrationMappingSignoff
    {
        $tenantId = $this->authorize($actor, $companyEntityId, self::MANAGE);

        // Own transaction so a losing race is contained: PostgreSQL aborts the
        // enclosing transaction on a failed insert.
        try {
            return DB::transaction(static fn (): TrainingMigrationMappingSignoff => TrainingMigrationMappingSignoff::query()->create([
                'tenant_id' => $tenantId, 'company_entity_id' => $companyEntityId,
                'signed_by_user_id' => $actor->getKey(),
                'signed_at' => now(),
                'note' => trim((string) $note) ?: null,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new InvalidTrainingMigrationMappingException('The mapping set of this company is already signed.');
        }
    }

    /**
     * The writer whose window covers $on for the workflow, or null when none
     * does. Unique by construction: declareWriter() never lets two writers
     * share a day.
     */
    public function authoritativeWriter(int $companyEntityId, MigrationWorkflow $workflow, DateTimeImmutable $on): ?string
    {
        $day = $on->format('Y-m-d');

        return TrainingMigrationWriterWindow::query()
            ->forCompany($this->tenantId(), $companyEntityId)
            ->where('workflow', $workflow->value)
            ->whereDate('starts_on', '<=', $day)
            ->orderBy('id')
            ->get()
            ->first(static fn (TrainingMigrationWriterWindow $window): bool => $window->ends_on === null || $window->ends_on->toDateString() >= $day)
            ?->writer->value;
    }

    /** True once the company's mapping set carries its sign-off. No actor: this is the rule an import obeys. */
    public function signedMappings(int $companyEntityId): bool
    {
        return $this->signoff($this->tenantId(), $companyEntityId) !== null;
    }

    public function mappingSignoff(User $actor, int $companyEntityId): ?TrainingMigrationMappingSignoff
    {
        return $this->signoff($this->authorize($actor, $companyEntityId, self::VIEW), $companyEntityId);
    }

    /** @return Collection<int, TrainingMigrationFieldMapping> the company's mappings, oldest first, source attached */
    public function mappings(User $actor, int $companyEntityId): Collection
    {
        $tenantId = $this->authorize($actor, $companyEntityId, self::VIEW);
        $mappings = TrainingMigrationFieldMapping::query()->forCompany($tenantId, $companyEntityId)->orderBy('id')->get();
        $sources = TrainingMigrationSource::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('id', $mappings->pluck('training_migration_source_id'))->get()->keyBy('id');

        foreach ($mappings as $mapping) {
            $mapping->setRelation('source', $sources->get($mapping->training_migration_source_id));
        }

        return $mappings;
    }

    /** @return Collection<int, TrainingMigrationWriterWindow> the company's windows by workflow, then start */
    public function writerWindows(User $actor, int $companyEntityId): Collection
    {
        $tenantId = $this->authorize($actor, $companyEntityId, self::VIEW);

        return TrainingMigrationWriterWindow::query()->forCompany($tenantId, $companyEntityId)
            ->orderBy('workflow')->orderBy('starts_on')->orderBy('id')->get();
    }

    private function requireUnsigned(int $tenantId, int $companyEntityId): void
    {
        if ($this->signoff($tenantId, $companyEntityId) !== null) {
            throw new InvalidTrainingMigrationMappingException('The mapping set is signed: nothing can be added for this company.');
        }
    }

    private function signoff(int $tenantId, int $companyEntityId): ?TrainingMigrationMappingSignoff
    {
        return TrainingMigrationMappingSignoff::query()->forCompany($tenantId, $companyEntityId)->first();
    }

    /** @return array<string, string|null> */
    private function validatedMapping(TrainingMigrationFieldMappingDraft $draft): array
    {
        $field = trim($draft->sourceField);
        if ($field === '') {
            throw new InvalidTrainingMigrationMappingException('A mapping needs the source field it reads.');
        }
        $identifier = '/^[a-z][a-z0-9_]{0,63}$/';
        $table = trim($draft->targetTable);
        $column = trim($draft->targetColumn);
        if (preg_match($identifier, $table) !== 1 || ! Schema::hasTable($table)) {
            throw new InvalidTrainingMigrationMappingException('The target table must be an existing People table.');
        }
        if (preg_match($identifier, $column) !== 1 || ! Schema::hasColumn($table, $column)) {
            throw new InvalidTrainingMigrationMappingException('The target column must exist on the target table.');
        }
        $rule = trim($draft->dedupRule);
        if ($rule === '') {
            throw new InvalidTrainingMigrationMappingException('A mapping needs the dedup rule that decides between overlapping records.');
        }

        return [
            'source_field' => $field,
            'source_code' => trim((string) $draft->sourceCode) ?: null,
            'target_table' => $table,
            'target_column' => $column,
            'dedup_rule' => $rule,
        ];
    }

    private function authorize(User $actor, int $companyEntityId, string $capability): int
    {
        $tenantId = $this->tenantId();
        if (! $this->companies->mayActFor($actor, $companyEntityId)) {
            throw new InvalidTrainingMigrationMappingException('The mapping register is unavailable in the current company scope.');
        }
        $this->authorization->authorize(Actor::forUser($actor), $capability);

        return $tenantId;
    }

    private function tenantId(): int
    {
        return $this->tenants->currentTenantId()
            ?? throw new InvalidTrainingMigrationMappingException('A tenant context is required for the mapping register.');
    }
}
