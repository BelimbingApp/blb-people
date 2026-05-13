<?php

namespace App\Modules\People\Attendance\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Payroll\Models\PayrollInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AttendancePayrollHandoff extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_REVERSED = 'reversed';

    protected $table = 'people_attendance_payroll_handoffs';

    protected $fillable = [
        'company_id',
        'employee_id',
        'source_type',
        'source_id',
        'payroll_input_id',
        'pay_item_code',
        'input_type',
        'quantity',
        'amount',
        'occurred_on',
        'payroll_period_date',
        'status',
        'transformation_snapshot',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'amount' => 'decimal:2',
            'occurred_on' => 'date',
            'payroll_period_date' => 'date',
            'transformation_snapshot' => 'array',
            'metadata' => 'array',
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

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function payrollInput(): BelongsTo
    {
        return $this->belongsTo(PayrollInput::class, 'payroll_input_id');
    }
}
