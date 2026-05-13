<?php

namespace App\Modules\People\Claim\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClaimLine extends Model
{
    protected $table = 'people_claim_lines';

    /** @var list<string> */
    protected $fillable = [
        'claim_request_id',
        'claim_type_id',
        'claim_policy_id',
        'claim_assignment_line_id',
        'incurred_on',
        'description',
        'unit',
        'quantity',
        'rate',
        'requested_amount',
        'approved_amount',
        'reimbursed_amount',
        'currency',
        'provider_name',
        'receipt_number',
        'attachment_count',
        'payroll_pay_item_code',
        'debit_account_code',
        'credit_account_code',
        'adjustment_reason',
        'policy_snapshot',
        'accounting_snapshot',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'incurred_on' => 'date',
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'requested_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'reimbursed_amount' => 'decimal:2',
            'attachment_count' => 'integer',
            'policy_snapshot' => 'array',
            'accounting_snapshot' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ClaimRequest::class, 'claim_request_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ClaimType::class, 'claim_type_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ClaimPolicy::class, 'claim_policy_id');
    }

    public function assignmentLine(): BelongsTo
    {
        return $this->belongsTo(ClaimAssignmentLine::class, 'claim_assignment_line_id');
    }

    /** @return HasMany<ClaimEntitlementUsageEntry, $this> */
    public function usageEntries(): HasMany
    {
        return $this->hasMany(ClaimEntitlementUsageEntry::class, 'claim_line_id');
    }
}
