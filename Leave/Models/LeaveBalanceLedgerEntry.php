<?php

namespace App\Modules\People\Leave\Models;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Leave\Exceptions\LeaveLedgerImmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalanceLedgerEntry extends Model
{
    public const ENTRY_OPENING = 'opening';
    public const ENTRY_ACCRUAL = 'accrual';
    public const ENTRY_TAKEN = 'taken';
    public const ENTRY_CANCELLED = 'cancelled';
    public const ENTRY_ADJUSTED = 'adjusted';
    public const ENTRY_CARRIED_FORWARD = 'carried_forward';
    public const ENTRY_EXPIRED = 'expired';
    public const ENTRY_ENCASHED = 'encashed';

    public const SOURCE_LEAVE_REQUEST = 'leave_request';
    public const SOURCE_ENTITLEMENT_RUN = 'entitlement_run';
    public const SOURCE_CARRY_FORWARD_JOB = 'carry_forward_job';
    public const SOURCE_REPLACEMENT_EARN = 'replacement_earn';
    public const SOURCE_REPLACEMENT_EXPIRY = 'replacement_expiry';
    public const SOURCE_MANUAL_ADJUSTMENT = 'manual_adjustment';
    public const SOURCE_MIGRATION = 'migration';

    public $timestamps = false;

    protected $table = 'people_leave_balance_ledger_entries';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'leave_type_id',
        'leave_year',
        'entry_type',
        'quantity',
        'unit',
        'source_type',
        'source_id',
        'entitlement_policy_id',
        'entitlement_policy_version',
        'request_policy_id',
        'request_policy_version',
        'pack_identifier',
        'pack_version',
        'occurred_on',
        'expires_on',
        'recorded_by_user_id',
        'note',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'leave_year' => 'integer',
            'quantity' => 'decimal:4',
            'occurred_on' => 'date',
            'expires_on' => 'date',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (LeaveBalanceLedgerEntry $entry): void {
            throw LeaveLedgerImmutableException::cannotUpdate();
        });

        static::deleting(function (LeaveBalanceLedgerEntry $entry): void {
            throw LeaveLedgerImmutableException::cannotDelete();
        });
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
