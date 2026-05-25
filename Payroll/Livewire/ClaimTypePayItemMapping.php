<?php

namespace App\Modules\People\Payroll\Livewire;

use App\Modules\People\Claim\Models\ClaimType;
use App\Modules\People\Payroll\Livewire\Concerns\ManagesPayrollMappingAuthorization;
use App\Modules\People\Payroll\Models\PayrollClaimTypePayItem;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Assigns payroll pay-item codes to claim types.
 *
 * Mirror of LeaveTypePayItemMapping and AttendanceAllowanceMapping.
 */
class ClaimTypePayItemMapping extends Component
{
    use ManagesPayrollMappingAuthorization;

    public int $editingClaimTypeId = 0;

    public string $editingPayItemCode = '';

    public string $editingEffectiveFrom = '';

    public function mount(): void
    {
        $this->editingEffectiveFrom = now()->toDateString();
    }

    public function startEditing(int $claimTypeId): void
    {
        $this->authorizeManage();

        $type = $this->loadType($claimTypeId);
        $current = $this->currentMappingFor($type);

        $this->editingClaimTypeId = $type->id;
        $this->editingPayItemCode = $current?->payroll_pay_item_code ?? '';
        $this->editingEffectiveFrom = $current?->effective_from?->toDateString() ?? now()->toDateString();
    }

    public function cancelEditing(): void
    {
        $this->editingClaimTypeId = 0;
        $this->editingPayItemCode = '';
        $this->editingEffectiveFrom = now()->toDateString();
    }

    public function saveMapping(): void
    {
        $this->authorizeManage();

        $type = $this->loadType($this->editingClaimTypeId);
        $companyId = $type->company_id;

        $validated = $this->validate([
            'editingPayItemCode' => [
                'required',
                'string',
                'max:80',
                Rule::exists('people_payroll_pay_items', 'code')
                    ->where(function ($query) use ($companyId): void {
                        $query->where('status', 'active')
                            ->where(function ($scope) use ($companyId): void {
                                $scope->where('company_id', $companyId)
                                    ->orWhereNull('company_id');
                            });
                    }),
            ],
            'editingEffectiveFrom' => ['required', 'date'],
        ]);

        PayrollClaimTypePayItem::query()->updateOrCreate(
            [
                'claim_type_id' => $type->id,
                'effective_from' => $validated['editingEffectiveFrom'],
            ],
            [
                'company_id' => $companyId,
                'payroll_pay_item_code' => $validated['editingPayItemCode'],
                'effective_to' => null,
            ],
        );

        $this->cancelEditing();
        session()->flash('success', __('Claim-type pay-item mapping saved.'));
    }

    public function deleteMapping(int $claimTypeId, string $effectiveFrom): void
    {
        $this->authorizeManage();

        PayrollClaimTypePayItem::query()
            ->where('claim_type_id', $claimTypeId)
            ->whereDate('effective_from', Carbon::parse($effectiveFrom))
            ->delete();

        session()->flash('success', __('Claim-type pay-item mapping removed.'));
    }

    public function render(): View
    {
        $companyId = $this->companyId();

        $types = ClaimType::query()
            ->where('company_id', $companyId)
            ->where('payroll_eligible', true)
            ->orderBy('code')
            ->get();

        $mappings = PayrollClaimTypePayItem::query()
            ->where('company_id', $companyId)
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('claim_type_id');

        $payItems = $this->activePayItemsForCompany($companyId);

        return view('people-payroll::livewire.people.payroll.claim-type-pay-item-mapping', [
            'types' => $types,
            'mappingsByType' => $mappings,
            'payItems' => $payItems,
            'canManage' => $this->canManage(),
        ]);
    }

    private function loadType(int $id): ClaimType
    {
        return ClaimType::query()->where('company_id', $this->companyId())->findOrFail($id);
    }

    private function currentMappingFor(ClaimType $type): ?PayrollClaimTypePayItem
    {
        return PayrollClaimTypePayItem::query()
            ->where('claim_type_id', $type->id)
            ->orderByDesc('effective_from')
            ->first();
    }
}
