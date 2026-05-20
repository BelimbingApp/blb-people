<?php

namespace App\Modules\People\Attendance\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRosterLock extends Model
{
    protected $table = 'people_attendance_roster_locks';

    protected $fillable = [
        'company_id',
        'period_start',
        'period_end',
        'locked_by',
        'locked_at',
        'unlock_reason',
        'unlocked_by',
        'unlocked_at',
    ];

    protected $casts = [
        'locked_at' => 'datetime',
        'unlocked_at' => 'datetime',
    ];

    public function isLocked(): bool
    {
        return $this->unlocked_at === null;
    }
}
