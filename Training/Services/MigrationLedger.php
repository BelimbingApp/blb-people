<?php

namespace App\Domains\People\Training\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Training\Enums\MigrationLedgerStatus;
use App\Domains\People\Training\Exceptions\InvalidMigrationLedgerException;
use App\Domains\People\Training\Models\TrainingMigrationLedgerEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Source provenance and quarantine for Training migration rows (0015-d).
 *
 * Every applied or rejected source row is durable here. Counts alone are not
 * enough: reconcile() later proves each migrated target still matches, or
 * marks it drifted. Rejected rows stay for remediation; they are never
 * dropped.
 */
final class MigrationLedger
{
    public function __construct(
        private readonly TenantContext $tenants,
    ) {}

    public function recordMigrated(
        int $companyEntityId,
        string $sourceKey,
        string $sourceSha256,
        int $sourceRow,
        string $targetTable,
        int $targetId,
        int $recordedBy,
    ): TrainingMigrationLedgerEntry {
        return $this->insert(
            $companyEntityId,
            $sourceKey,
            $sourceSha256,
            $sourceRow,
            $targetTable,
            $targetId,
            MigrationLedgerStatus::Migrated,
            null,
            null,
            $recordedBy,
        );
    }

    /**
     * Quarantine a refused source row. The excerpt is what an operator needs
     * to find the row again; the reason is why it was refused.
     *
     * @param  array<string, mixed>  $payloadExcerpt
     */
    public function recordRejected(
        int $companyEntityId,
        string $sourceKey,
        string $sourceSha256,
        int $sourceRow,
        string $reason,
        array $payloadExcerpt,
        int $recordedBy,
    ): TrainingMigrationLedgerEntry {
        return $this->insert(
            $companyEntityId,
            $sourceKey,
            $sourceSha256,
            $sourceRow,
            '',
            null,
            MigrationLedgerStatus::Rejected,
            $reason,
            $payloadExcerpt,
            $recordedBy,
        );
    }

    /** @return Collection<int, TrainingMigrationLedgerEntry> */
    public function listRejected(int $companyEntityId): Collection
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->requireCompany($tenantId, $companyEntityId);

