<?php

namespace App\Modules\People\Attendance\Models;

use App\Base\Database\Concerns\BelongsToCompany;
use App\Base\Database\Concerns\HasActiveInactiveStatus;
use App\Base\Database\Concerns\HasEffectiveDateRange;
use App\Base\Database\Concerns\TracksExternalSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceShiftTemplate extends Model
{
    use BelongsToCompany;
    use HasActiveInactiveStatus;
    use HasEffectiveDateRange;
    use TracksExternalSource;

    protected $table = 'people_attendance_shift_templates';

    protected $fillable = [
        ...self::COMPANY_FILLABLE,
        'code',
        'name',
        'starts_at',
        'ends_at',
        'crosses_midnight',
        'expected_work_minutes',
        'break_windows',
        'payroll_attribution',
        ...self::EFFECTIVE_DATE_RANGE_FILLABLE,
        'status',
        ...self::EXTERNAL_SOURCE_FILLABLE,
    ];

    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'bool',
            'expected_work_minutes' => 'integer',
            'break_windows' => 'array',
            ...self::EFFECTIVE_DATE_RANGE_CASTS,
            ...self::EXTERNAL_SOURCE_CASTS,
        ];
    }

    public function punchWindows(): HasMany
    {
        return $this->hasMany(AttendancePunchWindow::class, 'attendance_shift_template_id');
    }
}
