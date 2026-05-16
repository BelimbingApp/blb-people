<?php

namespace App\Modules\People\Payroll\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Claim\Models\ClaimType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a claim type to a payroll pay-item code.
 *
 * Owned by Payroll. The cross-module FK to people_claim_types.id is
 * legal because Payroll depends on Claim — not the other way around.
 *
 * Resolution: pick the row whose effective_from is the latest one not
 * after the claim line's incurred-on date. SubmitClaimRequestService
 * resolves this at submission time and snapshots the code onto the
 * ClaimLine for audit / reverse paths.
 */
class PayrollClaimTypePayItem extends Model
{
    protected $table = 'people_payroll_claim_type_pay_items';

    protected $fillable = [
        'company_id',
        'claim_type_id',
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

    public function claimType(): BelongsTo
    {
        return $this->belongsTo(ClaimType::class, 'claim_type_id');
    }
}
