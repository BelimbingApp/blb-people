<?php

namespace App\Modules\People\Attendance\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceShiftTemplate extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'attendance_shift_templates';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'starts_at',
        'ends_at',
        'crosses_midnight',
        'expected_work_minutes',
        'break_windows',
        'day_type_overrides',
        'payroll_attribution',
        'effective_from',
        'effective_to',
        'status',
        'source_system',
        'source_label',
        'source_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'bool',
            'expected_work_minutes' => 'integer',
            'break_windows' => 'array',
            'day_type_overrides' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function punchWindows(): HasMany
    {
        return $this->hasMany(AttendancePunchWindow::class, 'attendance_shift_template_id');
    }
}
