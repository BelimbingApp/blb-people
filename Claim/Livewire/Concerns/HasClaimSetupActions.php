<?php

namespace App\Modules\People\Claim\Livewire\Concerns;

use App\Modules\People\Claim\Models\ClaimAssignment;
use App\Modules\People\Claim\Models\ClaimAssignmentLine;
use App\Modules\People\Claim\Models\ClaimCategory;
use App\Modules\People\Claim\Models\ClaimContext;
use App\Modules\People\Claim\Models\ClaimPolicy;
use App\Modules\People\Claim\Models\ClaimPolicyBand;
use App\Modules\People\Claim\Models\ClaimType;
use Illuminate\Validation\Rule;

trait HasClaimSetupActions
{
    public function createCategory(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'categoryCode' => ['required', 'string', 'max:64'],
            'categoryName' => ['required', 'string', 'max:255'],
        ]);

        ClaimCategory::query()->updateOrCreate(
            [
                'company_id' => $this->companyId(),
                'code' => $this->normalizeCode($validated['categoryCode']),
            ],
            [
                'name' => $validated['categoryName'],
                'status' => ClaimCategory::STATUS_ACTIVE,
                'metadata' => ['source' => 'claim-workbench'],
            ],
        );

        $this->reset('categoryCode', 'categoryName');
        session()->flash('success', __('Claim category saved.'));
    }

    public function createClaimType(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'typeCategoryId' => ['nullable', 'integer', Rule::exists(ClaimCategory::class, 'id')->where('company_id', $this->companyId())],
            'typeCode' => ['required', 'string', 'max:64'],
            'typeName' => ['required', 'string', 'max:255'],
            'typeDefaultUnit' => ['required', Rule::in([ClaimType::UNIT_AMOUNT, ClaimType::UNIT_DISTANCE, ClaimType::UNIT_QUANTITY, ClaimType::UNIT_DAYS])],
            'typeReceiptRequirement' => ['required', Rule::in([ClaimType::RECEIPT_NEVER, ClaimType::RECEIPT_ABOVE_AMOUNT, ClaimType::RECEIPT_ALWAYS])],
            'typeDebitAccountCode' => ['nullable', 'string', 'max:100'],
            'typeCreditAccountCode' => ['nullable', 'string', 'max:100'],
            'typeApprovalRouteKey' => ['nullable', 'string', 'max:100'],
        ]);

        ClaimType::query()->updateOrCreate(
            [
                'company_id' => $this->companyId(),
                'code' => $this->normalizeCode($validated['typeCode']),
            ],
            [
                'claim_category_id' => $this->blankToNull($validated['typeCategoryId'] ?? null),
                'name' => $validated['typeName'],
                'default_unit' => $validated['typeDefaultUnit'],
                'calculation_mode' => $validated['typeDefaultUnit'] === ClaimType::UNIT_AMOUNT ? 'manual_amount' : 'quantity_rate',
                'receipt_requirement' => $validated['typeReceiptRequirement'],
                'provider_required' => $this->typeProviderRequired,
                'payroll_eligible' => $this->typePayrollEligible,
                'debit_account_code' => $this->blankToNull($validated['typeDebitAccountCode'] ?? null),
                'credit_account_code' => $this->blankToNull($validated['typeCreditAccountCode'] ?? null),
                'approval_route_key' => $this->blankToNull($validated['typeApprovalRouteKey'] ?? null),
                'status' => ClaimType::STATUS_ACTIVE,
                'metadata' => ['source' => 'claim-workbench'],
            ],
        );

        $this->reset('typeCategoryId', 'typeCode', 'typeName', 'typeDebitAccountCode', 'typeCreditAccountCode', 'typeApprovalRouteKey');
        $this->typeDefaultUnit = ClaimType::UNIT_AMOUNT;
        $this->typeReceiptRequirement = ClaimType::RECEIPT_ALWAYS;
        $this->typeProviderRequired = false;
        $this->typePayrollEligible = true;
        session()->flash('success', __('Claim type saved.'));
    }

    public function createPolicy(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'policyCode' => ['required', 'string', 'max:64'],
            'policyName' => ['required', 'string', 'max:255'],
            'policyItemMode' => ['required', Rule::in([ClaimPolicy::MODE_SINGLE_VALUE, ClaimPolicy::MODE_RANGE, ClaimPolicy::MODE_SERVICE_YEAR])],
            'policyRateType' => ['nullable', 'string', 'max:100'],
            'policyApprovalProfileKey' => ['nullable', 'string', 'max:100'],
            'policyEffectiveFrom' => ['required', 'date'],
        ]);

        ClaimPolicy::query()->updateOrCreate(
            [
                'company_id' => $this->companyId(),
                'code' => $this->normalizeCode($validated['policyCode']),
            ],
            [
                'name' => $validated['policyName'],
                'item_mode' => $validated['policyItemMode'],
                'auto_calculated' => $this->policyAutoCalculated,
                'rate_type' => $this->blankToNull($validated['policyRateType'] ?? null),
                'approval_profile_key' => $this->blankToNull($validated['policyApprovalProfileKey'] ?? null),
                'encumber_pending' => $this->policyEncumberPending,
                'effective_from' => $validated['policyEffectiveFrom'],
                'version' => 1,
                'status' => ClaimPolicy::STATUS_ACTIVE,
                'metadata' => ['source' => 'claim-workbench'],
            ],
        );

        $this->reset('policyCode', 'policyName', 'policyRateType', 'policyApprovalProfileKey');
        session()->flash('success', __('Claim policy saved.'));
    }

    public function addPolicyBand(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'bandPolicyId' => ['required', 'integer', Rule::exists(ClaimPolicy::class, 'id')->where('company_id', $this->companyId())],
            'bandThreshold' => ['nullable', 'numeric'],
            'bandRate' => ['required', 'numeric'],
            'bandPerDayUnitLimit' => ['nullable', 'numeric'],
            'bandPerClaimLimit' => ['nullable', 'numeric'],
            'bandPerMonthLimit' => ['nullable', 'numeric'],
            'bandPerYearLimit' => ['nullable', 'numeric'],
        ]);

        $nextOrder = (int) ClaimPolicyBand::query()
            ->where('claim_policy_id', $validated['bandPolicyId'])
            ->max('sort_order') + 10;

        ClaimPolicyBand::query()->create([
            'claim_policy_id' => (int) $validated['bandPolicyId'],
            'logical_operator' => '<=',
            'threshold_value' => $this->blankToNull($validated['bandThreshold'] ?? null),
            'rate' => $validated['bandRate'],
            'per_day_unit_limit' => $this->blankToNull($validated['bandPerDayUnitLimit'] ?? null),
            'per_claim_limit' => $this->blankToNull($validated['bandPerClaimLimit'] ?? null),
            'per_month_limit' => $this->blankToNull($validated['bandPerMonthLimit'] ?? null),
            'per_year_limit' => $this->blankToNull($validated['bandPerYearLimit'] ?? null),
            'sort_order' => $nextOrder,
            'metadata' => ['source' => 'claim-workbench'],
        ]);

        $this->reset('bandThreshold', 'bandPerDayUnitLimit', 'bandPerClaimLimit', 'bandPerMonthLimit', 'bandPerYearLimit');
        $this->bandRate = '0';
        session()->flash('success', __('Claim policy band added.'));
    }

    public function createAssignment(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'assignmentCode' => ['required', 'string', 'max:64'],
            'assignmentName' => ['required', 'string', 'max:255'],
            'assignmentEffectiveFrom' => ['required', 'date'],
        ]);

        ClaimAssignment::query()->updateOrCreate(
            [
                'company_id' => $this->companyId(),
                'code' => $this->normalizeCode($validated['assignmentCode']),
            ],
            [
                'name' => $validated['assignmentName'],
                'effective_from' => $validated['assignmentEffectiveFrom'],
                'status' => ClaimAssignment::STATUS_ACTIVE,
                'metadata' => ['source' => 'claim-workbench'],
            ],
        );

        $this->reset('assignmentCode', 'assignmentName');
        session()->flash('success', __('Claim assignment saved.'));
    }

    public function addAssignmentLine(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'lineAssignmentId' => ['required', 'integer', Rule::exists(ClaimAssignment::class, 'id')->where('company_id', $this->companyId())],
            'lineClaimTypeId' => ['required', 'integer', Rule::exists(ClaimType::class, 'id')->where('company_id', $this->companyId())],
            'lineClaimPolicyId' => ['required', 'integer', Rule::exists(ClaimPolicy::class, 'id')->where('company_id', $this->companyId())],
            'lineCombineTag' => ['nullable', 'string', 'max:100'],
        ]);

        $nextOrder = (int) ClaimAssignmentLine::query()
            ->where('claim_assignment_id', $validated['lineAssignmentId'])
            ->max('sort_order') + 10;

        ClaimAssignmentLine::query()->updateOrCreate(
            [
                'claim_assignment_id' => (int) $validated['lineAssignmentId'],
                'claim_type_id' => (int) $validated['lineClaimTypeId'],
            ],
            [
                'claim_policy_id' => (int) $validated['lineClaimPolicyId'],
                'combine_tag' => $this->blankToNull($validated['lineCombineTag'] ?? null),
                'uses_combined_cap' => $this->lineUsesCombinedCap,
                'hidden_from_application' => $this->lineHiddenFromApplication,
                'sort_order' => $nextOrder,
                'status' => ClaimAssignmentLine::STATUS_ACTIVE,
                'metadata' => ['source' => 'claim-workbench'],
            ],
        );

        $this->reset('lineClaimTypeId', 'lineClaimPolicyId', 'lineCombineTag');
        $this->lineUsesCombinedCap = false;
        $this->lineHiddenFromApplication = false;
        session()->flash('success', __('Claim assignment line saved.'));
    }

    public function createContext(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'contextCode' => ['required', 'string', 'max:64'],
            'contextLabel' => ['required', 'string', 'max:255'],
            'contextMaxClaimLimit' => ['nullable', 'numeric'],
        ]);

        ClaimContext::query()->updateOrCreate(
            [
                'company_id' => $this->companyId(),
                'code' => $this->normalizeCode($validated['contextCode']),
            ],
            [
                'label' => $validated['contextLabel'],
                'max_claim_limit' => $this->blankToNull($validated['contextMaxClaimLimit'] ?? null),
                'status' => ClaimContext::STATUS_ACTIVE,
                'metadata' => ['source' => 'claim-workbench'],
            ],
        );

        $this->reset('contextCode', 'contextLabel', 'contextMaxClaimLimit');
        session()->flash('success', __('Claim context saved.'));
    }

    private function normalizeCode(string $value): string
    {
        $code = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', trim($value)));

        return trim($code, '_');
    }

    private function blankToNull(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
