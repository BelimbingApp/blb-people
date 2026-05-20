<?php

namespace App\Modules\People\Attendance\Models;

use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRosterAcknowledgment extends Model
{
    protected $table = 'people_attendance_roster_acknowledgments';

    protected $fillable = [
        'company_id',
        'employee_id',
        'actor_id',
        'period_start',
        'period_end',
        'acknowledged_at',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
