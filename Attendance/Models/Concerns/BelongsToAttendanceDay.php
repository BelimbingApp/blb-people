<?php

namespace App\Modules\People\Attendance\Models\Concerns;

use App\Modules\People\Attendance\Models\AttendanceDay;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToAttendanceDay
{
    public function attendanceDay(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }
}
