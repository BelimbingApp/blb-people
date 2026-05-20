<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Leave\Contracts\RoutesLeaveApprovals;
use App\Modules\People\Leave\Data\LeaveApprovalIntent;
use App\Modules\People\Leave\Data\LeaveDayBreakdown;
use App\Modules\People\Leave\Data\LeaveDaysPreview;
use App\Modules\People\Leave\Data\LeaveSubmissionContext;
use App\Modules\People\Leave\Data\LeaveValidationIssue;
use App\Modules\People\Leave\Exceptions\LeaveRequestValidationException;
use App\Modules\People\Leave\Models\LeaveAssignment;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Leave\Models\LeaveRequestAuditEvent;
use App\Modules\People\Leave\Models\LeaveRequestDay;
use App\Modules\People\Leave\Models\LeaveRequestPolicy;
use App\Modules\People\Leave\Models\LeaveType;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class SubmitLeaveRequestService
{
    public function __construct(
        private readonly LeaveRequestDaysBuilder $daysBuilder,
        private readonly LeaveBalanceLedgerService $ledger,
        private readonly RoutesLeaveApprovals $approvalRouter,
        private readonly LeaveNotificationDispatcher $notifications,
        private readonly OnBehalfApplicationService $onBehalfApplications,
    ) {}

    /**
     * @param  array<string, mixed>  $options  Optional: country_iso, state_code, attachments, on_behalf_actor_user_id, on_behalf_reason, short_notice.
     */
    public function submit(
        Employee $employee,
        LeaveAssignment $assignment,
        DateTimeImmutable $startsOn,
        DateTimeImmutable $endsOn,
        string $unit = LeaveRequest::UNIT_DAY,
        ?float $hoursCount = null,
        array $options = [],
    ): LeaveRequest {
        return DB::transaction(function () use ($employee, $assignment, $startsOn, $endsOn, $unit, $hoursCount, $options): LeaveRequest {
            $leaveType = $assignment->leaveType;
            $requestPolicy = $assignment->requestPolicy;
            $entitlementPolicy = $assignment->entitlementPolicy;

            $preview = $this->daysBuilder->preview(
                employee: $employee,
                startsOn: $startsOn,
                endsOn: $endsOn,
                unit: $unit,
                hoursCount: $hoursCount,
                policy: $requestPolicy,
                countryIso: $options['country_iso'] ?? null,
                stateCode: $options['state_code'] ?? null,
            );

            $attachmentCount = (int) ($options['attachment_count'] ?? 0);
            $issues = $this->validate(
                $assignment,
                $employee,
                $preview,
                new LeaveSubmissionContext(
                    startsOn: $startsOn,
                    endsOn: $endsOn,
                    unit: $unit,
                    attachmentCount: $attachmentCount,
                    options: $options,
                ),
            );

            $blocking = array_filter($issues, fn (LeaveValidationIssue $i) => $i->isBlocking());
            if ($blocking !== []) {
                throw new LeaveRequestValidationException(array_values($blocking));
            }

            $request = LeaveRequest::query()->create([
                'company_id' => $assignment->company_id,
                'employee_id' => $employee->getKey(),
                'leave_type_id' => $leaveType->getKey(),
                'leave_assignment_id' => $assignment->getKey(),
                'leave_request_policy_id' => $requestPolicy->getKey(),
                'leave_request_policy_version' => $requestPolicy->version,
                'status' => LeaveRequest::STATUS_SUBMITTED,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'unit' => $unit,
                'quantity' => $preview->totalCountedDays + ($preview->totalCountedHours / 8.0),
                'attachment_count' => $attachmentCount,
                'on_behalf_actor_user_id' => $options['on_behalf_actor_user_id'] ?? null,
                'on_behalf_reason' => $options['on_behalf_reason'] ?? null,
                'short_notice' => (bool) ($options['short_notice'] ?? false),
                'back_dated' => $startsOn < new DateTimeImmutable('today'),
                'emergency_tag' => $this->resolveApplicationTag($requestPolicy, $startsOn, $options),
                'submitted_at' => now(),
                'metadata' => [
                    'preview_warnings' => $preview->warnings,
                    'entitlement_policy_version' => $entitlementPolicy?->version,
                ],
            ]);

            foreach ($preview->days as $day) {
                LeaveRequestDay::query()->create([
                    'leave_request_id' => $request->getKey(),
                    'occurs_on' => $day->occursOn,
                    'portion' => $day->portion,
                    'hours_count' => $day->hoursCount,
                    'daytype' => $day->daytype,
                    'counts_against_balance' => $day->countsAgainstBalance,
                    'metadata' => $day->note ? ['note' => $day->note] : null,
                ]);
            }

            if (isset($options['on_behalf_actor_user_id'], $options['on_behalf_reason'])) {
                $request = $this->onBehalfApplications->attach(
                    $request,
                    (int) $options['on_behalf_actor_user_id'],
                    (string) $options['on_behalf_reason'],
                );
            }

            LeaveRequestAuditEvent::query()->create([
                'leave_request_id' => $request->getKey(),
                'from_status' => LeaveRequest::STATUS_DRAFT,
                'to_status' => LeaveRequest::STATUS_SUBMITTED,
                'actor_user_id' => $options['on_behalf_actor_user_id'] ?? null,
                'reason' => 'submitted',
                'occurred_at' => now(),
                'metadata' => ['total_days' => $preview->totalCountedDays],
            ]);

            $intent = new LeaveApprovalIntent(
                leaveRequestId: $request->getKey(),
                approvalDepth: (int) $leaveType->default_approval_depth,
                daysThreshold: $preview->totalCountedDays,
                metadata: ['emergency_tag' => $request->emergency_tag],
            );
            $this->approvalRouter->route($request, $intent);

            $this->notifications->dispatch(LeaveNotificationDispatcher::EVENT_SUBMITTED, $request);

            return $request->refresh();
        });
    }

    /** @return list<LeaveValidationIssue> */
    private function validate(
        LeaveAssignment $assignment,
        Employee $employee,
        LeaveDaysPreview $preview,
        LeaveSubmissionContext $ctx,
    ): array {
        $issues = [];
        $requestPolicy = $assignment->requestPolicy;
        $leaveType = $assignment->leaveType;

        if (((bool) $requestPolicy->compulsory_attachment || (bool) $leaveType->compulsory_attachment) && $ctx->attachmentCount < 1) {
            $issues[] = new LeaveValidationIssue(
                code: 'attachment_required',
                message: sprintf('%s requires a supporting attachment.', $leaveType->name),
            );
        }

        if ($requestPolicy->max_days_per_application !== null
            && $preview->totalCountedDays > (float) $requestPolicy->max_days_per_application) {
            $issues[] = new LeaveValidationIssue(
                code: 'max_days_exceeded',
                message: sprintf(
                    '%s: request of %.2f days exceeds max-days-per-application of %.2f.',
                    $leaveType->name,
                    $preview->totalCountedDays,
                    (float) $requestPolicy->max_days_per_application,
                ),
            );
        }

        if ((bool) $requestPolicy->no_cross_month_split && $ctx->startsOn->format('Y-m') !== $ctx->endsOn->format('Y-m')) {
            $issues[] = new LeaveValidationIssue(
                code: 'cross_month_split_not_allowed',
                message: sprintf(
                    '%s cannot cross a month boundary under the active request policy.',
                    $leaveType->name,
                ),
            );
        }

        $issues = [
            ...$issues,
            ...$this->validateNoticeRules($requestPolicy, $leaveType, $employee, $ctx->startsOn, $ctx->options),
            ...$this->validateOverlapRules($requestPolicy, $employee, $preview, $ctx->unit),
        ];

        if (! (bool) $requestPolicy->allow_negative_balance && $preview->totalCountedDays > 0) {
            $year = $preview->days[0]->occursOn->format('Y');
            $available = $this->ledger->balanceFor($employee->getKey(), $leaveType->getKey(), (int) $year);

            if ((bool) $requestPolicy->include_pending_as_taken) {
                $encumbered = $this->ledger->encumberedFor($employee->getKey(), $leaveType->getKey(), (int) $year);
                $consumed = $this->ledger->consumedFor($employee->getKey(), $leaveType->getKey(), (int) $year);
                $available -= max(0.0, $encumbered - $consumed);
            }

            if ($preview->totalCountedDays > $available) {
                $issues[] = new LeaveValidationIssue(
                    code: 'insufficient_balance',
                    message: sprintf(
                        '%s: insufficient balance (have %.2f, request %.2f).',
                        $leaveType->name,
                        $available,
                        $preview->totalCountedDays,
                    ),
                );
            }
        }

        return $issues;
    }

    /**
     * @return list<LeaveValidationIssue>
     */
    private function validateNoticeRules(
        LeaveRequestPolicy $requestPolicy,
        LeaveType $leaveType,
        Employee $employee,
        DateTimeImmutable $startsOn,
        array $options,
    ): array {
        $today = new DateTimeImmutable('today');
        $daysUntilStart = (int) $today->diff($startsOn)->format('%r%a');

        return [
            ...$this->validateAdvanceNotice($requestPolicy, $leaveType, $employee, $startsOn, $daysUntilStart, $options),
            ...$this->validateBackDateRule($requestPolicy, $leaveType, $startsOn, $today, $daysUntilStart),
        ];
    }

    /**
     * @return list<LeaveValidationIssue>
     */
    private function validateAdvanceNotice(
        LeaveRequestPolicy $requestPolicy,
        LeaveType $leaveType,
        Employee $employee,
        DateTimeImmutable $startsOn,
        int $daysUntilStart,
        array $options,
    ): array {
        $advanceNotice = is_array($requestPolicy->advance_notice) ? $requestPolicy->advance_notice : [];
        $standardDays = max(0, (int) ($advanceNotice['standard_days'] ?? 0));

        if ($standardDays === 0 || $daysUntilStart >= $standardDays) {
            return [];
        }

        $requestedShortNotice = (bool) ($options['short_notice'] ?? false);
        $shortNotice = is_array($advanceNotice['short_notice'] ?? null) ? $advanceNotice['short_notice'] : [];

        if (! $requestedShortNotice || ! (bool) ($shortNotice['allowed'] ?? false)) {
            return [new LeaveValidationIssue(
                code: 'advance_notice_required',
                message: sprintf(
                    '%s requires %d day(s) advance notice unless short-notice handling is explicitly allowed.',
                    $leaveType->name,
                    $standardDays,
                ),
            )];
        }

        return $this->validateShortNoticeWindow($shortNotice, $leaveType, $employee, $startsOn, $daysUntilStart);
    }

    /**
     * @param  array<string, mixed>  $shortNotice
     * @return list<LeaveValidationIssue>
     */
    private function validateShortNoticeWindow(
        array $shortNotice,
        LeaveType $leaveType,
        Employee $employee,
        DateTimeImmutable $startsOn,
        int $daysUntilStart,
    ): array {
        $issues = [];

        if ((bool) ($shortNotice['disallow_today'] ?? false) && $daysUntilStart <= 0) {
            $issues[] = new LeaveValidationIssue(
                code: 'short_notice_same_day_not_allowed',
                message: sprintf('%s cannot be submitted as short notice on the same day.', $leaveType->name),
            );
        }

        $annualCap = $shortNotice['annual_cap'] ?? null;
        if (! is_numeric($annualCap)) {
            return $issues;
        }

        $usedShortNotice = LeaveRequest::query()
            ->where('employee_id', $employee->getKey())
            ->whereYear('starts_on', (int) $startsOn->format('Y'))
            ->where('short_notice', true)
            ->whereNotIn('status', [
                LeaveRequest::STATUS_REJECTED,
                LeaveRequest::STATUS_CANCELLED,
                LeaveRequest::STATUS_WITHDRAWN,
            ])
            ->count();

        if ($usedShortNotice >= (int) $annualCap) {
            $issues[] = new LeaveValidationIssue(
                code: 'short_notice_annual_cap_exceeded',
                message: sprintf(
                    '%s short-notice quota of %d request(s) for %d has been exhausted.',
                    $leaveType->name,
                    (int) $annualCap,
                    (int) $startsOn->format('Y'),
                ),
            );
        }

        return $issues;
    }

    /**
     * @return list<LeaveValidationIssue>
     */
    private function validateBackDateRule(
        LeaveRequestPolicy $requestPolicy,
        LeaveType $leaveType,
        DateTimeImmutable $startsOn,
        DateTimeImmutable $today,
        int $daysUntilStart,
    ): array {
        if ($startsOn >= $today) {
            return [];
        }

        $backDate = is_array($requestPolicy->back_date) ? $requestPolicy->back_date : [];

        if (! (bool) ($backDate['allowed'] ?? false)) {
            return [new LeaveValidationIssue(
                code: 'back_date_not_allowed',
                message: sprintf('%s does not allow back-dated applications.', $leaveType->name),
            )];
        }

        if (! isset($backDate['max_days']) || ! is_numeric($backDate['max_days'])) {
            return [];
        }

        $maxBackDateDays = max(0, (int) $backDate['max_days']);
        $daysBackDated = abs($daysUntilStart);

        if ($daysBackDated <= $maxBackDateDays) {
            return [];
        }

        return [new LeaveValidationIssue(
            code: 'back_date_window_exceeded',
            message: sprintf(
                '%s can only be back-dated by %d day(s); requested %d day(s).',
                $leaveType->name,
                $maxBackDateDays,
                $daysBackDated,
            ),
        )];
    }

    /**
     * @return list<LeaveValidationIssue>
     */
    private function validateOverlapRules(
        LeaveRequestPolicy $requestPolicy,
        Employee $employee,
        LeaveDaysPreview $preview,
        string $unit,
    ): array {
        $requestedDays = array_filter(
            $preview->days,
            fn (LeaveDayBreakdown $day): bool => $day->countsAgainstBalance,
        );

        if ($requestedDays === []) {
            return [];
        }

        $requestedDates = array_values(array_unique(array_map(
            fn (LeaveDayBreakdown $day): string => $day->occursOn->format('Y-m-d'),
            $requestedDays,
        )));
        $firstRequestedDate = min($requestedDates);
        $lastRequestedDate = max($requestedDates);

        $overlappingRequests = LeaveRequest::query()
            ->where('employee_id', $employee->getKey())
            ->where('starts_on', '<=', $lastRequestedDate)
            ->where('ends_on', '>=', $firstRequestedDate)
            ->whereNotIn('status', [
                LeaveRequest::STATUS_REJECTED,
                LeaveRequest::STATUS_CANCELLED,
                LeaveRequest::STATUS_WITHDRAWN,
            ])
            ->exists();

        if ($overlappingRequests && ! (bool) $requestPolicy->allow_multiple_applications_per_day) {
            return [
                new LeaveValidationIssue(
                    code: 'overlapping_request',
                    message: sprintf(
                        'Existing leave already occupies %s to %s; multiple applications per day are disabled.',
                        $firstRequestedDate,
                        $lastRequestedDate,
                    ),
                ),
            ];
        }

        $existingDays = LeaveRequestDay::query()
            ->with('request')
            ->whereIn('occurs_on', $requestedDates)
            ->where('counts_against_balance', true)
            ->whereHas('request', function ($query) use ($employee): void {
                $query->where('employee_id', $employee->getKey())
                    ->whereNotIn('status', [
                        LeaveRequest::STATUS_REJECTED,
                        LeaveRequest::STATUS_CANCELLED,
                        LeaveRequest::STATUS_WITHDRAWN,
                    ]);
            })
            ->get()
            ->groupBy(fn (LeaveRequestDay $day): string => $day->occurs_on->format('Y-m-d'));

        foreach ($requestedDays as $requestedDay) {
            $dateKey = $requestedDay->occursOn->format('Y-m-d');
            $matches = $existingDays->get($dateKey);
            if ($matches === null) {
                continue;
            }

            foreach ($matches as $existingDay) {
                if ($this->portionsConflict($requestedDay->portion, $existingDay->portion, $unit, (string) $existingDay->request?->unit)) {
                    return [
                        new LeaveValidationIssue(
                            code: 'overlapping_request',
                            message: sprintf(
                                'Existing leave on %s overlaps the requested portion.',
                                $dateKey,
                            ),
                        ),
                    ];
                }
            }
        }

        return [];
    }

    private function portionsConflict(
        string $requestedPortion,
        string $existingPortion,
        string $requestedUnit,
        ?string $existingUnit,
    ): bool {
        if ($requestedUnit === LeaveRequest::UNIT_HOUR || $existingUnit === LeaveRequest::UNIT_HOUR) {
            return true;
        }

        if ($requestedPortion === LeaveRequestDay::PORTION_FULL || $existingPortion === LeaveRequestDay::PORTION_FULL) {
            return true;
        }

        return $requestedPortion === $existingPortion;
    }

    private function resolveApplicationTag(
        LeaveRequestPolicy $requestPolicy,
        DateTimeImmutable $startsOn,
        array $options,
    ): ?string {
        $explicitTag = $options['emergency_tag'] ?? null;
        if (is_string($explicitTag) && $explicitTag !== '') {
            return $explicitTag;
        }

        if ((bool) ($options['short_notice'] ?? false)) {
            $shortNoticeTag = $this->shortNoticeTag($requestPolicy);
            if ($shortNoticeTag !== null) {
                return $shortNoticeTag;
            }
        }

        if ($startsOn < new DateTimeImmutable('today')) {
            return $this->backDateTag($requestPolicy);
        }

        return null;
    }

    private function shortNoticeTag(LeaveRequestPolicy $requestPolicy): ?string
    {
        $advanceNotice = is_array($requestPolicy->advance_notice) ? $requestPolicy->advance_notice : [];
        $shortNotice = is_array($advanceNotice['short_notice'] ?? null) ? $advanceNotice['short_notice'] : [];
        $tag = $shortNotice['tag'] ?? null;

        return is_string($tag) && $tag !== '' ? $tag : null;
    }

    private function backDateTag(LeaveRequestPolicy $requestPolicy): ?string
    {
        $backDate = is_array($requestPolicy->back_date) ? $requestPolicy->back_date : [];
        $tag = $backDate['tag'] ?? null;

        return is_string($tag) && $tag !== '' ? $tag : null;
    }
}
