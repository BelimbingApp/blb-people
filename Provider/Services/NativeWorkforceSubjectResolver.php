<?php

namespace App\Domains\People\Provider\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Provider\Contracts\ResolvesWorkforceSubjects;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Data\WorkforceSubjectResolution;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Provider\Enums\WorkforceSubjectRefusal;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class NativeWorkforceSubjectResolver implements ResolvesWorkforceSubjects
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function resolve(WorkforceSubject $subject): WorkforceSubjectResolution
    {
        $currentTenantId = $this->tenantContext->currentTenantId();

        if ($currentTenantId === null
            || $subject->tenantId === null
            || $subject->companyId === null
            || $subject->tenantId !== $currentTenantId
            || ! ctype_digit($subject->stableId)) {
            return WorkforceSubjectResolution::refused(WorkforceSubjectRefusal::Unknown);
        }

        $record = $this->find($subject, $currentTenantId);

        if ($record === null) {
            return WorkforceSubjectResolution::refused(WorkforceSubjectRefusal::Unknown);
        }

        $companyId = $subject->type === WorkforceResourceType::Company
            ? $record->getKey()
            : $record->getAttribute('company_id');

        if (! is_numeric($companyId)) {
            return WorkforceSubjectResolution::refused(WorkforceSubjectRefusal::Unknown);
        }

        if ((int) $companyId !== $subject->companyId) {
            return WorkforceSubjectResolution::refused(WorkforceSubjectRefusal::WrongCompany);
        }

        if ($record->getAttribute('status') !== 'active') {
            return WorkforceSubjectResolution::refused(WorkforceSubjectRefusal::Deactivated);
        }

        return WorkforceSubjectResolution::resolved($record);
    }

    private function find(WorkforceSubject $subject, int $tenantId): ?Model
    {
        $id = (int) $subject->stableId;

        return match ($subject->type) {
            WorkforceResourceType::Company => Company::query()
                ->forTenant($tenantId)
                ->find($id),
            WorkforceResourceType::Employee => Employee::query()
                ->whereHas('company', static fn (Builder $query): Builder => $query->forTenant($tenantId))
                ->find($id),
            WorkforceResourceType::OrganizationUnit => $this->findReference(
                $id,
                $tenantId,
                PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
            ),
            WorkforceResourceType::Position => $this->findReference(
                $id,
                $tenantId,
                PeopleReferenceEntry::TYPE_JOB_TITLE,
            ),
            WorkforceResourceType::User => null,
        };
    }

    private function findReference(int $id, int $tenantId, string $type): ?PeopleReferenceEntry
    {
        return PeopleReferenceEntry::query()
            ->whereKey($id)
            ->where('type', $type)
            ->whereHas('company', static fn (Builder $query): Builder => $query->forTenant($tenantId))
            ->first();
    }
}
