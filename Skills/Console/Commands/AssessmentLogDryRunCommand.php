<?php

namespace App\Domains\People\Skills\Console\Commands;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Exceptions\InvalidAssessmentException;
use App\Domains\People\Skills\Import\UnreadableSkillWorkbook;
use App\Domains\People\Skills\Livewire\Catalog\Import;
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
    protected $signature = 'people:skills-assessment-log-dry-run
                            {workbook : Path to the local XLSX workbook}
                            {--company= : Company workforce entity the log belongs to}
                            {--as= : Platform user id the run is authorized as}';

    protected $description = 'Check the 04 Assessment Log sheet against the company before importing it; writes nothing';

    public function handle(TenantContext $tenants, SkillAudience $audience, AssessmentLogDryRun $dryRun): int
    {
        $tenantId = $tenants->requireTenantId();
        $company = $this->option('company');
        $as = $this->option('as');

        if (! is_scalar($company) || preg_match('/^\d+$/D', (string) $company) !== 1) {
            $this->error('A dry run is per company: pass --company=<workforce company entity id>.');

            return self::FAILURE;
        }

        if (! is_scalar($as) || preg_match('/^\d+$/D', (string) $as) !== 1) {
            $this->error('A dry run is authorized as a user: pass --as=<platform user id>.');

            return self::FAILURE;
        }

        $companyEntityId = (int) $company;
        $user = User::query()->find((int) $as);

        if ($user === null || (int) $user->tenant_id !== $tenantId || ! $this->authorized($audience, $user, $companyEntityId)) {
            $this->error('User '.(string) $as.' is not authorized for '.Import::CAPABILITY.' in company '.(string) $company.'; the workbook was not opened.');

            return self::FAILURE;
        }

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

    /** The import page's funnel: capability, HR audience, and this company. */
    private function authorized(SkillAudience $audience, User $user, int $companyEntityId): bool
    {
        try {
            $audiences = $audience->authorizeAudience($user, Import::CAPABILITY);

            if (! in_array(SkillAudience::HR, $audiences, true)
                || ! array_key_exists($companyEntityId, $audience->allowedCompanies($user, Import::CAPABILITY))) {
                return false;
            }

            $audience->assertHr($user, $companyEntityId);
        } catch (AuthorizationDeniedException) {
            return false;
        }

        return true;
    }
}
