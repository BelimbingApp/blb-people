<?php

namespace App\Modules\People\Attendance\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRosterPattern extends Model
{
    public const TYPE_FIXED_WEEKLY = 'fixed_weekly';

    public const TYPE_ROTATING = 'rotating_cycle';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $table = 'people_attendance_roster_patterns';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'pattern_type',
        'pattern_definition',
        'status',
        'source_system',
        'source_label',
        'source_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'pattern_definition' => 'array',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
