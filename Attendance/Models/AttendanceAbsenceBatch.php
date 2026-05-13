<?php

namespace App\Modules\People\Attendance\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceAbsenceBatch extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_FINALIZED = 'finalized';

    protected $table = 'attendance_absence_batches';

    protected $fillable = [
        'company_id',
        'reference',
        'status',
        'period_starts_on',
        'period_ends_on',
        'lock_date',
        'filters',
        'created_by_user_id',
        'generated_at',
        'finalized_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'lock_date' => 'date',
            'filters' => 'array',
            'generated_at' => 'datetime',
            'finalized_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AttendanceAbsenceBatchEntry::class, 'attendance_absence_batch_id');
    }
}
