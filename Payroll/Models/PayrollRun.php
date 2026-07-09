<?php

namespace App\Modules\People\Payroll\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Payroll\Exceptions\ClosedPayrollRunException;
use App\Modules\People\Payroll\Services\PayrollContributionIntake;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CALCULATED = 'calculated';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_VOIDED = 'voided';

    protected $table = 'people_payroll_runs';

    protected static function booted(): void
    {
        static::created(function (PayrollRun $run): void {
            // Materialise any pending payroll contributions whose period_anchor
            // falls inside the new run's period. See docs/architecture/payroll-intake.md.
            app(PayrollContributionIntake::class)
                ->materializePendingForRun($run);
        });
    }

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'payroll_calendar_id',
        'payroll_period_id',
        'code',
        'name',
        'status',
        'currency',
        'calculated_at',
        'reviewed_at',
        'approved_at',
        'closed_at',
        'voided_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'calculated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
            'voided_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(PayrollCalendar::class, 'payroll_calendar_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    /**
     * @return HasMany<PayrollRunParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(PayrollRunParticipant::class, 'payroll_run_id');
    }

    /**
     * @return HasMany<PayrollInput, $this>
     */
    public function inputs(): HasMany
    {
        return $this->hasMany(PayrollInput::class, 'payroll_run_id');
    }

    /**
     * @return HasMany<PayrollResultLine, $this>
     */
    public function resultLines(): HasMany
    {
        return $this->hasMany(PayrollResultLine::class, 'payroll_run_id');
    }

    /**
     * @return HasMany<PayrollRunAuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(PayrollRunAuditEvent::class, 'payroll_run_id');
    }

    /**
     * @return HasMany<PayrollPdfArtifact, $this>
     */
    public function pdfArtifacts(): HasMany
    {
        return $this->hasMany(PayrollPdfArtifact::class, 'payroll_run_id');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_CLOSED, self::STATUS_VOIDED], true);
    }

    public function assertMutable(): void
    {
        if ($this->isClosed()) {
            throw new ClosedPayrollRunException($this->id, $this->status);
        }
    }

    public function markCalculated(): void
    {
        $this->assertMutable();

        $this->forceFill([
            'status' => self::STATUS_CALCULATED,
            'calculated_at' => now(),
        ])->save();
    }

    public function markReviewed(): void
    {
        $this->assertMutable();

        $this->forceFill([
            'status' => self::STATUS_REVIEWED,
            'reviewed_at' => now(),
        ])->save();

        $this->recordAuditEvent('reviewed', 'Payroll run reviewed.');
    }

    public function approve(): void
    {
        $this->assertMutable();

        $this->forceFill([
            'status' => self::STATUS_APPROVED,
            'approved_at' => now(),
        ])->save();

        $this->recordAuditEvent('approved', 'Payroll run approved.');
    }

    public function close(): void
    {
        $this->assertMutable();

        $this->forceFill([
            'status' => self::STATUS_CLOSED,
            'closed_at' => now(),
        ])->save();

        $this->recordAuditEvent('closed', 'Payroll run closed.');
    }

    public function void(): void
    {
        $this->assertMutable();

        $this->forceFill([
            'status' => self::STATUS_VOIDED,
            'voided_at' => now(),
        ])->save();

        $this->recordAuditEvent('voided', 'Payroll run voided.');
    }

    private function recordAuditEvent(string $action, string $message): void
    {
        $this->auditEvents()->create([
            'action' => $action,
            'message' => $message,
            'occurred_at' => now(),
        ]);
    }
}
