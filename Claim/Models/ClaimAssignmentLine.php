<?php

namespace App\Modules\People\Claim\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimAssignmentLine extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'people_claim_assignment_lines';

    /** @var list<string> */
    protected $fillable = [
        'claim_assignment_id',
        'claim_type_id',
        'claim_policy_id',
        'combine_tag',
        'uses_combined_cap',
        'hidden_from_application',
        'sort_order',
        'status',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'uses_combined_cap' => 'bool',
            'hidden_from_application' => 'bool',
            'sort_order' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ClaimAssignment::class, 'claim_assignment_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ClaimType::class, 'claim_type_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ClaimPolicy::class, 'claim_policy_id');
    }
}
