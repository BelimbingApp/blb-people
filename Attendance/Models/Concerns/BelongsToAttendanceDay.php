<?php

namespace App\Domains\People\Attendance\Models\Concerns;

use App\Domains\People\Attendance\Models\AttendanceDay;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToAttendanceDay
{
    public function attendanceDay(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }
}
