<?php

namespace App\Modules\People\Attendance\Models;

use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceAbsenceBatchEntry extends Model
{
    protected $table = 'people_attendance_absence_batch_entries';

    protected $fillable = [
        'attendance_absence_batch_id',
        'attendance_day_id',
        'employee_id',
        'absence_date',
        'day_type',
        'absence_code',
        'status',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'absence_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AttendanceAbsenceBatch::class, 'attendance_absence_batch_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
