<?php

namespace App\Domains\People\Progression\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Progression\Contracts\ReadsPublishedProgressionPolicy;
use App\Domains\People\Progression\Data\PublishedProgressionPolicy;
use App\Domains\People\Progression\Enums\ProgressionPolicyRefusal;
use App\Domains\People\Provider\Contracts\ResolvesWorkforceSubjects;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceSubjectRefusal;
use Illuminate\Contracts\Config\Repository;

final readonly class ConfigPublishedProgressionPolicyReader implements ReadsPublishedProgressionPolicy
{
    public function __construct(
        private TenantContext $tenantContext,
        private ResolvesWorkforceSubjects $subjects,
        private Repository $config,
    ) {}

    public function read(WorkforceSubject $subject): PublishedProgressionPolicy|ProgressionPolicyRefusal
    {
        $tenantId = $this->tenantContext->currentTenantId();

        if ($tenantId === null || $subject->tenantId === null) {
            return ProgressionPolicyRefusal::MissingTenant;
        }

        if ($tenantId !== $subject->tenantId) {
            return ProgressionPolicyRefusal::TenantMismatch;
        }

        if ($subject->companyId === null) {
            return ProgressionPolicyRefusal::WrongCompany;
        }

        $resolution = $this->subjects->resolve($subject);
        if ($resolution->refusal !== null) {
            return match ($resolution->refusal) {
                WorkforceSubjectRefusal::WrongCompany => ProgressionPolicyRefusal::WrongCompany,
                WorkforceSubjectRefusal::Deactivated => ProgressionPolicyRefusal::DeactivatedSubject,
                WorkforceSubjectRefusal::Unknown => ProgressionPolicyRefusal::UnknownSubject,
            };
        }

        $policy = $this->config->get("people.progression.published_policies.{$tenantId}.{$subject->companyId}");
        if ($policy === null) {
            return ProgressionPolicyRefusal::NoPolicyPublished;
        }

        if (! is_array($policy)
            || ! is_string($policy['policy_id'] ?? null) || trim($policy['policy_id']) === ''
            || ! is_string($policy['version'] ?? null) || trim($policy['version']) === '') {
            return ProgressionPolicyRefusal::InvalidPolicy;
        }

        return new PublishedProgressionPolicy($tenantId, $subject->companyId, $policy['policy_id'], $policy['version']);
    }
}