        return TrainingMigrationLedgerEntry::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('status', MigrationLedgerStatus::Rejected)
            ->orderBy('source_key')
            ->orderBy('source_row')
            ->get();
    }

    public function markReconciled(int $companyEntityId, int $ledgerId): TrainingMigrationLedgerEntry
    {
        return $this->mark($companyEntityId, $ledgerId, MigrationLedgerStatus::Reconciled);
    }

    public function markDrifted(int $companyEntityId, int $ledgerId): TrainingMigrationLedgerEntry
    {
        return $this->mark($companyEntityId, $ledgerId, MigrationLedgerStatus::Drifted);
    }

    /**
     * For each migrated row, prove the target still exists (and is not
     * retired), then mark reconciled or drifted. Dry-run computes outcomes
     * and writes nothing.
     *
     * @return array{migrated: int, rejected: int, reconciled: int, drifted: int, by_source: array<string, array{migrated: int, rejected: int, reconciled: int, drifted: int}>}
     */
    public function reconcile(int $companyEntityId, bool $dryRun = false): array
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->requireCompany($tenantId, $companyEntityId);

        $migrated = TrainingMigrationLedgerEntry::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('status', MigrationLedgerStatus::Migrated)
            ->orderBy('id')
            ->get();

        if (! $dryRun) {
            DB::transaction(function () use ($tenantId, $companyEntityId, $migrated): void {
                foreach ($migrated as $entry) {
                    $status = $this->targetIntact($tenantId, $companyEntityId, $entry)
                        ? MigrationLedgerStatus::Reconciled
                        : MigrationLedgerStatus::Drifted;
                    $this->applyMark($entry, $status);
                }
            });
        }

        return $this->statusCounts($tenantId, $companyEntityId);
    }

    /**
     * @return array{migrated: int, rejected: int, reconciled: int, drifted: int, by_source: array<string, array{migrated: int, rejected: int, reconciled: int, drifted: int}>}
     */
    public function statusCounts(int $tenantId, int $companyEntityId): array
    {
        $totals = ['migrated' => 0, 'rejected' => 0, 'reconciled' => 0, 'drifted' => 0];
        $bySource = [];

        foreach (TrainingMigrationLedgerEntry::query()->forCompany($tenantId, $companyEntityId)->get() as $entry) {
            $key = $entry->source_key;
            $bySource[$key] ??= ['migrated' => 0, 'rejected' => 0, 'reconciled' => 0, 'drifted' => 0];
            $status = $entry->status->value;
            $totals[$status]++;
            $bySource[$key][$status]++;
        }

        ksort($bySource);

        return $totals + ['by_source' => $bySource];
    }

    /**
     * @param  array<string, mixed>|null  $payloadExcerpt
     */
    private function insert(
        int $companyEntityId,
        string $sourceKey,
        string $sourceSha256,
        int $sourceRow,
        string $targetTable,
        ?int $targetId,
        MigrationLedgerStatus $status,
        ?string $reason,
        ?array $payloadExcerpt,
        int $recordedBy,
    ): TrainingMigrationLedgerEntry {
        $tenantId = $this->tenants->requireTenantId();
        $this->requireCompany($tenantId, $companyEntityId);

        if (preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) !== 1) {
            throw new InvalidMigrationLedgerException('source_sha256 must be a 64-character lowercase hex digest.');
        }

        // Nested transaction → SAVEPOINT on Postgres. A unique hit aborts only
        // the savepoint; the caller's outer transaction (tests, importers) stays
        // usable. Without it, catching UniqueConstraintViolationException still
        // leaves 25P02 "current transaction is aborted" on the next query.
        try {
            return DB::transaction(function () use (
                $tenantId,
                $companyEntityId,
                $sourceKey,
                $sourceSha256,
                $sourceRow,
                $targetTable,
                $targetId,
                $status,
                $reason,
                $payloadExcerpt,
                $recordedBy,
            ): TrainingMigrationLedgerEntry {
                return TrainingMigrationLedgerEntry::query()->create([
                    'tenant_id' => $tenantId,
                    'company_entity_id' => $companyEntityId,
                    'source_key' => $sourceKey,
                    'source_sha256' => $sourceSha256,
                    'source_row' => $sourceRow,
                    'target_table' => $targetTable,
                    'target_id' => $targetId,
                    'status' => $status,
                    'reason' => $reason,
                    'payload_excerpt' => $payloadExcerpt,
                    'recorded_by' => $recordedBy,
                    'recorded_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidMigrationLedgerException(
                'A migration ledger row for this source, digest and row already exists.',
            );
        }
    }

    private function mark(int $companyEntityId, int $ledgerId, MigrationLedgerStatus $status): TrainingMigrationLedgerEntry
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->requireCompany($tenantId, $companyEntityId);

        return DB::transaction(function () use ($tenantId, $companyEntityId, $ledgerId, $status): TrainingMigrationLedgerEntry {
            $entry = TrainingMigrationLedgerEntry::query()
                ->forCompany($tenantId, $companyEntityId)
                ->whereKey($ledgerId)
                ->lockForUpdate()
                ->first();

            if ($entry === null) {
                throw new InvalidMigrationLedgerException('Migration ledger row not found for this company.');
            }

            return $this->applyMark($entry, $status);
        });
    }

    private function applyMark(TrainingMigrationLedgerEntry $entry, MigrationLedgerStatus $status): TrainingMigrationLedgerEntry
    {
        $entry->update([
            'status' => $status,
            'reconciled_at' => now(),
        ]);

        return $entry->refresh();
    }

    private function targetIntact(int $tenantId, int $companyEntityId, TrainingMigrationLedgerEntry $entry): bool
    {
        if ($entry->target_id === null || $entry->target_table === '') {
            return false;
        }

        if ($entry->target_table === (new RequirementProfile)->getTable()) {
            $profile = RequirementProfile::query()
                ->forCompany($tenantId, $companyEntityId)
                ->whereKey($entry->target_id)
                ->first();

            return $profile !== null && $profile->status !== RequirementProfileStatus::Retired;
        }

        return DB::table($entry->target_table)
            ->where('tenant_id', $tenantId)
            ->where('company_entity_id', $companyEntityId)
            ->where('id', $entry->target_id)
            ->exists();
    }

    private function requireCompany(int $tenantId, int $companyEntityId): void
    {
        $exists = Company::query()
            ->where('id', $companyEntityId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $exists) {
            throw new InvalidMigrationLedgerException(
                'Company '.$companyEntityId.' does not belong to the current tenant.',
            );
        }
    }
}
