<?php

namespace App\Modules\People\Leave\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Leave\Models\LeaveAssignment;
use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicy;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicyBand;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Leave\Models\LeaveRequestPolicy;
use App\Modules\People\Leave\Models\LeaveType;
use App\Modules\People\Leave\Services\ApproveLeaveRequestService;
use App\Modules\People\Leave\Services\CarryForwardService;
use App\Modules\People\Leave\Services\LeaveBalanceLedgerService;
use App\Modules\People\Leave\Services\LeaveBalanceStatementBuilder;
use App\Modules\People\Leave\Services\LeaveCountryPackRegistry;
use App\Modules\People\Leave\Services\RejectLeaveRequestService;
use App\Modules\People\Leave\Services\SubmitLeaveRequestService;
use App\Modules\People\Leave\Services\WithdrawLeaveRequestService;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class Index extends Component
{
    private const DEFAULT_EFFECTIVE_FROM = '2026-01-01';

    use WithPagination;

    public string $surface = 'my';

    public string $tab = 'apply';

    public bool $showApplyModal = false;

    public bool $showLeaveTypeModal = false;

    public bool $showEntitlementPolicyModal = false;

    public bool $showRequestPolicyModal = false;

    public bool $showEntitlementBandModal = false;

    public bool $showAssignmentModal = false;

    public bool $showAdjustmentModal = false;

    public string $search = '';

    // Leave type form
    public string $typeCode = '';
    public string $typeName = '';
    public string $typeDefaultUnit = LeaveType::UNIT_DAY;
    public bool $typePaid = true;
    public bool $typeInteractsWithPayroll = false;
    public bool $typeCompulsoryAttachment = false;

    // Entitlement policy form
    public string $entitlementLeaveTypeId = '';
    public string $entitlementCode = '';
    public string $entitlementName = '';
    public string $entitlementAccrualMethod = LeaveEntitlementPolicy::ACCRUAL_ANNUAL_LUMP_NO_PRORATE;
    public string $entitlementRounding = LeaveEntitlementPolicy::ROUNDING_NONE;
    public bool $entitlementProrateJoiners = true;
    public bool $entitlementProrateLeavers = true;
    public string $entitlementBringForwardCap = '';
    public string $entitlementBringForwardExpiryMonth = '';
    public string $entitlementBringForwardAnchor = LeaveEntitlementPolicy::ANCHOR_YEAR_START;
    public string $entitlementEffectiveFrom = self::DEFAULT_EFFECTIVE_FROM;

    // Request policy form
    public string $requestLeaveTypeId = '';
    public string $requestCode = '';
    public string $requestName = '';
    public bool $requestAllowNegative = false;
    public bool $requestIncludePending = true;
    public bool $requestAllowMultiplePerDay = false;
    public bool $requestNoCrossMonth = false;
    public bool $requestCompulsoryAttachment = false;
    public bool $requestExcludeHoliday = true;
    public bool $requestExcludeOffDay = true;
    public bool $requestExcludeRestDay = true;
    public string $requestMaxDaysPerApplication = '';
    public string $requestEffectiveFrom = self::DEFAULT_EFFECTIVE_FROM;

    // Assignment form
    public string $assignmentCode = '';
    public string $assignmentName = '';
    public string $assignmentLeaveTypeId = '';
    public string $assignmentEntitlementPolicyId = '';
    public string $assignmentRequestPolicyId = '';
    public string $assignmentEffectiveFrom = self::DEFAULT_EFFECTIVE_FROM;

    // Approval queue
    public ?int $selectedRequestId = null;
    public string $approvalReason = '';

    // Calendar tab
    public int $calendarYear;
    public string $calendarState = 'KUL';

    // Balances tab
    public string $balanceEmployeeId = '';
    public int $balanceYear;

    // Apply (employee self-service) tab
    public string $applyAssignmentId = '';
    public string $applyStartsOn = '';
    public string $applyEndsOn = '';
    public string $applyUnit = LeaveRequest::UNIT_DAY;
    public string $applyHoursCount = '';
    public bool $applyShortNotice = false;
    public string $applyNote = '';

    // Service-band editor
    public string $bandPolicyId = '';
    public string $bandMinYears = '0';
    public string $bandMaxYears = '';
    public string $bandDays = '';
    public string $bandCarryForwardOverride = '';

    // Cohort predicate editor (assignment form)
    public string $assignmentCohortGender = '';
    public string $assignmentCohortMaritalStatus = '';
    public string $assignmentCohortCitizenship = '';

    // Ledger adjustment form
    public string $adjustmentEmployeeId = '';
    public string $adjustmentLeaveTypeId = '';
    public string $adjustmentEntryType = LeaveBalanceLedgerEntry::ENTRY_OPENING;
    public string $adjustmentQuantity = '';
    public string $adjustmentUnit = 'day';
    public string $adjustmentNote = '';
    public int $adjustmentYear;

    // Carry-forward dry-run
    public int $carryForwardFromYear;
    public string $carryForwardEmployeeId = '';
    public string $carryForwardLeaveTypeId = '';
    /** @var list<array<string, mixed>> */
    public array $carryForwardPreview = [];

    public function mount(?string $surface = null, ?string $section = null): void
    {
        $this->surface = in_array($surface, ['my', 'approvals', 'settings'], true) ? $surface : 'my';

        if ($this->surface === 'settings') {
            $allowed = ['types', 'policies', 'assignments', 'balances', 'adjustments', 'carry-forward'];
            $this->tab = in_array($section, $allowed, true) ? $section : 'types';
        } else {
            $this->tab = match ($this->surface) {
                'approvals' => 'approvals',
                default => 'apply',
            };
        }

        $this->calendarYear = (int) now()->year;
        $this->balanceYear = $this->calendarYear;
        $this->applyStartsOn = now()->toDateString();
        $this->applyEndsOn = now()->toDateString();
        $this->adjustmentYear = $this->calendarYear;
        $this->carryForwardFromYear = $this->calendarYear;
    }

    /** @return list<string> */
    private function tabsForSurface(): array
    {
        return match ($this->surface) {
            'approvals' => ['approvals'],
            'settings' => [$this->tab],
            default => ['apply', 'my-balance', 'calendar'],
        };
    }

    public function applyLeave(): void
    {
        $employeeId = $this->currentEmployeeId();
        if ($employeeId === null) {
            session()->flash('error', __('Your user account is not linked to an employee record.'));
            return;
        }

        $validated = $this->validate([
            'applyAssignmentId' => ['required', 'integer', Rule::exists(LeaveAssignment::class, 'id')->where('company_id', $this->companyId())],
            'applyStartsOn' => ['required', 'date'],
            'applyEndsOn' => ['required', 'date', 'after_or_equal:applyStartsOn'],
            'applyUnit' => ['required', Rule::in([LeaveRequest::UNIT_DAY, LeaveRequest::UNIT_HALF_DAY, LeaveRequest::UNIT_HOUR])],
            'applyHoursCount' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $employee = Employee::query()->findOrFail($employeeId);
            $assignment = LeaveAssignment::query()
                ->with(['leaveType', 'entitlementPolicy', 'requestPolicy'])
                ->where('company_id', $this->companyId())
                ->findOrFail((int) $validated['applyAssignmentId']);

            app(SubmitLeaveRequestService::class)->submit(
                employee: $employee,
                assignment: $assignment,
                startsOn: new DateTimeImmutable($validated['applyStartsOn']),
                endsOn: new DateTimeImmutable($validated['applyEndsOn']),
                unit: $validated['applyUnit'],
                hoursCount: $validated['applyHoursCount'] !== '' ? (float) $validated['applyHoursCount'] : null,
                options: [
                    'short_notice' => $this->applyShortNotice,
                    'country_iso' => 'MY',
                ],
            );

            $this->reset('applyNote', 'applyHoursCount', 'applyShortNotice');
            $this->showApplyModal = false;
            session()->flash('success', __('Leave request submitted for approval.'));
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
            $request = LeaveRequest::query()
                ->where('company_id', $this->companyId())
                ->where('employee_id', $employeeId)
                ->findOrFail($requestId);

            app(WithdrawLeaveRequestService::class)->withdraw($request, Auth::id(), 'Withdrawn by employee');
            session()->flash('success', __('Leave request withdrawn.'));
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, $this->tabsForSurface(), true)) {
            return;
        }

        $this->tab = $tab;
        $this->selectedRequestId = null;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function createLeaveType(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'typeCode' => ['required', 'string', 'max:64'],
            'typeName' => ['required', 'string', 'max:255'],
            'typeDefaultUnit' => ['required', Rule::in([LeaveType::UNIT_DAY, LeaveType::UNIT_HALF_DAY, LeaveType::UNIT_HOUR])],
        ]);

        $code = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', trim($validated['typeCode'])));
        $code = trim($code, '_');

        LeaveType::query()->create([
            'company_id' => $this->companyId(),
            'code' => $code,
            'name' => $validated['typeName'],
            'paid' => $this->typePaid,
            'default_unit' => $validated['typeDefaultUnit'],
            'hour_quantum_minutes' => $validated['typeDefaultUnit'] === LeaveType::UNIT_HOUR ? 120 : null,
            'default_approval_depth' => 1,
            'interacts_with_payroll' => $this->typeInteractsWithPayroll,
            'compulsory_attachment' => $this->typeCompulsoryAttachment,
            'status' => LeaveType::STATUS_ACTIVE,
            'pack_identifier' => 'belimbing/people-core',
            'pack_version' => '2026.dev',
            'metadata' => ['source' => 'leave-workbench'],
        ]);

        $this->reset('typeCode', 'typeName');
        $this->typeDefaultUnit = LeaveType::UNIT_DAY;
        $this->typePaid = true;
        $this->typeInteractsWithPayroll = false;
        $this->typeCompulsoryAttachment = false;
        $this->showLeaveTypeModal = false;

        session()->flash('success', __('Leave type created.'));
    }

    public function createEntitlementPolicy(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'entitlementLeaveTypeId' => ['required', 'integer', Rule::exists(LeaveType::class, 'id')->where('company_id', $this->companyId())],
            'entitlementCode' => ['required', 'string', 'max:64'],
            'entitlementName' => ['required', 'string', 'max:255'],
            'entitlementAccrualMethod' => ['required', 'string'],
            'entitlementRounding' => ['required', 'string'],
            'entitlementBringForwardCap' => ['nullable', 'numeric'],
            'entitlementBringForwardExpiryMonth' => ['nullable', 'integer', 'between:1,12'],
            'entitlementBringForwardAnchor' => ['required', 'string'],
            'entitlementEffectiveFrom' => ['required', 'date'],
        ]);

        LeaveEntitlementPolicy::query()->create([
            'company_id' => $this->companyId(),
            'leave_type_id' => (int) $validated['entitlementLeaveTypeId'],
            'code' => $validated['entitlementCode'],
            'name' => $validated['entitlementName'],
            'accrual_method' => $validated['entitlementAccrualMethod'],
            'entitlement_rounding' => $validated['entitlementRounding'],
            'prorate_for_joiners' => $this->entitlementProrateJoiners,
            'prorate_for_leavers' => $this->entitlementProrateLeavers,
            'bring_forward_cap_days' => $this->blankToNull($validated['entitlementBringForwardCap'] ?? null),
            'bring_forward_expiry_month' => $this->blankToNull($validated['entitlementBringForwardExpiryMonth'] ?? null),
            'bring_forward_anchor' => $validated['entitlementBringForwardAnchor'],
            'effective_from' => $validated['entitlementEffectiveFrom'],
            'version' => 1,
            'status' => 'active',
            'metadata' => ['source' => 'leave-workbench'],
        ]);

        $this->reset('entitlementCode', 'entitlementName', 'entitlementBringForwardCap', 'entitlementBringForwardExpiryMonth');
        $this->showEntitlementPolicyModal = false;
        session()->flash('success', __('Entitlement policy created.'));
    }

    public function createRequestPolicy(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'requestLeaveTypeId' => ['required', 'integer', Rule::exists(LeaveType::class, 'id')->where('company_id', $this->companyId())],
            'requestCode' => ['required', 'string', 'max:64'],
            'requestName' => ['required', 'string', 'max:255'],
            'requestMaxDaysPerApplication' => ['nullable', 'numeric'],
            'requestEffectiveFrom' => ['required', 'date'],
        ]);

        LeaveRequestPolicy::query()->create([
            'company_id' => $this->companyId(),
            'leave_type_id' => (int) $validated['requestLeaveTypeId'],
            'code' => $validated['requestCode'],
            'name' => $validated['requestName'],
            'allow_negative_balance' => $this->requestAllowNegative,
            'include_pending_as_taken' => $this->requestIncludePending,
            'allow_multiple_applications_per_day' => $this->requestAllowMultiplePerDay,
            'no_cross_month_split' => $this->requestNoCrossMonth,
            'compulsory_attachment' => $this->requestCompulsoryAttachment,
            'exclude_holiday_from_count' => $this->requestExcludeHoliday,
            'exclude_off_day_from_count' => $this->requestExcludeOffDay,
            'exclude_rest_day_from_count' => $this->requestExcludeRestDay,
            'max_days_per_application' => $this->blankToNull($validated['requestMaxDaysPerApplication'] ?? null),
            'effective_from' => $validated['requestEffectiveFrom'],
            'version' => 1,
            'status' => 'active',
            'metadata' => ['source' => 'leave-workbench'],
        ]);

        $this->reset('requestCode', 'requestName', 'requestMaxDaysPerApplication');
        $this->showRequestPolicyModal = false;
        session()->flash('success', __('Request policy created.'));
    }

    public function createAssignment(): void
    {
        $this->authorizeManage();

        $companyId = $this->companyId();

        $validated = $this->validate([
            'assignmentCode' => ['required', 'string', 'max:64'],
            'assignmentName' => ['required', 'string', 'max:255'],
            'assignmentLeaveTypeId' => ['required', 'integer', Rule::exists(LeaveType::class, 'id')->where('company_id', $companyId)],
            'assignmentEntitlementPolicyId' => ['required', 'integer', Rule::exists(LeaveEntitlementPolicy::class, 'id')->where('company_id', $companyId)],
            'assignmentRequestPolicyId' => ['required', 'integer', Rule::exists(LeaveRequestPolicy::class, 'id')->where('company_id', $companyId)],
            'assignmentEffectiveFrom' => ['required', 'date'],
        ]);

        $predicate = array_filter([
            'gender' => $this->assignmentCohortGender ?: null,
            'marital_status' => $this->assignmentCohortMaritalStatus ?: null,
            'citizenship_status' => $this->assignmentCohortCitizenship ?: null,
        ]);

        LeaveAssignment::query()->create([
            'company_id' => $companyId,
            'code' => $validated['assignmentCode'],
            'name' => $validated['assignmentName'],
            'leave_type_id' => (int) $validated['assignmentLeaveTypeId'],
            'leave_entitlement_policy_id' => (int) $validated['assignmentEntitlementPolicyId'],
            'leave_request_policy_id' => (int) $validated['assignmentRequestPolicyId'],
            'cohort_predicate' => $predicate,
            'effective_from' => $validated['assignmentEffectiveFrom'],
            'status' => 'active',
            'metadata' => ['source' => 'leave-workbench'],
        ]);

        $this->reset('assignmentCode', 'assignmentName', 'assignmentCohortGender', 'assignmentCohortMaritalStatus', 'assignmentCohortCitizenship');
        $this->showAssignmentModal = false;
        session()->flash('success', __('Leave assignment created.'));
    }

    public function addEntitlementBand(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'bandPolicyId' => ['required', 'integer', Rule::exists(LeaveEntitlementPolicy::class, 'id')->where('company_id', $this->companyId())],
            'bandMinYears' => ['required', 'numeric', 'min:0'],
            'bandMaxYears' => ['nullable', 'numeric', 'gte:bandMinYears'],
            'bandDays' => ['required', 'numeric', 'min:0'],
            'bandCarryForwardOverride' => ['nullable', 'numeric', 'min:0'],
        ]);

        $nextOrder = (int) LeaveEntitlementPolicyBand::query()
            ->where('leave_entitlement_policy_id', $validated['bandPolicyId'])
            ->max('sort_order') + 10;

        LeaveEntitlementPolicyBand::query()->create([
            'leave_entitlement_policy_id' => (int) $validated['bandPolicyId'],
            'min_years_of_service' => $validated['bandMinYears'],
            'max_years_of_service' => $this->blankToNull($validated['bandMaxYears'] ?? null),
            'entitlement_days' => $validated['bandDays'],
            'bring_forward_cap_days_override' => $this->blankToNull($validated['bandCarryForwardOverride'] ?? null),
            'sort_order' => $nextOrder,
            'metadata' => ['source' => 'leave-workbench'],
        ]);

        $this->reset('bandMinYears', 'bandMaxYears', 'bandDays', 'bandCarryForwardOverride');
        $this->bandMinYears = '0';
        $this->showEntitlementBandModal = false;
        session()->flash('success', __('Entitlement band added.'));
    }

    public function recordAdjustment(): void
    {
        $this->authorizeManage();

        $companyId = $this->companyId();

        $validated = $this->validate([
            'adjustmentEmployeeId' => ['required', 'integer', Rule::exists(Employee::class, 'id')->where('company_id', $companyId)],
            'adjustmentLeaveTypeId' => ['required', 'integer', Rule::exists(LeaveType::class, 'id')->where('company_id', $companyId)],
            'adjustmentEntryType' => ['required', Rule::in([
                LeaveBalanceLedgerEntry::ENTRY_OPENING,
                LeaveBalanceLedgerEntry::ENTRY_ADJUSTED,
                LeaveBalanceLedgerEntry::ENTRY_ACCRUAL,
            ])],
            'adjustmentQuantity' => ['required', 'numeric'],
            'adjustmentUnit' => ['required', Rule::in(['day', 'hour'])],
            'adjustmentYear' => ['required', 'integer'],
            'adjustmentNote' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $leaveType = LeaveType::query()->find((int) $validated['adjustmentLeaveTypeId']);

            app(LeaveBalanceLedgerService::class)->record(
                companyId: $companyId,
                employeeId: (int) $validated['adjustmentEmployeeId'],
                leaveTypeId: (int) $validated['adjustmentLeaveTypeId'],
                leaveYear: (int) $validated['adjustmentYear'],
                entryType: $validated['adjustmentEntryType'],
                quantity: (float) $validated['adjustmentQuantity'],
                unit: $validated['adjustmentUnit'],
                sourceType: LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
                packIdentifier: $leaveType?->pack_identifier,
                packVersion: $leaveType?->pack_version,
                occurredOn: new DateTimeImmutable(sprintf('%d-01-01', (int) $validated['adjustmentYear'])),
                recordedByUserId: Auth::id(),
                note: $validated['adjustmentNote'] ?: null,
                metadata: ['source' => 'leave-workbench'],
            );

            $this->reset('adjustmentQuantity', 'adjustmentNote');
            $this->showAdjustmentModal = false;
            session()->flash('success', __('Ledger adjustment recorded.'));
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function previewCarryForward(): void
    {
        $this->authorizeManage();

        $companyId = $this->companyId();
        $service = app(CarryForwardService::class);

        $query = LeaveBalanceLedgerEntry::query()
            ->where('company_id', $companyId)
            ->where('leave_year', $this->carryForwardFromYear)
            ->select('employee_id', 'leave_type_id')
            ->distinct();

        if ($this->carryForwardEmployeeId !== '' && ctype_digit($this->carryForwardEmployeeId)) {
            $query->where('employee_id', (int) $this->carryForwardEmployeeId);
        }

        if ($this->carryForwardLeaveTypeId !== '' && ctype_digit($this->carryForwardLeaveTypeId)) {
            $query->where('leave_type_id', (int) $this->carryForwardLeaveTypeId);
        }

        $pairs = $query->get();
        $preview = [];

        foreach ($pairs as $pair) {
            $policy = LeaveEntitlementPolicy::query()
                ->where('company_id', $companyId)
                ->where('leave_type_id', $pair->leave_type_id)
                ->orderByDesc('effective_from')
                ->first();

            if ($policy === null) {
                continue;
            }

            $outcome = $service->compute(
                companyId: $companyId,
                employeeId: (int) $pair->employee_id,
                leaveTypeId: (int) $pair->leave_type_id,
                fromYear: $this->carryForwardFromYear,
                policy: $policy,
                dryRun: true,
            );

            $preview[] = [
                'employee_id' => $outcome->employeeId,
                'leave_type_id' => $outcome->leaveTypeId,
                'remaining' => $outcome->remainingBalance,
                'cap' => $outcome->capDays,
                'carried' => $outcome->carriedForward,
                'expired' => $outcome->expiredAtYearEnd,
                'to_year' => $outcome->toYear,
                'policy_code' => $policy->code,
            ];
        }

        $this->carryForwardPreview = $preview;

        if ($preview === []) {
            session()->flash('error', __('No ledger entries found for the chosen filters.'));
        }
    }

    public function commitCarryForward(): void
    {
        $this->authorizeManage();

        if ($this->carryForwardPreview === []) {
            session()->flash('error', __('Generate a preview first.'));
            return;
        }

        $companyId = $this->companyId();
        $service = app(CarryForwardService::class);
        $count = 0;

        foreach ($this->carryForwardPreview as $row) {
            $policy = LeaveEntitlementPolicy::query()
                ->where('company_id', $companyId)
                ->where('leave_type_id', (int) $row['leave_type_id'])
                ->orderByDesc('effective_from')
                ->first();

            if ($policy === null) {
                continue;
            }

            $service->compute(
                companyId: $companyId,
                employeeId: (int) $row['employee_id'],
                leaveTypeId: (int) $row['leave_type_id'],
                fromYear: $this->carryForwardFromYear,
                policy: $policy,
                dryRun: false,
            );
            $count++;
        }

        $this->carryForwardPreview = [];
        session()->flash('success', __('Carry-forward committed for :n (employee, leave-type) pair(s).', ['n' => $count]));
    }

    public function selectRequest(int $requestId): void
    {
        $this->selectedRequestId = $requestId;
        $this->tab = 'approvals';
    }

    public function approveRequest(int $requestId): void
    {
        $this->authorizeApprove();

        try {
            $request = LeaveRequest::query()
                ->where('company_id', $this->companyId())
                ->findOrFail($requestId);

            app(ApproveLeaveRequestService::class)->approve($request, Auth::id(), $this->approvalReason ?: null);
            $this->approvalReason = '';
            session()->flash('success', __('Leave request approved.'));
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function rejectRequest(int $requestId): void
    {
        $this->authorizeApprove();

        try {
            $request = LeaveRequest::query()
                ->where('company_id', $this->companyId())
                ->findOrFail($requestId);

            app(RejectLeaveRequestService::class)->reject($request, Auth::id(), $this->approvalReason ?: null);
            $this->approvalReason = '';
            session()->flash('success', __('Leave request rejected.'));
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            LeaveRequest::STATUS_DRAFT => 'default',
            LeaveRequest::STATUS_SUBMITTED => 'warning',
            LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_APPLIED => 'success',
            LeaveRequest::STATUS_REJECTED, LeaveRequest::STATUS_CANCELLED, LeaveRequest::STATUS_WITHDRAWN => 'danger',
            default => 'info',
        };
    }

    public function render(): View
    {
        $authActor = Actor::forUser(Auth::user());
        $authz = app(AuthorizationService::class);
        $canManage = $authz->can($authActor, 'people.leave.manage')->allowed;
        $canApprove = $authz->can($authActor, 'people.leave.approve')->allowed;

        $companyId = $this->companyId();
        $search = trim($this->search);

        $leaveTypes = LeaveType::query()
            ->where('company_id', $companyId)
            ->when($search !== '' && $this->tab === 'types', fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
            }))
            ->orderBy('code')
            ->get();

        $entitlementPolicies = LeaveEntitlementPolicy::query()
            ->where('company_id', $companyId)
            ->with(['leaveType', 'bands'])
            ->orderBy('code')
            ->get();

        $requestPolicies = LeaveRequestPolicy::query()
            ->where('company_id', $companyId)
            ->with('leaveType')
            ->orderBy('code')
            ->get();

        $assignments = LeaveAssignment::query()
            ->where('company_id', $companyId)
            ->with(['leaveType', 'entitlementPolicy', 'requestPolicy'])
            ->orderBy('code')
            ->get();

        $pendingRequests = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('status', LeaveRequest::STATUS_SUBMITTED)
            ->with(['employee', 'leaveType'])
            ->orderBy('starts_on')
            ->paginate(10, ['*'], 'pendingPage');

        $teamCalendarRequests = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_APPLIED, LeaveRequest::STATUS_SUBMITTED])
            ->whereYear('starts_on', $this->calendarYear)
            ->with(['employee', 'leaveType'])
            ->orderBy('starts_on')
            ->limit(100)
            ->get();

        $publicHolidays = $this->resolveHolidays();

        $selectedRequest = $this->selectedRequestId !== null
            ? LeaveRequest::query()
                ->where('company_id', $companyId)
                ->with(['employee', 'leaveType', 'days', 'auditEvents'])
                ->find($this->selectedRequestId)
            : null;

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->orderBy('employee_number')
            ->limit(500)
            ->get();

        $balanceStatement = null;
        if ($this->balanceEmployeeId !== '' && ctype_digit($this->balanceEmployeeId)) {
            $balanceStatement = app(LeaveBalanceStatementBuilder::class)
                ->build((int) $this->balanceEmployeeId, $this->balanceYear, $companyId);
        }

        $countryPacks = app(LeaveCountryPackRegistry::class)->all();

        $recentManualEntries = LeaveBalanceLedgerEntry::query()
            ->where('company_id', $companyId)
            ->where('source_type', LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT)
            ->with(['employee', 'leaveType'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $currentEmployeeId = $this->currentEmployeeId();
        $myAssignments = LeaveAssignment::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->with(['leaveType', 'entitlementPolicy', 'requestPolicy'])
            ->orderBy('code')
            ->get();

        $myRequests = $currentEmployeeId !== null
            ? LeaveRequest::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $currentEmployeeId)
                ->with(['leaveType'])
                ->orderByDesc('starts_on')
                ->limit(50)
                ->get()
            : collect();

        $myBalanceStatement = $currentEmployeeId !== null
            ? app(LeaveBalanceStatementBuilder::class)->build($currentEmployeeId, $this->balanceYear, $companyId)
            : null;

        $allTabs = [
            'apply' => ['id' => 'apply', 'label' => __('Apply Leave'), 'icon' => 'heroicon-o-paper-airplane'],
            'my-balance' => ['id' => 'my-balance', 'label' => __('My Balance'), 'icon' => 'heroicon-o-chart-pie'],
            'calendar' => ['id' => 'calendar', 'label' => __('Team Calendar'), 'icon' => 'heroicon-o-calendar'],
            'approvals' => ['id' => 'approvals', 'label' => __('Approvals'), 'icon' => 'heroicon-o-check-badge'],
            'balances' => ['id' => 'balances', 'label' => __('Balance Statement'), 'icon' => 'heroicon-o-chart-bar'],
            'types' => ['id' => 'types', 'label' => __('Leave Types'), 'icon' => 'heroicon-o-tag'],
            'policies' => ['id' => 'policies', 'label' => __('Policies'), 'icon' => 'heroicon-o-document-text'],
            'assignments' => ['id' => 'assignments', 'label' => __('Assignments'), 'icon' => 'heroicon-o-user-group'],
            'adjustments' => ['id' => 'adjustments', 'label' => __('Adjustments'), 'icon' => 'heroicon-o-pencil-square'],
            'carry-forward' => ['id' => 'carry-forward', 'label' => __('Carry-Forward'), 'icon' => 'heroicon-o-arrow-path'],
        ];

        $tabsConfig = array_values(array_map(
            fn (string $id) => $allTabs[$id],
            $this->tabsForSurface(),
        ));

        $settingsSectionTitle = [
            'types' => __('Leave Types'),
            'policies' => __('Leave Policies'),
            'assignments' => __('Leave Assignments'),
            'balances' => __('Balance Statement'),
            'adjustments' => __('Manual Adjustments'),
            'carry-forward' => __('Year-End Carry-Forward'),
        ];

        $surfaceTitle = match ($this->surface) {
            'approvals' => __('Leave Approvals'),
            'settings' => $settingsSectionTitle[$this->tab] ?? __('Leave Settings'),
            default => __('My Leave'),
        };

        $settingsSectionSubtitle = [
            'types' => __('Neutral leave-type catalog. Country packs and the SBG seeder layer statutory and licensee-specific types on top.'),
            'policies' => __('Effective-dated entitlement bands and request-side rules (notice, attachments, day-counting).'),
            'assignments' => __('Bind employee cohorts to a (leave type, entitlement, request policy) triple.'),
            'balances' => __('Per-employee balance projected from the append-only ledger.'),
            'adjustments' => __('Append a ledger fact — opening balance, correction, or manual accrual. Original entries are never mutated.'),
            'carry-forward' => __('Preview and commit year-end carry-forward. Writes carried_forward + expired ledger facts; the from-year nets to zero above the cap.'),
        ];

        $surfaceSubtitle = match ($this->surface) {
            'approvals' => __('Review and act on leave requests awaiting your approval.'),
            'settings' => $settingsSectionSubtitle[$this->tab] ?? __('Configure leave types, policies, assignments, balances, and year-end carry-forward.'),
            default => __('Apply for leave, review your balance and history, and see your team\'s schedule.'),
        };

        return view('livewire.people.leave.index', [
            'tabs' => $tabsConfig,
            'surface' => $this->surface,
            'surfaceTitle' => $surfaceTitle,
            'surfaceSubtitle' => $surfaceSubtitle,
            'currentEmployeeId' => $currentEmployeeId,
            'myAssignments' => $myAssignments,
            'myRequests' => $myRequests,
            'myBalanceStatement' => $myBalanceStatement,
            'recentManualEntries' => $recentManualEntries,
            'leaveTypes' => $leaveTypes,
            'entitlementPolicies' => $entitlementPolicies,
            'requestPolicies' => $requestPolicies,
            'assignments' => $assignments,
            'pendingRequests' => $pendingRequests,
            'selectedRequest' => $selectedRequest,
            'teamCalendarRequests' => $teamCalendarRequests,
            'publicHolidays' => $publicHolidays,
            'employees' => $employees,
            'balanceStatement' => $balanceStatement,
            'countryPacks' => $countryPacks,
            'canManage' => $canManage,
            'canApprove' => $canApprove,
        ]);
    }

    /** @return list<array{occurs_on: string, name: string, scope: string, state_codes: list<string>, substituted: bool}> */
    private function resolveHolidays(): array
    {
        $registry = app(LeaveCountryPackRegistry::class);

        if (! $registry->hasCountry('MY')) {
            return [];
        }

        $calendar = $registry->forCountry('MY')->publicHolidayCalendar();
        $holidays = $calendar->publicHolidaysForYear($this->calendarYear, $this->calendarState ?: null);

        return array_map(static fn ($h) => [
            'occurs_on' => $h->occursOn->format('Y-m-d'),
            'name' => $h->name,
            'scope' => $h->scope,
            'state_codes' => $h->stateCodes,
            'substituted' => $h->substitutedFrom !== null,
        ], $holidays);
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
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
            'people.leave.manage',
        );
    }

    private function authorizeApprove(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'people.leave.approve',
        );
    }
}
