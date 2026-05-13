<?php

namespace App\Modules\People\Payroll\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable record of a payroll contribution submitted via PayrollContributionIntake.
 *
 * One row exists per unique (source_type, source_id, pay_item_code, period_anchor)
 * tuple. Lifecycle:
 *  - pending:           no open run yet, awaiting materializer
 *  - queued_in_run:     materialized into a draft PayrollInput
 *  - calculated:        run has progressed past draft
 *  - closed/voided:     run is locked
 *  - reversed:          producer asked to reverse
 *  - rejected_locked:   targeted a locked run and could not be materialized
 */
class PayrollPendingContribution extends Model
{
    protected $table = 'payroll_pending_contributions';

    /** @var array<int, string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'source_type',
        'source_id',
        'pay_item_code',
        'period_anchor',
        'occurred_on',
        'input_type',
        'currency',
        'amount',
        'quantity',
        'rate',
        'label',
        'accounting_snapshot',
        'state',
        'payroll_input_id',
        'reason',
        'materialized_at',
        'reversed_at',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_anchor' => 'date',
            'occurred_on' => 'date',
            'amount' => 'decimal:4',
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'accounting_snapshot' => 'array',
            'materialized_at' => 'datetime',
            'reversed_at' => 'datetime',
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

    public function payrollInput(): BelongsTo
    {
        return $this->belongsTo(PayrollInput::class, 'payroll_input_id');
    }

    public function isMaterialized(): bool
    {
        return $this->payroll_input_id !== null
            && in_array($this->state, [
                PayrollContributionState::QUEUED_IN_RUN,
                PayrollContributionState::CALCULATED,
                PayrollContributionState::CLOSED,
                PayrollContributionState::VOIDED,
            ], true);
    }
}
