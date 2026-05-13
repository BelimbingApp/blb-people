<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\People\Attendance\Exceptions\AttendanceLifecycleException;
use App\Modules\People\Attendance\Models\AttendanceDay;

class AttendanceLifecycleService
{
    public function finalize(AttendanceDay $day): AttendanceDay
    {
        $this->assertMutable($day);

        if (! in_array($day->status, [
            AttendanceDay::STATUS_READY_FOR_REVIEW,
            AttendanceDay::STATUS_EXCEPTION_PENDING,
        ], true)) {
            throw AttendanceLifecycleException::notFinalizable($day->id, $day->status);
        }

        $day->forceFill([
            'status' => AttendanceDay::STATUS_FINALIZED,
            'finalized_at' => now(),
        ])->save();

        return $day;
    }

    public function lock(AttendanceDay $day): AttendanceDay
    {
        if ($day->status !== AttendanceDay::STATUS_EXPORTED_TO_PAYROLL) {
            $day->status = AttendanceDay::STATUS_LOCKED;
        }

        $day->locked_at = now();
        $day->save();

        return $day;
    }

    public function assertMutable(AttendanceDay $day): void
    {
        if ($day->locked_at !== null || $day->status === AttendanceDay::STATUS_LOCKED) {
            throw AttendanceLifecycleException::lockedDay($day->id);
        }
    }
}
