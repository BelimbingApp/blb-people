<?php

namespace App\Domains\People\Progression\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Progression\Contracts\ReadsPublishedProgressionPolicy;
use App\Domains\People\Progression\Data\PublishedProgressionPolicy;
use App\Domains\People\Progression\Enums\ProgressionPolicyRefusal;
use App\Domains\People\Progression\Enums\ProgressionPolicyStatus;
use App\Domains\People\Progression\Models\ProgressionPolicy;
use App\Domains\People\Provider\Contracts\ResolvesWorkforceSubjects;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceSubjectRefusal;

/**
 * Resolves the one published policy version that applies to a subject's
 * company today: the latest effective-dated published row. The subject
 * checks are unchanged from the config-backed reader this replaces; only the
 * source of the policy moved from configuration to the publication record.
 */
final readonly class DatabasePublishedProgressionPolicyReader implements ReadsPublishedProgressionPolicy
{
    public function __construct(
        private TenantContext $tenantContext,
        private ResolvesWorkforceSubjects $subjects,
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

        // whereDate: SQLite stores date columns with a time part, so a bare
        // string compare on effective_from silently misses boundary days.
        $policy = ProgressionPolicy::query()
            ->forCompany($tenantId, $subject->companyId)
            ->where('status', ProgressionPolicyStatus::Published->value)
            ->whereDate('effective_from', '<=', today()->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('published_at')
            ->first();

        if ($policy === null) {
            return ProgressionPolicyRefusal::NoPolicyPublished;
        }

        if (trim((string) $policy->policy_id) === '' || trim((string) $policy->version) === '') {
            return ProgressionPolicyRefusal::InvalidPolicy;
        }

        return new PublishedProgressionPolicy($tenantId, $subject->companyId, (string) $policy->policy_id, (string) $policy->version);
    }
}
