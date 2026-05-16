<?php

namespace App\Modules\People\Payroll\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Attendance\Models\AttendanceAllowanceRule;
use App\Modules\People\Payroll\Models\PayrollAttendanceRulePayItem;
use App\Modules\People\Payroll\Models\PayrollPayItem;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Assigns payroll pay-item codes to attendance allowance rules.
 *
 * The Attendance module owns the rules (what counts as a meal allowance,
 * a night shift differential, etc.) but the pay-item code that classifies
 * the resulting payslip line is a payroll concept. This screen lives in
 * the Payroll module so the mapping is editable on a deployment that has
 * Payroll installed; on a deployment without Payroll, attendance rules
 * still author normally and the mapping is simply absent.
 */
class AttendanceAllowanceMapping extends Component
{
    public int $editingRuleId = 0;

    public string $editingPayItemCode = '';

    public string $editingEffectiveFrom = '';

    public function mount(): void
    {
        $this->editingEffectiveFrom = now()->toDateString();
    }

    public function startEditing(int $ruleId): void
    {
        $this->authorizeManage();

        $rule = $this->loadRule($ruleId);
        $current = $this->currentMappingFor($rule);

        $this->editingRuleId = $rule->id;
        $this->editingPayItemCode = $current?->payroll_pay_item_code ?? '';
        $this->editingEffectiveFrom = $current?->effective_from?->toDateString() ?? now()->toDateString();
    }

    public function cancelEditing(): void
    {
        $this->editingRuleId = 0;
        $this->editingPayItemCode = '';
        $this->editingEffectiveFrom = now()->toDateString();
    }

    public function saveMapping(): void
    {
        $this->authorizeManage();

        $rule = $this->loadRule($this->editingRuleId);
        $companyId = $rule->company_id;

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

        PayrollAttendanceRulePayItem::query()->updateOrCreate(
            [
                'attendance_allowance_rule_id' => $rule->id,
                'effective_from' => $validated['editingEffectiveFrom'],
            ],
            [
                'company_id' => $companyId,
                'payroll_pay_item_code' => $validated['editingPayItemCode'],
                'effective_to' => null,
            ],
        );

        $this->cancelEditing();

        session()->flash('success', __('Pay-item mapping saved.'));
    }

    public function deleteMapping(int $ruleId, string $effectiveFrom): void
    {
        $this->authorizeManage();

        PayrollAttendanceRulePayItem::query()
            ->where('attendance_allowance_rule_id', $ruleId)
            ->whereDate('effective_from', Carbon::parse($effectiveFrom))
            ->delete();

        session()->flash('success', __('Pay-item mapping removed.'));
    }

    public function render(): View
    {
        $companyId = $this->companyId();

        $rules = AttendanceAllowanceRule::query()
            ->where('company_id', $companyId)
            ->orderBy('code')
            ->get();

        $mappings = PayrollAttendanceRulePayItem::query()
            ->where('company_id', $companyId)
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('attendance_allowance_rule_id');

        $payItems = PayrollPayItem::query()
            ->where('status', 'active')
            ->where(function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->orWhereNull('company_id');
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return view('livewire.people.payroll.attendance-allowance-mapping', [
            'rules' => $rules,
            'mappingsByRule' => $mappings,
            'payItems' => $payItems,
            'canManage' => $this->canManage(),
        ]);
    }

    private function loadRule(int $ruleId): AttendanceAllowanceRule
    {
        return AttendanceAllowanceRule::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($ruleId);
    }

    private function currentMappingFor(AttendanceAllowanceRule $rule): ?PayrollAttendanceRulePayItem
    {
        return PayrollAttendanceRulePayItem::query()
            ->where('attendance_allowance_rule_id', $rule->id)
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

        return app(AuthorizationService::class)->actorCan(
            Actor::fromUser($user),
            'people.payroll.manage',
        );
    }

    private function authorizeManage(): void
    {
        if (! $this->canManage()) {
            abort(403);
        }
    }
}
