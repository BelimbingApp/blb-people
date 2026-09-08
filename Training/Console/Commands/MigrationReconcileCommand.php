<?php

namespace App\Domains\People\Training\Console\Commands;

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Training\Exceptions\InvalidMigrationLedgerException;
use App\Domains\People\Training\Services\MigrationLedger;

/**
 * Reconcile migrated Training ledger rows for one company (0015-d).
 *
 * For each migrated row, prove the target still exists (and is not retired),
 * then mark reconciled or drifted. --dry-run prints the same per-source
 * counts and writes nothing.
 */
final class MigrationReconcileCommand extends TenantScopedCommand
{
    protected $signature = 'people:migration:reconcile
                            {--company= : Company workforce entity to reconcile}
                            {--dry-run : Report counts and write nothing}';

    protected $description = 'Mark migrated training ledger rows reconciled or drifted against their targets';

    public function handle(TenantContext $tenants, MigrationLedger $ledger): int
    {
        $company = $this->option('company');

        if ($company === null || $company === '' || preg_match('/^\d+$/D', (string) $company) !== 1) {
            $this->error('A reconcile run is per company: pass --company=<workforce company entity id>.');

            return self::FAILURE;
        }

        $tenantId = $tenants->requireTenantId();
        $companyId = (int) $company;
        $dryRun = (bool) $this->option('dry-run');

        try {
            $counts = $ledger->reconcile($companyId, $dryRun);
        } catch (InvalidMigrationLedgerException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($counts['by_source'] as $sourceKey => $sourceCounts) {
            $this->line(sprintf(
                'source %s  migrated=%d rejected=%d reconciled=%d drifted=%d',
                $sourceKey,
                $sourceCounts['migrated'],
                $sourceCounts['rejected'],
                $sourceCounts['reconciled'],
                $sourceCounts['drifted'],
            ));
        }

        $this->line(sprintf(
            'total  migrated=%d rejected=%d reconciled=%d drifted=%d',
            $counts['migrated'],
            $counts['rejected'],
            $counts['reconciled'],
            $counts['drifted'],
        ));

        if ($dryRun) {
            $this->line('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }
}
