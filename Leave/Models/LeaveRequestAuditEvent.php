<?php

namespace App\Modules\People\Leave\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequestAuditEvent extends Model
{
    public $timestamps = false;

    protected $table = 'people_leave_request_audit_events';

    /** @var list<string> */
    protected $fillable = [
        'leave_request_id',
        'from_status',
        'to_status',
        'actor_user_id',
        'reason',
        'occurred_at',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }
}
