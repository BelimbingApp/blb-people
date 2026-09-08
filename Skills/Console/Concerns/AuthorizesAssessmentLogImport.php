<?php

namespace App\Domains\People\Skills\Console\Concerns;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Livewire\Catalog\Import;
use App\Domains\People\Skills\Services\SkillAudience;

/**
 * The import page's funnel for the assessment-log commands: the acting user
 * needs the import capability with the HR audience for the named company,
 * checked before any workbook is opened.
 */
trait AuthorizesAssessmentLogImport
{
    /** Resolves --company and --as; null (after printing the reason) when the run may not start. */
    private function importActor(SkillAudience $audience, int $tenantId, string $what): ?array
    {
        $company = $this->option('company');
        $as = $this->option('as');

        if (! is_scalar($company) || preg_match('/^\d+$/D', (string) $company) !== 1) {
            $this->error($what.' is per company: pass --company=<workforce company entity id>.');

            return null;
        }

        if (! is_scalar($as) || preg_match('/^\d+$/D', (string) $as) !== 1) {
            $this->error($what.' is authorized as a user: pass --as=<platform user id>.');

            return null;
        }

        $companyEntityId = (int) $company;
        $user = User::query()->find((int) $as);

        if ($user === null || (int) $user->tenant_id !== $tenantId || ! $this->authorized($audience, $user, $companyEntityId)) {
            $this->error('User '.(string) $as.' is not authorized for '.Import::CAPABILITY.' in company '.(string) $company.'; the workbook was not opened.');

            return null;
        }

        return [$user, $companyEntityId];
    }

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
