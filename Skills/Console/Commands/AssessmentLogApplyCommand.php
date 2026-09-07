<?php

namespace App\Domains\People\Skills\Console\Commands;

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Skills\Console\Concerns\AuthorizesAssessmentLogImport;
use App\Domains\People\Skills\Exceptions\InvalidAssessmentException;
use App\Domains\People\Skills\Import\UnreadableSkillWorkbook;
use App\Domains\People\Skills\Services\AssessmentLogImporter;
use App\Domains\People\Skills\Services\SkillAudience;

/**
 * Apply 04 Assessment Log: after a clean dry run, create one finalized
 * assessment per would-create row in a single transaction (blb-people#390).
 *
 * Authorized like the dry run: the acting user (--as) needs the import
 * capability with the HR audience for the named company, checked before the
 * file is opened. Any defect means nothing was written and a non-zero exit.
 */
final class AssessmentLogApplyCommand extends TenantScopedCommand
{
    use AuthorizesAssessmentLogImport;

    protected $signature = 'people:skills-assessment-log-apply
                            {workbook : Path to the local XLSX workbook}
                            {--company= : Company workforce entity the log belongs to}
                            {--as= : Platform user id the import is authorized as}';

    protected $description = 'Import the 04 Assessment Log sheet as finalized assessments after a clean dry run; one transaction, idempotent per file and row';

    public function handle(TenantContext $tenants, SkillAudience $audience, AssessmentLogImporter $importer): int
    {
        $tenantId = $tenants->requireTenantId();
        $actor = $this->importActor($audience, $tenantId, 'An import');
        if ($actor === null) {
            return self::FAILURE;
        }
        [$user, $companyEntityId] = $actor;

        try {
            $result = $importer->apply($user, $companyEntityId, (string) $this->argument('workbook'));
        } catch (UnreadableSkillWorkbook|InvalidAssessmentException $exception) {
            $this->error($exception->getMessage());
            $this->line('Created: 0');

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

        $this->line(sprintf('Created: %d', count($result->created)));
        $this->line(sprintf('Skipped: %d', $result->skipped));
        $this->line(sprintf('Defects: %d', count($result->defects)));
        if ($result->created !== []) {
            $this->line('Created ids: '.implode(',', $result->created));
        }

        return $result->defects === [] ? self::SUCCESS : self::FAILURE;
    }
}
