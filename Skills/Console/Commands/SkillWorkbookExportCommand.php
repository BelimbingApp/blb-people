<?php

namespace App\Domains\People\Skills\Console\Commands;

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Skills\Exceptions\InvalidSkillCatalogException;
use App\Domains\People\Skills\Exceptions\ProficiencyScaleStateException;
use App\Domains\People\Skills\Export\SkillWorkbookWriter;

/**
 * Export the company skill catalogue as the signed-off workbook.
 *
 * Writes the reader's column contract from live data so the file round-trips
 * with zero defects; prints sheet and row counts plus the file SHA-256 so an
 * operator can reconcile the export against the UI and dashboard totals.
 */
final class SkillWorkbookExportCommand extends TenantScopedCommand
{
    protected $signature = 'people:skills-workbook-export
                            {output : Path to write the XLSX workbook to}
                            {--company= : Company workforce entity to export}';

    protected $description = 'Export the company skill catalogue as the signed-off workbook';

    public function handle(TenantContext $tenants, SkillWorkbookWriter $writer): int
    {
        $company = $this->option('company');

        if ($company === null || $company === '') {
            $this->error('An export is per company: pass --company=<workforce company entity id>.');

            return self::FAILURE;
        }

        try {
            $result = $writer->write($tenants->requireTenantId(), (int) $company, (string) $this->argument('output'));
        } catch (InvalidSkillCatalogException|ProficiencyScaleStateException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('02 Skill Catalogue: skills=%d', $result->skills));
        $this->line(sprintf('00 Guide: proficiency levels=%d', $result->levels));
        $this->line('Workbook SHA-256: '.$result->sha256);

        return self::SUCCESS;
    }
}
