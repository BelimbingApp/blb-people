<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Training\Data\TrainingMigrationSourceDraft;
use App\Domains\People\Training\Exceptions\InvalidTrainingMigrationSourceException;
use App\Domains\People\Training\Models\TrainingMigrationSource;
use App\Domains\People\Training\Models\TrainingMigrationSourceSignoff;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The migration source inventory (0015-a): what HR intends to import from,
 * signed before any production import runs.
 *
 * Recording and updating are HR's; a source stops being editable the moment
 * it is signed, because a signature over a record that can still change is a
 * signature over nothing. Signing appends one row per source and the unique
 * key refuses a second, so "signed" is a fact the table proves rather than a
 * flag somebody set. signedInventory() is the gate the later import lanes
 * consult: every recorded source of the company must carry a sign-off.
 */
final class TrainingMigrationSourceStore
{
    public const VIEW = 'people.training.migration.view';

    public const MANAGE = 'people.training.migration.manage';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly CompanyAttribution $companies,
    ) {}

    public function record(User $actor, int $companyEntityId, TrainingMigrationSourceDraft $draft): TrainingMigrationSource
    {
        $tenantId = $this->authorize($actor, $companyEntityId, self::MANAGE);
        $attributes = $this->validated($companyEntityId, $draft);

        try {
            return DB::transaction(static fn (): TrainingMigrationSource => TrainingMigrationSource::query()->create([
                'tenant_id' => $tenantId, 'company_entity_id' => $companyEntityId,
                'created_by_user_id' => $actor->getKey(),
            ] + $attributes));
        } catch (UniqueConstraintViolationException) {
            throw new InvalidTrainingMigrationSourceException('A migration source with this key already exists in the company.');
        }
    }

    /** Change what a source says, while nobody has signed it. */
    public function update(User $actor, int $companyEntityId, int $sourceId, TrainingMigrationSourceDraft $draft): TrainingMigrationSource
    {
        $tenantId = $this->authorize($actor, $companyEntityId, self::MANAGE);
        $attributes = $this->validated($companyEntityId, $draft);

        return DB::transaction(function () use ($tenantId, $companyEntityId, $sourceId, $attributes): TrainingMigrationSource {
            $source = $this->find($tenantId, $companyEntityId, $sourceId);
            if ($this->isSigned($source)) {
                throw new InvalidTrainingMigrationSourceException('A signed migration source cannot be changed.');
            }

            try {
                $source->update($attributes);
            } catch (UniqueConstraintViolationException) {
                throw new InvalidTrainingMigrationSourceException('A migration source with this key already exists in the company.');
            }

            return $source->refresh();
        });
    }

    /** Append the one sign-off a source gets. */
    public function sign(User $actor, int $companyEntityId, int $sourceId, ?string $note = null): TrainingMigrationSourceSignoff
    {
        $tenantId = $this->authorize($actor, $companyEntityId, self::MANAGE);

        return DB::transaction(function () use ($tenantId, $companyEntityId, $sourceId, $actor, $note): TrainingMigrationSourceSignoff {
            $source = $this->find($tenantId, $companyEntityId, $sourceId);
            if ($this->isSigned($source)) {
                throw new InvalidTrainingMigrationSourceException('This migration source is already signed.');
            }

            // Own transaction (a savepoint here) so a losing race is contained:
            // PostgreSQL aborts the enclosing transaction on a failed insert.
            try {
                return DB::transaction(static fn (): TrainingMigrationSourceSignoff => TrainingMigrationSourceSignoff::query()->create([
                    'tenant_id' => $tenantId, 'company_entity_id' => $companyEntityId,
                    'training_migration_source_id' => $source->id,
                    'signed_by_user_id' => $actor->getKey(),
                    'signed_at' => now(),
                    'note' => trim((string) $note) ?: null,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new InvalidTrainingMigrationSourceException('This migration source is already signed.');
            }
        });
    }

    /**
     * The inventory of one company, oldest first, with its sign-off attached.
     *
     * @return Collection<int, TrainingMigrationSource>
     */
    public function inventory(User $actor, int $companyEntityId): Collection
    {
        $tenantId = $this->authorize($actor, $companyEntityId, self::VIEW);

        $sources = TrainingMigrationSource::query()->forCompany($tenantId, $companyEntityId)->orderBy('id')->get();
        $signoffs = TrainingMigrationSourceSignoff::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('training_migration_source_id', $sources->pluck('id'))
            ->get()
            ->keyBy('training_migration_source_id');

        foreach ($sources as $source) {
            $source->setRelation('signoff', $signoffs->get((int) $source->id));
        }

        return $sources;
    }

    /**
     * True only when every recorded source of the company carries a sign-off.
     *
     * No actor and no capability check: this is the rule a later import obeys,
     * not somebody reading the page. An empty inventory is not signed: nothing
     * recorded is nothing signed, and an import with no declared source is the
     * case the gate exists to stop.
     */
    public function signedInventory(int $companyEntityId): bool
    {
        $tenantId = $this->tenantId();
        $sources = TrainingMigrationSource::query()->forCompany($tenantId, $companyEntityId)->count();

        if ($sources === 0) {
            return false;
        }

        $signed = TrainingMigrationSourceSignoff::query()->forCompany($tenantId, $companyEntityId)->count();

        return $signed >= $sources;
    }

    private function isSigned(TrainingMigrationSource $source): bool
    {
        return TrainingMigrationSourceSignoff::query()
            ->forCompany((int) $source->tenant_id, (int) $source->company_entity_id)
            ->where('training_migration_source_id', $source->id)
            ->exists();
    }

    /** @return array<string, mixed> */
    private function validated(int $companyEntityId, TrainingMigrationSourceDraft $draft): array
    {
        $key = trim($draft->sourceKey);
        if ($key === '' || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $key) !== 1) {
            throw new InvalidTrainingMigrationSourceException('A source key is lowercase letters, digits, dots, dashes or underscores, up to 64 characters.');
        }
        foreach ([$draft->name, $draft->format] as $text) {
            if (trim($text) === '') {
                throw new InvalidTrainingMigrationSourceException('A migration source needs a name and a format.');
            }
        }
        if ($draft->estimatedVolume !== null && $draft->estimatedVolume < 0) {
            throw new InvalidTrainingMigrationSourceException('An estimated volume cannot be negative.');
        }
        if ($draft->ownerEmployeeEntityId !== null && ! Employee::query()
            ->where('company_id', $companyEntityId)->whereKey($draft->ownerEmployeeEntityId)->exists()) {
            throw new InvalidTrainingMigrationSourceException('The owner must be an employee of this company.');
        }

        return [
            'source_key' => $key,
            'name' => trim($draft->name),
            'kind' => $draft->kind,
            'owner_employee_id' => $draft->ownerEmployeeEntityId,
            'format' => trim($draft->format),
            'estimated_volume' => $draft->estimatedVolume,
            'retention_note' => trim((string) $draft->retentionNote) ?: null,
            'data_quality_note' => trim((string) $draft->dataQualityNote) ?: null,
        ];
    }

    private function find(int $tenantId, int $companyEntityId, int $sourceId): TrainingMigrationSource
    {
        return TrainingMigrationSource::query()->forCompany($tenantId, $companyEntityId)->whereKey($sourceId)->lockForUpdate()->first()
            ?? throw new InvalidTrainingMigrationSourceException('Migration source was not found in this company.');
    }

    private function authorize(User $actor, int $companyEntityId, string $capability): int
    {
        $tenantId = $this->tenantId();
        if (! $this->companies->mayActFor($actor, $companyEntityId)) {
            throw new InvalidTrainingMigrationSourceException('The migration inventory is unavailable in the current company scope.');
        }
        $this->authorization->authorize(Actor::forUser($actor), $capability);

        return $tenantId;
    }

    private function tenantId(): int
    {
        return $this->tenants->currentTenantId()
            ?? throw new InvalidTrainingMigrationSourceException('A tenant context is required for the migration inventory.');
    }
}
