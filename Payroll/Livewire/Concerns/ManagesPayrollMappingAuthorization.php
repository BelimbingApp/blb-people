<?php

namespace App\Modules\People\Payroll\Livewire\Concerns;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Payroll\Models\PayrollPayItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

trait ManagesPayrollMappingAuthorization
{
    private function companyId(): int
    {
        $user = Auth::user();

        return (int) ($user?->company_id ?? Company::query()->value('id') ?? 0);
    }

    private function canManage(): bool
    {
        $user = Auth::user();
        if ($user === null) {
            return false;
        }

        return app(AuthorizationService::class)
            ->can(Actor::forUser($user), 'people.payroll.manage')
            ->allowed;
    }

    private function authorizeManage(): void
    {
        if (! $this->canManage()) {
            abort(403);
        }
    }

    /**
     * @return Collection<int, PayrollPayItem>
     */
    private function activePayItemsForCompany(int $companyId): Collection
    {
        return PayrollPayItem::query()
            ->where('status', 'active')
            ->where(function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->orWhereNull('company_id');
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }
}
