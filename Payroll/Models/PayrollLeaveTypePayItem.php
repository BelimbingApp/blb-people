<?php

namespace App\Modules\People\Payroll\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Leave\Models\LeaveType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a leave type to a payroll pay-item code.
 *
 * Owned by Payroll. The cross-module FK to people_leave_types.id is
 * legal because Payroll depends on Leave — not the other way around.
 *
 * Resolution: the listener picks the row whose effective_from is the
 * latest one not after the leave's occurred-on date.
 */
class PayrollLeaveTypePayItem extends Model
{
    protected $table = 'people_payroll_leave_type_pay_items';

    protected $fillable = [
        'company_id',
        'leave_type_id',
        'payroll_pay_item_code',
        'effective_from',
        'effective_to',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
