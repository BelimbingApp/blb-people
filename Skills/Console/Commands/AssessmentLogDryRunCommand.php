<?php

namespace App\Domains\People\Skills\Console\Commands;

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Skills\Console\Concerns\AuthorizesAssessmentLogImport;
use App\Domains\People\Skills\Exceptions\InvalidAssessmentException;
use App\Domains\People\Skills\Import\UnreadableSkillWorkbook;
use App\Domains\People\Skills\Services\AssessmentLogDryRun;
use App\Domains\People\Skills\Services\SkillAudience;

/**
 * Report what importing 04 Assessment Log would do, writing nothing.
 *
 * Authorized like the catalogue import page: the acting user (--as) needs the
 * import capability with the HR audience for the named company, and that is
 * checked before the file is opened.
 */
final class AssessmentLogDryRunCommand extends TenantScopedCommand
{
    use AuthorizesAssessmentLogImport;

    protected $signature = 'people:skills-assessment-log-dry-run
                            {workbook : Path to the local XLSX workbook}
                            {--company= : Company workforce entity the log belongs to}
                            {--as= : Platform user id the run is authorized as}';

    protected $description = 'Check the 04 Assessment Log sheet against the company before importing it; writes nothing';

    public function handle(TenantContext $tenants, SkillAudience $audience, AssessmentLogDryRun $dryRun): int
    {
        $tenantId = $tenants->requireTenantId();
        $actor = $this->importActor($audience, $tenantId, 'A dry run');
        if ($actor === null) {
            return self::FAILURE;
        }
        [, $companyEntityId] = $actor;

        try {
            $result = $dryRun->run($tenantId, $companyEntityId, (string) $this->argument('workbook'));
        } catch (UnreadableSkillWorkbook|InvalidAssessmentException $exception) {
            $this->error($exception->getMessage());
            $this->line('Database writes: 0');

            return self::FAILURE;
        }

        $this->line('Workbook SHA-256: '.$result->sha256);

        foreach ($result->defects as $defect) {
            $this->line(sprintf(
                '[defect] %s at %s!%s | provenance sha256=%s row=%d',
                $defect->kind,
                $defect->source->sheet,
                $defect->cell,
                $defect->source->sha256,
                $defect->source->row,
            ));
        }

        $this->line(sprintf('Would create: %d', $result->wouldCreate));
        $this->line(sprintf('Would skip: %d', $result->wouldSkip));
        $this->line(sprintf('Defects: %d', count($result->defects)));
        $this->line('Database writes: 0');

        return $result->defects === [] ? self::SUCCESS : self::FAILURE;
    }
}
