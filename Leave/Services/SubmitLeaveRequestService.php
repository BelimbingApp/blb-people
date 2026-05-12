<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Leave\Contracts\RoutesLeaveApprovals;
use App\Modules\People\Leave\Data\LeaveApprovalIntent;
use App\Modules\People\Leave\Data\LeaveValidationIssue;
use App\Modules\People\Leave\Exceptions\LeaveRequestValidationException;
use App\Modules\People\Leave\Models\LeaveAssignment;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Leave\Models\LeaveRequestAuditEvent;
use App\Modules\People\Leave\Models\LeaveRequestDay;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class SubmitLeaveRequestService
{
    public function __construct(
        private readonly LeaveRequestDaysBuilder $daysBuilder,
        private readonly LeaveBalanceLedgerService $ledger,
        private readonly RoutesLeaveApprovals $approvalRouter,
        private readonly LeaveNotificationDispatcher $notifications,
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
                assignment: $assignment,
                employee: $employee,
                preview: $preview,
                attachmentCount: $attachmentCount,
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
                'emergency_tag' => $options['emergency_tag'] ?? null,
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

    /**
     * @return list<LeaveValidationIssue>
     */
    private function validate(
        LeaveAssignment $assignment,
        Employee $employee,
        \App\Modules\People\Leave\Data\LeaveDaysPreview $preview,
        int $attachmentCount,
    ): array {
        $issues = [];
        $requestPolicy = $assignment->requestPolicy;
        $leaveType = $assignment->leaveType;

        if (((bool) $requestPolicy->compulsory_attachment || (bool) $leaveType->compulsory_attachment) && $attachmentCount < 1) {
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

        if (! (bool) $requestPolicy->allow_negative_balance && $preview->totalCountedDays > 0) {
            $year = $preview->days[0]->occursOn->format('Y');
            $available = $this->ledger->balanceFor($employee->getKey(), $leaveType->getKey(), (int) $year);
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
}
