<?php

namespace App\Modules\People\Claim\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Claim\Models\ClaimAssignment;
use App\Modules\People\Claim\Models\ClaimAssignmentLine;
use App\Modules\People\Claim\Models\ClaimCategory;
use App\Modules\People\Claim\Models\ClaimContext;
use App\Modules\People\Claim\Models\ClaimPolicy;
use App\Modules\People\Claim\Models\ClaimPolicyBand;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimType;
use App\Modules\People\Claim\Services\ApproveClaimRequestService;
use App\Modules\People\Claim\Services\RejectClaimRequestService;
use App\Modules\People\Claim\Services\SubmitClaimRequestService;
use App\Modules\People\Claim\Services\WithdrawClaimRequestService;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Throwable;

class Index extends Component
{
    public string $tab = 'requests';

    public string $search = '';

    public string $categoryCode = '';

    public string $categoryName = '';

    public string $typeCategoryId = '';

    public string $typeCode = '';

    public string $typeName = '';

    public string $typeDefaultUnit = ClaimType::UNIT_AMOUNT;

    public string $typeReceiptRequirement = ClaimType::RECEIPT_ALWAYS;

    public bool $typeProviderRequired = false;

    public bool $typePayrollEligible = true;

    public string $typePayrollPayItemCode = '';

    public string $typeDebitAccountCode = '';

    public string $typeCreditAccountCode = '';

    public string $typeApprovalRouteKey = '';

    public string $policyCode = '';

    public string $policyName = '';

    public string $policyItemMode = ClaimPolicy::MODE_SINGLE_VALUE;

    public bool $policyAutoCalculated = false;

    public string $policyRateType = '';

    public string $policyApprovalProfileKey = '';

    public bool $policyEncumberPending = true;

    public string $policyEffectiveFrom = '2026-01-01';

    public string $bandPolicyId = '';

    public string $bandThreshold = '';

    public string $bandRate = '0';

    public string $bandPerDayUnitLimit = '';

    public string $bandPerClaimLimit = '';

    public string $bandPerMonthLimit = '';

    public string $bandPerYearLimit = '';

    public string $assignmentCode = '';

    public string $assignmentName = '';

    public string $assignmentEffectiveFrom = '2026-01-01';

    public string $lineAssignmentId = '';

    public string $lineClaimTypeId = '';

    public string $lineClaimPolicyId = '';

    public string $lineCombineTag = '';

    public bool $lineUsesCombinedCap = false;

    public bool $lineHiddenFromApplication = false;

    public string $contextCode = '';

    public string $contextLabel = '';

    public string $contextMaxClaimLimit = '';

    public string $applyAssignmentId = '';

    public string $applyAssignmentLineId = '';

    public string $applyContextId = '';

    public string $applyIncurredOn = '';

    public string $applyDescription = '';

    public string $applyRequestedAmount = '';

    public string $applyProviderName = '';

    public string $applyReceiptNumber = '';

    public string $applyAttachmentCount = '0';

    public string $approvalReason = '';

