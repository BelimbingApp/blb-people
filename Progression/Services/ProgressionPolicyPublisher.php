<?php

namespace App\Domains\People\Progression\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Progression\Enums\ProgressionPolicyStatus;
use App\Domains\People\Progression\Exceptions\ProgressionPolicyPublicationException;
use App\Domains\People\Progression\Models\ProgressionPolicy;
use App\Domains\People\Skills\Services\CompanyAttribution;
use Illuminate\Support\Facades\DB;

/**
 * The one write path that makes a policy version current. Livewire-free on
 * purpose: publication is an HR decision with an actor and a company, and
 * every refusal is typed so a caller cannot mistake a denial for an absence.
 *
 * Order of refusals: tenant, capability, company attribution, then the row.
 * The capability check comes before any lookup so an unauthorized caller
 * learns nothing about which policy ids exist.
 */
final readonly class ProgressionPolicyPublisher
{
    public const MANAGE = 'people.progression.policy.manage';

    public function __construct(
        private TenantContext $tenantContext,
        private AuthorizationService $authorization,
        private CompanyAttribution $companies,
    ) {}

    public function publish(User $actor, int $companyEntityId, int $policyId): ProgressionPolicy
    {
        $tenantId = $this->tenantContext->currentTenantId();
        if ($tenantId === null) {
            throw new ProgressionPolicyPublicationException('A tenant context is required to publish a progression policy.');
        }

        $this->authorization->authorize(Actor::forUser($actor), self::MANAGE);

        if (! $this->companies->mayActFor($actor, $companyEntityId)) {
            throw new ProgressionPolicyPublicationException('The actor may not publish policies for this company.');
        }

        return DB::transaction(function () use ($tenantId, $companyEntityId, $policyId, $actor): ProgressionPolicy {
            $policy = ProgressionPolicy::query()
                ->forCompany($tenantId, $companyEntityId)
                ->whereKey($policyId)
                ->lockForUpdate()
                ->first()
                ?? throw new ProgressionPolicyPublicationException('Progression policy was not found in this company.');

            if (! $policy->isDraft()) {
                throw new ProgressionPolicyPublicationException(
                    "Only a draft can be published; this version is {$policy->status->value}.",
                );
            }

            $now = now();

            ProgressionPolicy::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('policy_id', $policy->policy_id)
                ->where('status', ProgressionPolicyStatus::Published->value)
                ->update([
                    'status' => ProgressionPolicyStatus::Superseded->value,
                    'superseded_at' => $now,
                ]);

            $policy->forceFill([
                'status' => ProgressionPolicyStatus::Published,
                'published_at' => $now,
                'published_by_user_id' => $actor->getAuthIdentifier(),
            ])->save();

            return $policy->refresh();
        });
    }
}
