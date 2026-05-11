<?php
namespace App\Modules\People\Payroll\Models;

use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollInput extends Model
{
    public const TYPE_EARNING = 'earning';
    public const TYPE_DEDUCTION = 'deduction';
    public const TYPE_REIMBURSEMENT = 'reimbursement';

    protected $table = 'payroll_inputs';

    protected static function booted(): void
    {
        static::saving(function (PayrollInput $input): void {
            $input->runForMutationGuard()?->assertMutable();
        });

        static::deleting(function (PayrollInput $input): void {
            $input->runForMutationGuard()?->assertMutable();
        });
    }

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'payroll_run_id',
        'payroll_run_participant_id',
        'employee_id',
        'source_type',
        'source_id',
        'pay_item_code',
        'label',
        'input_type',
        'quantity',
        'rate',
        'amount',
        'currency',
        'occurred_on',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:4',
            'occurred_on' => 'date',
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

    private function runForMutationGuard(): ?PayrollRun
    {
        return $this->relationLoaded('run')
            ? $this->run
            : PayrollRun::query()->find($this->payroll_run_id);
    }
}
