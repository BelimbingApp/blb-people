<?php

namespace App\Modules\People\Attendance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePunchWindow extends Model
{
    public const TYPE_IN = 'in';

    public const TYPE_BREAK_OUT = 'break_out';

    public const TYPE_BREAK_IN = 'break_in';

    public const TYPE_OUT = 'out';

    protected $table = 'attendance_punch_windows';

    protected $fillable = [
        'attendance_shift_template_id',
        'event_type',
        'expected_at',
        'earliest_at',
        'latest_at',
        'required',
        'exception_on_unmatched',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'bool',
            'exception_on_unmatched' => 'bool',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(AttendanceShiftTemplate::class, 'attendance_shift_template_id');
    }
}
