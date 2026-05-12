<?php

namespace App\Modules\People\Leave\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequestPolicy extends Model
{
    protected $table = 'leave_request_policies';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'leave_type_id',
        'code',
        'name',
        'allow_negative_balance',
        'include_pending_as_taken',
        'allow_multiple_applications_per_day',
        'no_cross_month_split',
        'compulsory_attachment',
        'exclude_holiday_from_count',
        'exclude_off_day_from_count',
        'exclude_rest_day_from_count',
        'day_of_week_unit_overrides',
        'max_days_per_application',
        'advance_notice',
        'back_date',
        'replacement_expiry',
        'effective_from',
        'effective_to',
        'version',
        'status',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allow_negative_balance' => 'bool',
            'include_pending_as_taken' => 'bool',
            'allow_multiple_applications_per_day' => 'bool',
            'no_cross_month_split' => 'bool',
            'compulsory_attachment' => 'bool',
            'exclude_holiday_from_count' => 'bool',
            'exclude_off_day_from_count' => 'bool',
            'exclude_rest_day_from_count' => 'bool',
            'day_of_week_unit_overrides' => 'array',
            'max_days_per_application' => 'decimal:4',
            'advance_notice' => 'array',
            'back_date' => 'array',
            'replacement_expiry' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'version' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
