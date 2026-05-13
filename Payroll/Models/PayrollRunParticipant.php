<?php

namespace App\Modules\People\Payroll\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRunParticipant extends Model
{
    protected $table = 'people_payroll_run_participants';

    protected static function booted(): void
    {
        static::saving(function (PayrollRunParticipant $participant): void {
            $participant->runForMutationGuard()?->assertMutable();
        });

        static::deleting(function (PayrollRunParticipant $participant): void {
            $participant->runForMutationGuard()?->assertMutable();
        });
    }

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'payroll_run_id',
        'company_id',
        'employee_id',
        'status',
        'gross_pay',
        'total_deductions',
        'total_reimbursements',
        'net_pay',
        'currency',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross_pay' => 'decimal:4',
            'total_deductions' => 'decimal:4',
            'total_reimbursements' => 'decimal:4',
            'net_pay' => 'decimal:4',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * @return HasMany<PayrollInput, $this>
     */
    public function inputs(): HasMany
    {
        return $this->hasMany(PayrollInput::class, 'payroll_run_participant_id');
    }

    /**
     * @return HasMany<PayrollResultLine, $this>
     */
    public function resultLines(): HasMany
    {
        return $this->hasMany(PayrollResultLine::class, 'payroll_run_participant_id');
    }

    /**
     * @return HasMany<PayrollPdfArtifact, $this>
     */
    public function pdfArtifacts(): HasMany
    {
        return $this->hasMany(PayrollPdfArtifact::class, 'payroll_run_participant_id');
    }

    private function runForMutationGuard(): ?PayrollRun
    {
        return $this->relationLoaded('run')
            ? $this->run
            : PayrollRun::query()->find($this->payroll_run_id);
    }
}
