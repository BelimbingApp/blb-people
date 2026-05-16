<?php

namespace App\Modules\People\Claim\Models;

use App\Base\Database\Concerns\BelongsToCompany;
use App\Base\Database\Concerns\HasActiveInactiveStatus;
use App\Base\Database\Concerns\TracksExternalSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClaimType extends Model
{
    use BelongsToCompany;
    use HasActiveInactiveStatus;
    use TracksExternalSource;

    public const UNIT_AMOUNT = 'amount';

    public const UNIT_DISTANCE = 'distance';

    public const UNIT_QUANTITY = 'quantity';

    public const UNIT_DAYS = 'days';

    public const RECEIPT_NEVER = 'never';

    public const RECEIPT_ABOVE_AMOUNT = 'above_amount';

    public const RECEIPT_ALWAYS = 'always';

    protected $table = 'people_claim_types';

    /** @var list<string> */
    protected $fillable = [
        ...self::COMPANY_FILLABLE,
        'claim_category_id',
        'code',
        'name',
        'default_unit',
        'calculation_mode',
        'receipt_requirement',
        'provider_required',
        'payroll_eligible',
        'debit_account_code',
        'credit_account_code',
        'taxability_hint',
        'benefit_kind',
        'approval_route_key',
        'sort_order',
        'allow_employee_submission',
        'allow_on_behalf_submission',
        'admin_only',
        'advance_settlement_allowed',
        'status',
        ...self::EXTERNAL_SOURCE_FILLABLE,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider_required' => 'bool',
            'payroll_eligible' => 'bool',
            'sort_order' => 'integer',
            'allow_employee_submission' => 'bool',
            'allow_on_behalf_submission' => 'bool',
            'admin_only' => 'bool',
            'advance_settlement_allowed' => 'bool',
            ...self::EXTERNAL_SOURCE_CASTS,
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ClaimCategory::class, 'claim_category_id');
    }

    /** @return HasMany<ClaimAssignmentLine, $this> */
    public function assignmentLines(): HasMany
    {
        return $this->hasMany(ClaimAssignmentLine::class, 'claim_type_id');
    }
}
