<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\People\Attendance\Exceptions\AttendanceAdjustmentException;
use App\Modules\People\Attendance\Models\AttendanceAdjustmentRequest;
use App\Modules\People\Attendance\Models\AttendanceClockEvent;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle service for missing-punch / clock-correction requests.
 *
 * Flow:
 *  - submit($request, $actorUserId): draft → submitted.
 *  - approve($request, $actorUserId, $reason): submitted → approved. On the same transaction,
 *    materializes a real `AttendanceClockEvent` via {@see ClockEventIngestionService} —
 *    `recordManualClock` for missing-punch requests, `correctClockEvent` for amendments to
 *    an existing event. The created event's id is stored on `applied_clock_event_id`.
 *  - reject($request, $actorUserId, $reason): submitted → rejected. No clock event created.
 *  - cancel($request, $actorUserId): draft|submitted → cancelled. Initiated by the requester.
 *
 * Approval is the only path that mutates attendance facts. The created clock event flows
 * through the same ingestion pipeline as web/manual entries (auto-resolves the attendance
 * day, projects metrics), so the projection picks up the corrected timing immediately.
 */
class AttendanceAdjustmentService
{
    public function __construct(
        private readonly ClockEventIngestionService $ingestion,
    ) {}

    public function submit(AttendanceAdjustmentRequest $request, int $actorUserId): AttendanceAdjustmentRequest
    {
        if ($request->status !== AttendanceAdjustmentRequest::STATUS_DRAFT) {
            throw AttendanceAdjustmentException::invalidTransition($request->id, $request->status, AttendanceAdjustmentRequest::STATUS_SUBMITTED);
        }

        $request->forceFill([
            'status' => AttendanceAdjustmentRequest::STATUS_SUBMITTED,
            'submitted_by_user_id' => $actorUserId,
            'submitted_at' => now(),
        ])->save();

        return $request;
    }

    public function approve(AttendanceAdjustmentRequest $request, int $actorUserId, ?string $decisionReason = null): AttendanceAdjustmentRequest
    {
        if (! in_array($request->status, [AttendanceAdjustmentRequest::STATUS_DRAFT, AttendanceAdjustmentRequest::STATUS_SUBMITTED], true)) {
            throw AttendanceAdjustmentException::invalidTransition($request->id, $request->status, AttendanceAdjustmentRequest::STATUS_APPROVED);
        }

        if ($request->request_mode === AttendanceAdjustmentRequest::MODE_CORRECT_EXISTING && $request->corrects_clock_event_id === null) {
            throw AttendanceAdjustmentException::correctingEventMissing($request->id);
        }

        return DB::transaction(function () use ($request, $actorUserId, $decisionReason): AttendanceAdjustmentRequest {
            $request->loadMissing(['employee', 'correctsClockEvent']);

            $clockEvent = match ($request->request_mode) {
                AttendanceAdjustmentRequest::MODE_CORRECT_EXISTING => $this->ingestion->correctClockEvent(
                    $request->correctsClockEvent,
                    $request->target_event_type,
                    $request->proposed_occurred_at,
                    $actorUserId,
                    ['metadata' => ['adjustment_request_id' => $request->id]],
                ),
                default => $this->ingestion->recordManualClock(
                    $request->employee,
                    $request->target_event_type,
                    $request->proposed_occurred_at,
                    $actorUserId,
                    ['metadata' => ['adjustment_request_id' => $request->id]],
                ),
            };

            $request->forceFill([
                'status' => AttendanceAdjustmentRequest::STATUS_APPROVED,
                'applied_clock_event_id' => $clockEvent->id,
                'attendance_day_id' => $clockEvent->attendance_day_id ?? $request->attendance_day_id,
                'approved_at' => now(),
                'decision_reason' => $decisionReason,
            ])->save();

            return $request;
        });
    }

    public function reject(AttendanceAdjustmentRequest $request, int $actorUserId, ?string $decisionReason = null): AttendanceAdjustmentRequest
    {
        if (! in_array($request->status, [AttendanceAdjustmentRequest::STATUS_DRAFT, AttendanceAdjustmentRequest::STATUS_SUBMITTED], true)) {
            throw AttendanceAdjustmentException::invalidTransition($request->id, $request->status, AttendanceAdjustmentRequest::STATUS_REJECTED);
        }

        $request->forceFill([
            'status' => AttendanceAdjustmentRequest::STATUS_REJECTED,
            'rejected_at' => now(),
            'decision_reason' => $decisionReason,
            'metadata' => $this->metadataWithDecisionActor($request, 'rejected_by_user_id', $actorUserId),
        ])->save();

        return $request;
    }

    public function cancel(AttendanceAdjustmentRequest $request, int $actorUserId): AttendanceAdjustmentRequest
    {
        if (! in_array($request->status, [AttendanceAdjustmentRequest::STATUS_DRAFT, AttendanceAdjustmentRequest::STATUS_SUBMITTED], true)) {
            throw AttendanceAdjustmentException::invalidTransition($request->id, $request->status, AttendanceAdjustmentRequest::STATUS_CANCELLED);
        }

        $request->forceFill([
            'status' => AttendanceAdjustmentRequest::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'metadata' => $this->metadataWithDecisionActor($request, 'cancelled_by_user_id', $actorUserId),
        ])->save();

        return $request;
    }

    /** @return array<string, mixed> */
    private function metadataWithDecisionActor(AttendanceAdjustmentRequest $request, string $key, int $actorUserId): array
    {
        $metadata = is_array($request->metadata) ? $request->metadata : [];
        $metadata[$key] = $actorUserId;

        return $metadata;
    }
}
