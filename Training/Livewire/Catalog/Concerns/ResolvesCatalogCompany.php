<?php

namespace App\Domains\People\Training\Livewire\Catalog\Concerns;

use App\Domains\People\Training\Services\TrainingAudience;
use Illuminate\Support\Facades\Auth;

/**
 * Shared company resolution for Training catalog list and form pages.
 * `companyEntityId` is client-writable, so every action re-checks audience.
 */
trait ResolvesCatalogCompany
{
    /** @var array<int, string>|null */
    private ?array $companies = null;

    /** @return array<int, string> */
    protected function allowedCompanies(TrainingAudience $audience): array
    {
        return $this->companies ??= $audience->allowedCompanies(Auth::user());
    }

    protected function resolveInitialCompany(TrainingAudience $audience, ?int $preferred = null): void
    {
        $companies = $this->allowedCompanies($audience);
        if ($preferred !== null && array_key_exists($preferred, $companies)) {
            $this->companyEntityId = $preferred;

            return;
        }

        $this->companyEntityId = count($companies) > 0 ? (int) array_key_first($companies) : null;
    }

    protected function managedCompany(TrainingAudience $audience): int
    {
        abort_if($this->companyEntityId === null, 404);
        $audience->authorizeManage(Auth::user(), $this->companyEntityId);

        return $this->companyEntityId;
    }

    protected function viewedCompany(TrainingAudience $audience): ?int
    {
        $companies = $this->allowedCompanies($audience);
        if ($this->companyEntityId === null || ! array_key_exists($this->companyEntityId, $companies)) {
            return null;
        }

        return $this->companyEntityId;
    }
}
