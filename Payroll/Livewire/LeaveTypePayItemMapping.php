<?php

namespace App\Modules\People\Payroll\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Leave\Models\LeaveType;
use App\Modules\People\Payroll\Models\PayrollLeaveTypePayItem;
use App\Modules\People\Payroll\Models\PayrollPayItem;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Assigns payroll pay-item codes to leave types.
 *
 * Mirror of `AttendanceAllowanceMapping` for the Leave producer.
 */
class LeaveTypePayItemMapping extends Component
{
    public int $editingLeaveTypeId = 0;

    public string $editingPayItemCode = '';

    public string $editingEffectiveFrom = '';

    public function mount(): void
    {
        $this->editingEffectiveFrom = now()->toDateString();
    }

    public function startEditing(int $leaveTypeId): void
    {
        $this->authorizeManage();

        $type = $this->loadType($leaveTypeId);
        $current = $this->currentMappingFor($type);

        $this->editingLeaveTypeId = $type->id;
        $this->editingPayItemCode = $current?->payroll_pay_item_code ?? '';
        $this->editingEffectiveFrom = $current?->effective_from?->toDateString() ?? now()->toDateString();
    }

    public function cancelEditing(): void
    {
        $this->editingLeaveTypeId = 0;
        $this->editingPayItemCode = '';
        $this->editingEffectiveFrom = now()->toDateString();
    }

    public function saveMapping(): void
    {
        $this->authorizeManage();

        $type = $this->loadType($this->editingLeaveTypeId);
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

        PayrollLeaveTypePayItem::query()->updateOrCreate(
            [
                'leave_type_id' => $type->id,
                'effective_from' => $validated['editingEffectiveFrom'],
            ],
            [
                'company_id' => $companyId,
                'payroll_pay_item_code' => $validated['editingPayItemCode'],
                'effective_to' => null,
            ],
        );

        $this->cancelEditing();
        session()->flash('success', __('Leave-type pay-item mapping saved.'));
    }

    public function deleteMapping(int $leaveTypeId, string $effectiveFrom): void
    {
        $this->authorizeManage();

        PayrollLeaveTypePayItem::query()
            ->where('leave_type_id', $leaveTypeId)
            ->whereDate('effective_from', Carbon::parse($effectiveFrom))
            ->delete();

        session()->flash('success', __('Leave-type pay-item mapping removed.'));
    }

    public function render(): View
    {
        $companyId = $this->companyId();

        $types = LeaveType::query()
            ->where(function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->where('interacts_with_payroll', true)
            ->orderBy('code')
            ->get();

        $mappings = PayrollLeaveTypePayItem::query()
            ->where(function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('leave_type_id');

        $payItems = PayrollPayItem::query()
            ->where('status', 'active')
            ->where(function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return view('livewire.people.payroll.leave-type-pay-item-mapping', [
            'types' => $types,
            'mappingsByType' => $mappings,
            'payItems' => $payItems,
            'canManage' => $this->canManage(),
        ]);
    }

    private function loadType(int $id): LeaveType
    {
        $companyId = $this->companyId();

        return LeaveType::query()
            ->where(function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->findOrFail($id);
    }

    private function currentMappingFor(LeaveType $type): ?PayrollLeaveTypePayItem
    {
        return PayrollLeaveTypePayItem::query()
            ->where('leave_type_id', $type->id)
            ->orderByDesc('effective_from')
            ->first();
    }

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
}
