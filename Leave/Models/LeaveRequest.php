<?php

namespace App\Modules\People\Leave\Models;

use App\Base\Workflow\Concerns\HasWorkflowStatus;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveRequest extends Model
{
    use HasWorkflowStatus;

    public const WORKFLOW_FLOW = 'leave_application';

    public function flow(): string
    {
        return self::WORKFLOW_FLOW;
    }

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const UNIT_DAY = 'day';
    public const UNIT_HALF_DAY = 'half_day';
    public const UNIT_HOUR = 'hour';

    protected $table = 'leave_requests';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'leave_type_id',
        'leave_assignment_id',
        'leave_request_policy_id',
        'leave_request_policy_version',
        'status',
        'starts_on',
        'ends_on',
        'unit',
        'quantity',
        'attachment_count',
        'on_behalf_actor_user_id',
        'on_behalf_reason',
        'short_notice',
        'back_dated',
        'emergency_tag',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'cancelled_at',
        'applied_at',
        'withdrawn_at',
        'approval_workflow_ref',
        'rejection_reason',
        'applied_ledger_entry_id',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'quantity' => 'decimal:4',
            'short_notice' => 'bool',
            'back_dated' => 'bool',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'applied_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'attachment_count' => 'integer',
            'leave_request_policy_version' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(LeaveAssignment::class, 'leave_assignment_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(LeaveRequestDay::class)->orderBy('occurs_on');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(LeaveRequestAuditEvent::class)->orderBy('occurred_at');
    }

    public function appliedLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(LeaveBalanceLedgerEntry::class, 'applied_ledger_entry_id');
    }
}
