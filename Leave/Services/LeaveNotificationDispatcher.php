<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Settings\Models\PeopleNotificationDeliveryLog;

class LeaveNotificationDispatcher
{
    public const EVENT_SUBMITTED = 'leave.request.submitted';
    public const EVENT_APPROVED = 'leave.request.approved';
    public const EVENT_REJECTED = 'leave.request.rejected';
    public const EVENT_CANCELLED = 'leave.request.cancelled';
    public const EVENT_APPLIED = 'leave.request.applied';
    public const EVENT_WITHDRAWN = 'leave.request.withdrawn';
    public const EVENT_LOW_BALANCE = 'leave.balance.low';
    public const EVENT_EXPIRY_APPROACHING = 'leave.balance.expiry_approaching';
    public const EVENT_YEAR_PLANNER_PUBLISHED = 'leave.year_planner.published';

    /**
     * Emit a standard leave notification through the People notification log.
     * Channel routing (email/in-app/webhook) is the log consumer's job; Leave
     * only declares intent and payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $event, LeaveRequest $request, array $payload = [], ?string $recipient = null): PeopleNotificationDeliveryLog
    {
        return PeopleNotificationDeliveryLog::query()->create([
            'company_id' => $request->company_id,
            'notifiable_type' => LeaveRequest::class,
            'notifiable_id' => $request->getKey(),
            'channel' => 'pending',
            'recipient' => $recipient,
            'subject' => $event,
            'status' => 'queued',
            'metadata' => $payload + [
                'event' => $event,
                'leave_request_id' => $request->getKey(),
                'employee_id' => $request->employee_id,
                'leave_type_id' => $request->leave_type_id,
                'starts_on' => optional($request->starts_on)->format('Y-m-d'),
                'ends_on' => optional($request->ends_on)->format('Y-m-d'),
                'quantity' => (float) $request->quantity,
                'status' => $request->status,
            ],
        ]);
    }
}
