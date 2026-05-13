<?php

namespace App\Modules\People\Claim\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClaimRequest extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_NEEDS_MORE_INFO = 'needs_more_info';
    public const STATUS_RESUBMITTED = 'resubmitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_QUEUED_FOR_PAYROLL = 'queued_for_payroll';
    public const STATUS_REIMBURSED = 'reimbursed';
    public const STATUS_SETTLED = 'settled';

    protected $table = 'people_claim_requests';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'claim_assignment_id',
        'claim_context_id',
        'reference_number',
        'status',
        'currency',
        'requested_amount',
        'approved_amount',
        'reimbursed_amount',
        'approval_profile_key',
        'approval_route_snapshot',
        'strictest_line_snapshot',
        'submitted_by_user_id',
        'on_behalf_actor_user_id',
        'on_behalf_reason',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'cancelled_at',
        'withdrawn_at',
        'queued_for_payroll_at',
        'reimbursed_at',
        'decision_reason',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'reimbursed_amount' => 'decimal:2',
            'approval_route_snapshot' => 'array',
            'strictest_line_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'queued_for_payroll_at' => 'datetime',
            'reimbursed_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ClaimAssignment::class, 'claim_assignment_id');
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(ClaimContext::class, 'claim_context_id');
    }

    /** @return HasMany<ClaimLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ClaimLine::class, 'claim_request_id');
    }

    /** @return HasMany<ClaimRequestAuditEvent, $this> */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(ClaimRequestAuditEvent::class, 'claim_request_id');
    }
}
