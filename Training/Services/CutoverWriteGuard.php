<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Training\Enums\CutoverWorkflow;
use App\Domains\People\Training\Enums\CutoverWriter;
use App\Domains\People\Training\Exceptions\CutoverWriteRefusedException;
use App\Domains\People\Training\Exceptions\InvalidCutoverWindowException;
use App\Domains\People\Training\Models\TrainingCutoverWindow;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Declared cutover windows and the write guard stores consult (0015-f).
 *
 * While a workflow's authoritative writer is legacy, writes through People
 * stores are refused with CutoverWriteRefusedException. Reads are unaffected.
 * Overlapping windows for the same company and workflow are refused at
 * declaration time so two writers never share an instant.
 */
final class CutoverWriteGuard
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly CompanyAttribution $companies,
    ) {}

    public function declare(
        User $actor,
        int $companyEntityId,
        CutoverWorkflow $workflow,
        CutoverWriter $writer,
        DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        string $reason,
    ): TrainingCutoverWindow {
        $tenantId = $this->authorize($actor, $companyEntityId);
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidCutoverWindowException('A cutover window needs a reason.');
        }
        if ($endsAt !== null && $endsAt < $startsAt) {
            throw new InvalidCutoverWindowException('A cutover window cannot end before it starts.');
        }

        return DB::transaction(function () use ($tenantId, $companyEntityId, $actor, $workflow, $writer, $startsAt, $endsAt, $reason): TrainingCutoverWindow {
            // Serialize declarations per company before the empty-set lock query:
            // with no windows yet, lockForUpdate on the window table locks nothing,
            // so two concurrent first declares could both insert overlapping ranges.
            Company::query()
                ->whereKey($companyEntityId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $clash = TrainingCutoverWindow::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('workflow', $workflow->value)
                ->orderBy('starts_at')
                ->lockForUpdate()
                ->get()
                ->first(fn (TrainingCutoverWindow $window): bool => $this->overlaps(
                    $startsAt,
                    $endsAt,
                    $window->starts_at->toDateTimeImmutable(),
                    $window->ends_at?->toDateTimeImmutable(),
                ));

            if ($clash !== null) {
                throw new InvalidCutoverWindowException(sprintf(
                    'A cutover window for %s already covers part of that range; overlapping windows are refused.',
                    $workflow->label(),
                ));
            }

            return TrainingCutoverWindow::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyEntityId,
                'workflow' => $workflow,
                'writer' => $writer,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'declared_by_user_id' => $actor->getKey(),
                'declared_at' => now(),
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Refuse when the covering window names the legacy portal. No window means
     * unrestricted (People may write). A system window also allows writes.
     */
    public function assertWritable(int $companyEntityId, CutoverWorkflow $workflow, ?DateTimeImmutable $at = null): void
    {
        $at ??= new DateTimeImmutable('now');
        $window = $this->covering($companyEntityId, $workflow, $at);
        if ($window === null || $window->writer !== CutoverWriter::Legacy) {
            return;
        }

        throw new CutoverWriteRefusedException(
            $workflow,
            $window->starts_at->toDateTimeImmutable(),
            $window->ends_at?->toDateTimeImmutable(),
        );
    }

    public function covering(int $companyEntityId, CutoverWorkflow $workflow, DateTimeImmutable $at): ?TrainingCutoverWindow
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->requireCompany($tenantId, $companyEntityId);
        $instant = $at->format('Y-m-d H:i:s');

        return TrainingCutoverWindow::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('workflow', $workflow->value)
            ->where('starts_at', '<=', $instant)
            ->orderByDesc('id')
            ->get()
            ->first(static fn (TrainingCutoverWindow $window): bool => $window->ends_at === null || $window->ends_at->toDateTimeImmutable() >= $at);
    }

    private function overlaps(
        DateTimeImmutable $aStart,
        ?DateTimeImmutable $aEnd,
        DateTimeImmutable $bStart,
        ?DateTimeImmutable $bEnd,
    ): bool {
        // [aStart, aEnd] overlaps [bStart, bEnd] when each starts no later than the other ends.
        if ($aEnd !== null && $aEnd < $bStart) {
            return false;
        }
        if ($bEnd !== null && $bEnd < $aStart) {
            return false;
        }

        return true;
    }

    private function authorize(User $actor, int $companyEntityId): int
    {
        $tenantId = $this->tenants->requireTenantId();
        if (! $this->companies->mayActFor($actor, $companyEntityId)) {
            throw new InvalidCutoverWindowException('Cutover windows are unavailable in the current company scope.');
        }
        $this->authorization->authorize(Actor::forUser($actor), TrainingMigrationSourceStore::MANAGE);
        $this->requireCompany($tenantId, $companyEntityId);

        return $tenantId;
    }

    private function requireCompany(int $tenantId, int $companyEntityId): void
    {
        $exists = Company::query()->where('id', $companyEntityId)->where('tenant_id', $tenantId)->exists();
        if (! $exists) {
            throw new InvalidCutoverWindowException('Company '.$companyEntityId.' does not belong to the current tenant.');
        }
    }
}
