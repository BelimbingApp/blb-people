<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Leave\Models\LeaveRequestAuditEvent;
use Illuminate\Support\Facades\DB;

/**
 * Records on-behalf-of audit when an HR actor applies leave for an employee.
 *
 * Mirrors the audit posture EmployeeProfileChangeRequest uses: actor,
 * employee, reason, and an explicit notification side-effect handled by
 * the caller through {@see LeaveNotificationDispatcher}.
 */
class OnBehalfApplicationService
{
    public function attach(LeaveRequest $request, int $actorUserId, string $reason): LeaveRequest
    {
        return DB::transaction(function () use ($request, $actorUserId, $reason): LeaveRequest {
            $request->on_behalf_actor_user_id = $actorUserId;
            $request->on_behalf_reason = $reason;
            $request->save();

            LeaveRequestAuditEvent::query()->create([
                'leave_request_id' => $request->getKey(),
                'from_status' => $request->status,
                'to_status' => $request->status,
                'actor_user_id' => $actorUserId,
                'reason' => 'on_behalf_application: '.$reason,
                'occurred_at' => now(),
                'metadata' => [
                    'kind' => 'on_behalf_attached',
                    'employee_id' => $request->employee_id,
                ],
            ]);

            return $request;
        });
    }
}
