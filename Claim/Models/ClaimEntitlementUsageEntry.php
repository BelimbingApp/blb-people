<?php

namespace App\Modules\People\Claim\Models;

use App\Base\Database\Concerns\BelongsToCompany;
use App\Base\Database\Concerns\BelongsToEmployee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimEntitlementUsageEntry extends Model
{
    use BelongsToCompany;
    use BelongsToEmployee;

    public const ENTRY_OPENING = 'opening';
    public const ENTRY_ENCUMBERED = 'encumbered';
    public const ENTRY_APPROVED = 'approved';
    public const ENTRY_REIMBURSED = 'reimbursed';
    public const ENTRY_RELEASED = 'released';
    public const ENTRY_REVERSED = 'reversed';

    protected $table = 'people_claim_entitlement_usage_entries';

    /** @var list<string> */
    protected $fillable = [
        ...self::COMPANY_FILLABLE,
        ...self::EMPLOYEE_FILLABLE,
        'claim_type_id',
        'claim_policy_id',
        'claim_line_id',
        'claim_year',
        'entry_type',
        'amount',
        'currency',
        'source_type',
        'source_id',
        'occurred_on',
        'note',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'claim_year' => 'integer',
            'amount' => 'decimal:2',
            'occurred_on' => 'date',
            'metadata' => 'array',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ClaimType::class, 'claim_type_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ClaimPolicy::class, 'claim_policy_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ClaimLine::class, 'claim_line_id');
    }
}