    public function mount(): void
    {
        $this->applyIncurredOn = now()->toDateString();
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['requests', 'categories', 'types', 'policies', 'assignments', 'contexts'], true)) {
            return;
        }

        $this->tab = $tab;
    }

    public function updatedSearch(): void
    {
        $this->search = trim($this->search);
    }

    public function submitClaim(): void
    {
        $employeeId = $this->currentEmployeeId();
        if ($employeeId === null) {
            session()->flash('error', __('Your user account is not linked to an employee record.'));

            return;
        }

        $companyId = $this->companyId();
        $validated = $this->validate([
            'applyAssignmentId' => ['required', 'integer', Rule::exists(ClaimAssignment::class, 'id')->where('company_id', $companyId)],
            'applyAssignmentLineId' => ['required', 'integer', Rule::exists(ClaimAssignmentLine::class, 'id')],
            'applyContextId' => ['nullable', 'integer', Rule::exists(ClaimContext::class, 'id')->where('company_id', $companyId)],
            'applyIncurredOn' => ['required', 'date'],
            'applyDescription' => ['nullable', 'string', 'max:255'],
            'applyRequestedAmount' => ['required', 'numeric', 'min:0.01'],
            'applyProviderName' => ['nullable', 'string', 'max:255'],
            'applyReceiptNumber' => ['nullable', 'string', 'max:100'],
            'applyAttachmentCount' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->findOrFail($employeeId);
            $assignment = ClaimAssignment::query()
                ->where('company_id', $companyId)
                ->findOrFail((int) $validated['applyAssignmentId']);
            $assignmentLine = ClaimAssignmentLine::query()
                ->with(['type', 'policy'])
                ->where('claim_assignment_id', $assignment->getKey())
                ->findOrFail((int) $validated['applyAssignmentLineId']);

            app(SubmitClaimRequestService::class)->submit(
                employee: $employee,
                assignment: $assignment,
                assignmentLine: $assignmentLine,
                incurredOn: new DateTimeImmutable($validated['applyIncurredOn']),
                requestedAmount: (float) $validated['applyRequestedAmount'],
                options: [
                    'claim_context_id' => $this->blankToNull($validated['applyContextId'] ?? null),
                    'description' => $this->blankToNull($validated['applyDescription'] ?? null),
                    'provider_name' => $this->blankToNull($validated['applyProviderName'] ?? null),
                    'receipt_number' => $this->blankToNull($validated['applyReceiptNumber'] ?? null),
                    'attachment_count' => (int) $validated['applyAttachmentCount'],
                    'submitted_by_user_id' => Auth::id(),
                ],
            );

            $this->reset('applyAssignmentLineId', 'applyDescription', 'applyRequestedAmount', 'applyProviderName', 'applyReceiptNumber');
            $this->applyAttachmentCount = '0';
            session()->flash('success', __('Claim request submitted for approval.'));
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function approveRequest(int $requestId): void
    {
        $this->authorizeApprove();

        try {
            $request = ClaimRequest::query()
                ->where('company_id', $this->companyId())
                ->findOrFail($requestId);

            app(ApproveClaimRequestService::class)->approve($request, Auth::id(), $this->approvalReason ?: null);
            $this->approvalReason = '';
            session()->flash('success', __('Claim request approved.'));
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function rejectRequest(int $requestId): void
    {
        $this->authorizeApprove();

        try {
            $request = ClaimRequest::query()
                ->where('company_id', $this->companyId())
                ->findOrFail($requestId);

            app(RejectClaimRequestService::class)->reject($request, Auth::id(), $this->approvalReason ?: null);
            $this->approvalReason = '';
            session()->flash('success', __('Claim request rejected.'));
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function withdrawOwnRequest(int $requestId): void
    {
        $employeeId = $this->currentEmployeeId();
        if ($employeeId === null) {
            return;
        }

        try {
            $request = ClaimRequest::query()
                ->where('company_id', $this->companyId())
                ->where('employee_id', $employeeId)
                ->findOrFail($requestId);

            app(WithdrawClaimRequestService::class)->withdraw($request, Auth::id(), 'Withdrawn by employee');
            session()->flash('success', __('Claim request withdrawn.'));
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

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
            'typePayrollPayItemCode' => ['nullable', 'string', 'max:100'],
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
                'payroll_pay_item_code' => $this->blankToNull($validated['typePayrollPayItemCode'] ?? null),
                'debit_account_code' => $this->blankToNull($validated['typeDebitAccountCode'] ?? null),
                'credit_account_code' => $this->blankToNull($validated['typeCreditAccountCode'] ?? null),
                'approval_route_key' => $this->blankToNull($validated['typeApprovalRouteKey'] ?? null),
                'status' => ClaimType::STATUS_ACTIVE,
                'metadata' => ['source' => 'claim-workbench'],
            ],
        );

        $this->reset('typeCategoryId', 'typeCode', 'typeName', 'typePayrollPayItemCode', 'typeDebitAccountCode', 'typeCreditAccountCode', 'typeApprovalRouteKey');
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

    public function render(): View
    {
        $companyId = $this->companyId();
        $search = trim($this->search);
        $actor = Actor::forUser(Auth::user());
        $authz = app(AuthorizationService::class);
        $canManage = $authz
            ->can($actor, 'people.claim.manage')
            ->allowed;
        $canApprove = $authz
            ->can($actor, 'people.claim.approve')
            ->allowed;
        $currentEmployeeId = $this->currentEmployeeId();

        $tabs = [
            ['id' => 'requests', 'label' => __('Requests'), 'icon' => 'heroicon-o-inbox-stack'],
            ['id' => 'categories', 'label' => __('Categories'), 'icon' => 'heroicon-o-folder'],
            ['id' => 'types', 'label' => __('Claim Types'), 'icon' => 'heroicon-o-tag'],
            ['id' => 'policies', 'label' => __('Policies'), 'icon' => 'heroicon-o-document-text'],
            ['id' => 'assignments', 'label' => __('Assignments'), 'icon' => 'heroicon-o-user-group'],
            ['id' => 'contexts', 'label' => __('Contexts'), 'icon' => 'heroicon-o-building-office-2'],
        ];

        return view('livewire.people.claim.index', [
            'tabs' => $tabs,
            'canManage' => $canManage,
            'canApprove' => $canApprove,
            'currentEmployeeId' => $currentEmployeeId,
            'categories' => ClaimCategory::query()
                ->where('company_id', $companyId)
                ->when($search !== '' && $this->tab === 'categories', fn ($query) => $query->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
                ->orderBy('code')
                ->get(),
            'types' => ClaimType::query()
                ->where('company_id', $companyId)
                ->with('category')
                ->when($search !== '' && $this->tab === 'types', fn ($query) => $query->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get(),
            'policies' => ClaimPolicy::query()
                ->where('company_id', $companyId)
                ->with('bands')
                ->when($search !== '' && $this->tab === 'policies', fn ($query) => $query->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
                ->orderBy('code')
                ->get(),
            'assignments' => ClaimAssignment::query()
                ->where('company_id', $companyId)
                ->with(['lines.type', 'lines.policy'])
                ->orderBy('code')
                ->get(),
            'myAssignments' => ClaimAssignment::query()
                ->where('company_id', $companyId)
                ->where('status', ClaimAssignment::STATUS_ACTIVE)
                ->with(['lines' => fn ($query) => $query
                    ->where('status', ClaimAssignmentLine::STATUS_ACTIVE)
                    ->where('hidden_from_application', false)
                    ->with(['type', 'policy'])])
                ->orderBy('code')
                ->get(),
            'availableAssignmentLines' => $this->applyAssignmentId !== '' && ctype_digit($this->applyAssignmentId)
                ? ClaimAssignmentLine::query()
                    ->where('claim_assignment_id', (int) $this->applyAssignmentId)
                    ->where('status', ClaimAssignmentLine::STATUS_ACTIVE)
                    ->where('hidden_from_application', false)
                    ->with(['type', 'policy'])
                    ->orderBy('sort_order')
                    ->get()
                : collect(),
            'contexts' => ClaimContext::query()
                ->where('company_id', $companyId)
                ->orderBy('code')
                ->get(),
            'recentRequests' => ClaimRequest::query()
                ->where('company_id', $companyId)
                ->with(['employee', 'lines.type'])
                ->latest('id')
                ->limit(20)
                ->get(),
        ]);
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            ClaimRequest::STATUS_SUBMITTED, ClaimRequest::STATUS_RESUBMITTED, ClaimRequest::STATUS_NEEDS_MORE_INFO => 'warning',
            ClaimRequest::STATUS_APPROVED, ClaimRequest::STATUS_QUEUED_FOR_PAYROLL, ClaimRequest::STATUS_REIMBURSED, ClaimRequest::STATUS_SETTLED => 'success',
            ClaimRequest::STATUS_REJECTED, ClaimRequest::STATUS_CANCELLED, ClaimRequest::STATUS_WITHDRAWN => 'danger',
            default => 'info',
        };
    }

    private function companyId(): int
    {
        return auth()->user()?->company_id ?? Company::LICENSEE_ID;
    }

    private function currentEmployeeId(): ?int
    {
        $id = auth()->user()?->employee_id;

        return $id === null ? null : (int) $id;
    }

    private function authorizeManage(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'people.claim.manage',
        );
    }

    private function authorizeApprove(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'people.claim.approve',
        );
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
