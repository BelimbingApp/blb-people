<?php

namespace App\Domains\People\Training\Console\Commands;

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Training\Exceptions\InvalidMigrationImportException;
use App\Domains\People\Training\Services\MigrationImport;
use Illuminate\Support\Facades\Auth;

/**
 * Dry-run or apply a Training migration import for one signed source (0015-e).
 */
final class MigrationImportCommand extends TenantScopedCommand
{
    protected $signature = 'people:migration:import
                            {path : Absolute path to the starter-shape CSV}
                            {--company= : Company workforce entity id}
                            {--source= : Inventory source_key to import under}
                            {--dry-run : Classify rows and write nothing}
                            {--as= : Acting user id (defaults to the authenticated user)}';

    protected $description = 'Import a signed Training migration source with quarantine, or dry-run it';

    public function handle(TenantContext $tenants, MigrationImport $imports): int
    {
        $company = $this->option('company');
        $source = $this->option('source');
        $path = (string) $this->argument('path');

        if ($company === null || $company === '' || preg_match('/^\d+$/D', (string) $company) !== 1) {
            $this->error('Pass --company=<workforce company entity id>.');

            return self::FAILURE;
        }
        if ($source === null || $source === '') {
            $this->error('Pass --source=<inventory source_key>.');

            return self::FAILURE;
        }

        $as = $this->option('as');
        $user = null;
        if ($as !== null && $as !== '') {
            if (preg_match('/^\d+$/D', (string) $as) !== 1) {
                $this->error('--as must be a user id.');

                return self::FAILURE;
            }
            $user = User::query()->find((int) $as);
        } else {
            $auth = Auth::user();
            $user = $auth instanceof User ? $auth : null;
        }
        if ($user === null) {
            $this->error('Pass --as=<user id> for the HR actor.');

            return self::FAILURE;
        }

        $tenants->requireTenantId();
        $dryRun = (bool) $this->option('dry-run');

        try {
            $report = $dryRun
                ? $imports->dryRun($user, (int) $company, (string) $source, $path)
                : $imports->import($user, (int) $company, (string) $source, $path);
        } catch (InvalidMigrationImportException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'source %s sha256=%s read=%d imported=%d skipped=%d rejected=%d',
            $report->sourceKey,
            $report->sourceSha256,
            $report->read,
            $report->importedCount(),
            $report->skippedCount(),
            $report->rejectedCount(),
        ));
        foreach ($report->rejected as $row) {
            $this->line(sprintf('  rejected row=%d reason=%s', $row['row'], $row['reason']));
        }
        if ($report->dryRun) {
            $this->line('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }
}
