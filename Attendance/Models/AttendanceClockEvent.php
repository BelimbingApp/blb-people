<?php

namespace App\Modules\People\Attendance\Models;

use App\Base\Database\Concerns\BelongsToCompanyAndEmployee;
use App\Modules\People\Attendance\Models\Concerns\BelongsToAttendanceDay;
use Illuminate\Database\Eloquent\Model;

class AttendanceClockEvent extends Model
{
    use BelongsToAttendanceDay;
    use BelongsToCompanyAndEmployee;

    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    public const TYPE_BREAK_OUT = 'break_out';

    public const TYPE_BREAK_IN = 'break_in';

    public const SOURCE_WEB = 'web_clock';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_APP = 'app_clock';

    public const SOURCE_IMPORT = 'import';

    protected $table = 'people_attendance_clock_events';

    protected $fillable = [
        ...self::COMPANY_EMPLOYEE_FILLABLE,
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
}
