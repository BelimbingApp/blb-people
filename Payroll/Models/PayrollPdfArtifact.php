<?php

namespace App\Modules\People\Payroll\Models;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPdfArtifact extends Model
{
    protected $table = 'payroll_pdf_artifacts';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'payroll_run_id',
        'payroll_run_participant_id',
        'employee_id',
        'report_type',
        'disk',
        'path',
        'template_version',
        'data_version',
        'bytes',
        'sha256',
        'produced_by',
        'produced_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'produced_at' => 'datetime',
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

    public function producer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'produced_by');
    }
}
