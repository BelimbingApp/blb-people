<?php

namespace App\Modules\People\Claim\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClaimPolicy extends Model
{
    public const MODE_SINGLE_VALUE = 'single_value';
    public const MODE_RANGE = 'range';
    public const MODE_SERVICE_YEAR = 'service_year';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'people_claim_policies';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'item_mode',
        'auto_calculated',
        'rate_type',
        'cohort_predicate',
        'receipt_rules',
        'provider_rules',
        'currency_rules',
        'advance_rules',
        'approval_profile_key',
        'encumber_pending',
        'effective_from',
        'effective_to',
        'version',
        'status',
        'source_system',
        'source_label',
        'source_code',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'auto_calculated' => 'bool',
            'cohort_predicate' => 'array',
            'receipt_rules' => 'array',
            'provider_rules' => 'array',
            'currency_rules' => 'array',
            'advance_rules' => 'array',
            'encumber_pending' => 'bool',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'version' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** @return HasMany<ClaimPolicyBand, $this> */
    public function bands(): HasMany
    {
        return $this->hasMany(ClaimPolicyBand::class, 'claim_policy_id')->orderBy('sort_order');
    }
}
