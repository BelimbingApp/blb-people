<?php

namespace App\Domains\People\Progression\Models;

use App\Domains\People\Progression\Enums\ProgressionPolicyStatus;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

/**
 * One version of a company's progression policy: the identity and rules a
 * later eligibility computation would pin its snapshot to (plan 0004). A row
 * is drafted, published once, and superseded when the next version of the
 * same policy_id is published. Nothing here decides eligibility or pay.
 *
 * Company ownership uses the Skills guard pattern (docs/contracts in the
 * connector, plan 0001): every query must pin the company axis or it refuses.
 */
class ProgressionPolicy extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_progression_policies';

    protected function casts(): array
    {
        return [
            'status' => ProgressionPolicyStatus::class,
            'effective_from' => 'date',
            'rules' => 'array',
            'published_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === ProgressionPolicyStatus::Draft;
    }
}
