<?php

namespace App\Modules\People\Claim\Models;

use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimRequestAuditEvent extends Model
{
    protected $table = 'people_claim_request_audit_events';

    /** @var list<string> */
    protected $fillable = [
        'claim_request_id',
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
            'updated_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ClaimRequest::class, 'claim_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
