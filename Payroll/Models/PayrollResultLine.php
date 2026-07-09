<?php

namespace App\Modules\People\Payroll\Models;

use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollResultLine extends Model
{
    public const TYPE_EARNING = 'earning';

    public const TYPE_EMPLOYEE_DEDUCTION = 'employee_deduction';

    public const TYPE_EMPLOYEE_CONTRIBUTION = 'employee_contribution';

    public const TYPE_EMPLOYER_CONTRIBUTION = 'employer_contribution';

    public const TYPE_EMPLOYER_LEVY = 'employer_levy';

    public const TYPE_TAX = 'tax';

    public const TYPE_REIMBURSEMENT = 'reimbursement';

    public const TYPE_NET_PAY = 'net_pay';

    public const TYPE_INFORMATIONAL = 'informational';

    protected $table = 'people_payroll_result_lines';

    protected static function booted(): void
    {
        static::saving(function (PayrollResultLine $line): void {
            $line->runForMutationGuard()?->assertMutable();
        });

        static::deleting(function (PayrollResultLine $line): void {
            $line->runForMutationGuard()?->assertMutable();
        });
    }

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'payroll_run_id',
        'payroll_run_participant_id',
        'employee_id',
        'payroll_input_id',
        'line_type',
        'code',
        'label',
        'amount',
        'currency',
        'source_rule',
        'source_version',
        'explanation',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'explanation' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(PayrollRunParticipant::class, 'payroll_run_participant_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function input(): BelongsTo
    {
        return $this->belongsTo(PayrollInput::class, 'payroll_input_id');
    }

    private function runForMutationGuard(): ?PayrollRun
    {
        return $this->relationLoaded('run')
            ? $this->run
            : PayrollRun::query()->find($this->payroll_run_id);
    }
}
