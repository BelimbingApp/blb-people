<?php

namespace App\Modules\People\Claim\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Claim\Livewire\Concerns\HasClaimSetupActions;
use App\Modules\People\Claim\Livewire\Concerns\HasPayrollOperationsStatus;
use App\Modules\People\Claim\Models\ClaimAssignment;
use App\Modules\People\Claim\Models\ClaimAssignmentLine;
use App\Modules\People\Claim\Models\ClaimCategory;
use App\Modules\People\Claim\Models\ClaimContext;
use App\Modules\People\Claim\Models\ClaimPolicy;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimType;
use App\Modules\People\Claim\Services\ApproveClaimRequestService;
use App\Modules\People\Claim\Services\CancelClaimRequestService;
use App\Modules\People\Claim\Services\ReimburseClaimRequestService;
use App\Modules\People\Claim\Services\RejectClaimRequestService;
use App\Modules\People\Claim\Services\RequestClaimMoreInfoService;
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
    use HasClaimSetupActions;
    use HasPayrollOperationsStatus;

    public string $surface = 'my';

    public string $tab = 'submit';

    public bool $showClaimModal = false;

    public string $search = '';

    public string $operationsStatus = '';

    public string $operationsRisk = '';

    public string $operationsPayroll = '';

    public string $categoryCode = '';

    public string $categoryName = '';

    public string $typeCategoryId = '';

    public string $typeCode = '';

    public string $typeName = '';

    public string $typeDefaultUnit = ClaimType::UNIT_AMOUNT;

    public string $typeReceiptRequirement = ClaimType::RECEIPT_ALWAYS;

    public bool $typeProviderRequired = false;

    public bool $typePayrollEligible = true;

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

    public ?int $selectedRequestId = null;

    public string $approvalReason = '';

    public function mount(?string $surface = null, ?string $section = null): void
    {
        $this->surface = in_array($surface, ['my', 'approvals', 'operations', 'settings'], true) ? $surface : 'my';

        if ($this->surface === 'settings') {
            $allowed = ['categories', 'types', 'policies', 'assignments', 'contexts'];
            $this->tab = in_array($section, $allowed, true) ? $section : 'categories';
        } else {
            $this->tab = match ($this->surface) {
                'approvals' => 'approvals',
                'operations' => 'operations',
                default => 'submit',
            };
        }

        $this->applyIncurredOn = now()->toDateString();
    }

    /** @return list<string> */
    private function tabsForSurface(): array
    {
        return match ($this->surface) {
            'approvals' => ['approvals'],
            'operations' => ['operations'],
            'settings' => [$this->tab],
            default => ['submit'],
        };
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, $this->tabsForSurface(), true)) {
            return;
        }

        $this->tab = $tab;
        $this->selectedRequestId = null;
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
            $this->showClaimModal = false;
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

    public function requestMoreInfo(int $requestId): void
    {
        $this->authorizeApprove();

        try {
            $request = ClaimRequest::query()
                ->where('company_id', $this->companyId())
                ->findOrFail($requestId);

            app(RequestClaimMoreInfoService::class)->requestMoreInfo($request, Auth::id(), $this->approvalReason ?: null);
            $this->approvalReason = '';
            session()->flash('success', __('Claim request sent back for more information.'));
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function markReimbursed(int $requestId): void
    {
        $this->authorizeManage();

        try {
            $request = ClaimRequest::query()
                ->where('company_id', $this->companyId())
                ->findOrFail($requestId);

            app(ReimburseClaimRequestService::class)->reimburse($request, Auth::id(), $this->approvalReason ?: 'Marked reimbursed by operations');
            $this->approvalReason = '';
            session()->flash('success', __('Claim request marked as reimbursed.'));
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelRequest(int $requestId): void
    {
        $this->authorizeManage();

        try {
            $request = ClaimRequest::query()
                ->where('company_id', $this->companyId())
                ->findOrFail($requestId);

            app(CancelClaimRequestService::class)->cancel($request, Auth::id(), $this->approvalReason ?: 'Cancelled by operations');
            $this->approvalReason = '';
            session()->flash('success', __('Claim request cancelled.'));
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

    public function selectRequest(int $requestId): void
    {
        $this->selectedRequestId = $requestId;
        $this->tab = 'approvals';
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

        $settingsSectionTitle = [
            'categories' => __('Claim Categories'),
            'types' => __('Claim Types'),
            'policies' => __('Claim Policies'),
            'assignments' => __('Claim Assignments'),
            'contexts' => __('Claim Contexts'),
        ];

        $surfaceTitle = match ($this->surface) {
            'approvals' => __('Claim Approvals'),
            'operations' => __('Claim Operations'),
            'settings' => $settingsSectionTitle[$this->tab] ?? __('Claim Settings'),
            default => __('My Claims'),
        };

        $settingsSectionSubtitle = [
            'categories' => __('Group claim types into SBG/iPayroll-compatible categories.'),
            'types' => __('Configure claim items, receipt/provider rules, payroll codes, and accounting mappings.'),
            'policies' => __('Configure effective-dated caps, thresholds, and approval profile selectors.'),
            'assignments' => __('Bind claim types and policies into employee-visible claim groups.'),
            'contexts' => __('Maintain shallow claim context/client references and max claim limits.'),
        ];

        $surfaceSubtitle = match ($this->surface) {
            'approvals' => __('Review submitted claims, inspect line evidence and risks, then approve or reject.'),
            'operations' => __('Search claim requests, monitor duplicate risks, and track payroll handoff readiness.'),
            'settings' => $settingsSectionSubtitle[$this->tab] ?? __('Configure claim setup and SBG migration references.'),
            default => __('Submit reimbursement claims, track approval status, and review payroll handoff readiness.'),
        };

        $myRequests = $currentEmployeeId !== null
            ? ClaimRequest::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $currentEmployeeId)
                ->with(['employee', 'lines.type'])
                ->latest('id')
                ->limit(50)
                ->get()
            : collect();

        $pendingRequests = ClaimRequest::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [ClaimRequest::STATUS_SUBMITTED, ClaimRequest::STATUS_RESUBMITTED])
            ->with(['employee', 'lines.type'])
            ->latest('submitted_at')
            ->limit(50)
            ->get();

        $operationsRequests = ClaimRequest::query()
            ->where('company_id', $companyId)
            ->with(['employee', 'lines.type'])
            ->when($this->operationsStatus !== '', fn ($query) => $query->where('status', $this->operationsStatus))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('reference_number', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn ($employeeQuery) => $employeeQuery
                        ->where('employee_number', 'like', "%{$search}%")
                        ->orWhere('full_name', 'like', "%{$search}%"))
                    ->orWhereHas('lines', fn ($lineQuery) => $lineQuery
                        ->where('receipt_number', 'like', "%{$search}%")
                        ->orWhere('provider_name', 'like', "%{$search}%"));
            }))
            ->latest('submitted_at')
            ->latest('id')
            ->limit(100)
            ->get();

        if ($this->operationsRisk === 'duplicate') {
            $operationsRequests = $operationsRequests->filter(fn (ClaimRequest $request): bool => ($request->metadata['duplicate_risks'] ?? []) !== []);
        } elseif ($this->operationsRisk === 'clear') {
            $operationsRequests = $operationsRequests->filter(fn (ClaimRequest $request): bool => ($request->metadata['duplicate_risks'] ?? []) === []);
        }

        if ($this->operationsPayroll !== '') {
            $operationsRequests = $operationsRequests->filter(fn (ClaimRequest $request): bool => $this->payrollOperationsState($request) === $this->operationsPayroll);
        }

        $selectedRequest = $this->selectedRequestId !== null
            ? ClaimRequest::query()
                ->where('company_id', $companyId)
                ->with(['employee', 'assignment', 'context', 'lines.type', 'lines.policy', 'auditEvents'])
                ->find($this->selectedRequestId)
            : null;

        return view('livewire.people.claim.index', [
            'surface' => $this->surface,
            'surfaceTitle' => $surfaceTitle,
            'surfaceSubtitle' => $surfaceSubtitle,
            'operationsExportUrl' => route('people.claim.operations.export.csv', [
                'search' => $this->search,
                'status' => $this->operationsStatus,
                'risk' => $this->operationsRisk,
                'payroll' => $this->operationsPayroll,
            ]),
            'accountingExportUrl' => route('people.claim.operations.accounting.csv'),
            'reimbursementStatementUrl' => route('people.claim.operations.reimbursement_statement.csv'),
            'utilizationReportUrl' => route('people.claim.operations.utilization.csv'),
            'approvalAgingUrl' => route('people.claim.operations.approval_aging.csv'),
            'canManage' => $canManage,
            'canApprove' => $canApprove,
            'currentEmployeeId' => $currentEmployeeId,
            'myRequests' => $myRequests,
            'pendingRequests' => $pendingRequests,
            'operationsRequests' => $operationsRequests,
            'claimStatusOptions' => $this->claimStatusOptions(),
            'payrollOperationsOptions' => $this->payrollOperationsOptions(),
            'selectedRequest' => $selectedRequest,
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

    /** @return array<string, string> */
    private function claimStatusOptions(): array
    {
        return [
            ClaimRequest::STATUS_DRAFT => __('Draft'),
            ClaimRequest::STATUS_SUBMITTED => __('Submitted'),
            ClaimRequest::STATUS_NEEDS_MORE_INFO => __('Needs more info'),
            ClaimRequest::STATUS_RESUBMITTED => __('Resubmitted'),
            ClaimRequest::STATUS_APPROVED => __('Approved'),
            ClaimRequest::STATUS_REJECTED => __('Rejected'),
            ClaimRequest::STATUS_WITHDRAWN => __('Withdrawn'),
            ClaimRequest::STATUS_CANCELLED => __('Cancelled'),
            ClaimRequest::STATUS_QUEUED_FOR_PAYROLL => __('Queued for payroll'),
            ClaimRequest::STATUS_REIMBURSED => __('Reimbursed'),
            ClaimRequest::STATUS_SETTLED => __('Settled'),
        ];
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
}
