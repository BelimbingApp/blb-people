<?php

namespace App\Modules\People\Claim\Models;

use App\Base\Database\Concerns\HasCompanyScopedExternalLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClaimPolicy extends Model
{
    use HasCompanyScopedExternalLifecycle;

    public const MODE_SINGLE_VALUE = 'single_value';

    public const MODE_RANGE = 'range';

    public const MODE_SERVICE_YEAR = 'service_year';

    protected $table = 'people_claim_policies';

    /** @var list<string> */
    protected $fillable = [
        ...self::COMPANY_SCOPED_EXTERNAL_LIFECYCLE_FILLABLE,
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
        'version',
        'status',
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
            ...self::COMPANY_SCOPED_EXTERNAL_LIFECYCLE_CASTS,
            'version' => 'integer',
        ];
    }

    /** @return HasMany<ClaimPolicyBand, $this> */
    public function bands(): HasMany
    {
        return $this->hasMany(ClaimPolicyBand::class, 'claim_policy_id')->orderBy('sort_order');
    }
}
