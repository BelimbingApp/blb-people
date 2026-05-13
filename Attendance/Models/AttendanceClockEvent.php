<?php

namespace App\Modules\People\Attendance\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceClockEvent extends Model
{
    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    public const TYPE_BREAK_OUT = 'break_out';

    public const TYPE_BREAK_IN = 'break_in';

    public const SOURCE_WEB = 'web_clock';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_APP = 'app_clock';

    public const SOURCE_IMPORT = 'import';

    protected $table = 'attendance_clock_events';

    protected $fillable = [
        'company_id',
        'employee_id',
        'attendance_day_id',
        'attendance_geofence_id',
        'attendance_geofence_group_id',
        'event_type',
        'occurred_at',
        'timezone',
        'source',
        'actor_user_id',
        'card_number',
        'device_identifier',
        'outlet_label',
        'ip_address',
        'latitude',
        'longitude',
        'geofence_result',
        'photo_evidence_present',
        'corrects_clock_event_id',
        'source_system',
        'source_label',
        'source_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'photo_evidence_present' => 'bool',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function attendanceDay(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }
}
