<?php

namespace App\Modules\People\Leave\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequestDay extends Model
{
    public const PORTION_FULL = 'full';
    public const PORTION_AM = 'am';
    public const PORTION_PM = 'pm';
    public const PORTION_HOURS = 'hours';

    public const DAYTYPE_WORKING = 'working';
    public const DAYTYPE_HOLIDAY = 'holiday';
    public const DAYTYPE_OFF_DAY = 'off_day';
    public const DAYTYPE_REST_DAY = 'rest_day';

    protected $table = 'leave_request_days';

    /** @var list<string> */
    protected $fillable = [
        'leave_request_id',
        'occurs_on',
        'portion',
        'hours_count',
        'daytype',
        'counts_against_balance',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurs_on' => 'date',
            'hours_count' => 'decimal:2',
            'counts_against_balance' => 'bool',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }
}
