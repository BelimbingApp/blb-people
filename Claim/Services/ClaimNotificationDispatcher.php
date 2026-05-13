<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Settings\Models\PeopleNotificationDeliveryLog;

class ClaimNotificationDispatcher
{
    public const EVENT_SUBMITTED = 'claim.request.submitted';
    public const EVENT_APPROVED = 'claim.request.approved';
    public const EVENT_REJECTED = 'claim.request.rejected';
    public const EVENT_MORE_INFO = 'claim.request.more_info_requested';
    public const EVENT_WITHDRAWN = 'claim.request.withdrawn';
    public const EVENT_PAYROLL_QUEUED = 'claim.request.payroll_queued';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $event, ClaimRequest $request, array $payload = [], ?string $recipient = null): PeopleNotificationDeliveryLog
    {
        $request->loadMissing(['employee', 'lines']);

        return PeopleNotificationDeliveryLog::query()->create([
            'company_id' => $request->company_id,
            'notifiable_type' => ClaimRequest::class,
            'notifiable_id' => $request->getKey(),
            'channel' => 'pending',
            'recipient' => $recipient ?? $request->employee?->email,
            'subject' => $event,
            'status' => 'queued',
            'metadata' => $payload + [
                'event' => $event,
                'claim_request_id' => $request->getKey(),
                'reference_number' => $request->reference_number,
                'employee_id' => $request->employee_id,
                'status' => $request->status,
                'currency' => $request->currency,
                'requested_amount' => (float) $request->requested_amount,
                'approved_amount' => (float) $request->approved_amount,
                'line_count' => $request->lines->count(),
            ],
        ]);
    }
}
