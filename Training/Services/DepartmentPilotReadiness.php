<?php

namespace App\Domains\People\Training\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Skills\Enums\DevelopmentActionClosure;
use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Models\DevelopmentAction;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\RequirementItem;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Models\RequirementProfileSelector;
use App\Domains\People\Skills\Models\SkillAssessorAssignment;
use App\Domains\People\Skills\Services\DepartmentHeads;
use App\Domains\People\Skills\Services\RequirementResolver;

/**
 * Per-department pilot readiness for the five-department cutover (0015-c).
 *
 * Read-only: each row is a named check with red/green status, a count, and
 * drill-down ids. Sign-off stores snapshot these rows; nothing here writes.
 */
final class DepartmentPilotReadiness
{
    /** @var list<string> */
    private const OPEN_CLOSURES = [
        DevelopmentActionClosure::Open->value,
        DevelopmentActionClosure::PendingReassessment->value,
        DevelopmentActionClosure::FurtherActionRequired->value,
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly RequirementResolver $requirements,
        private readonly DepartmentHeads $heads,
    ) {}

    /**
     * @return list<array{
     *   check: string,
     *   status: 'red'|'green',
     *   count: int,
     *   ids: list<int>,
     *   with_open_action?: int
     * }>
     */
    public function rows(int $companyEntityId, int $organizationUnitEntityId): array
    {
        $tenantId = $this->tenants->requireTenantId();
        $profile = $this->publishedProfileForUnit($tenantId, $companyEntityId, $organizationUnitEntityId);
        $employeeIds = $this->employeeIdsInUnit($companyEntityId, $organizationUnitEntityId);
        $skillIds = $profile === null
            ? []
            : $this->requiredSkillIds($tenantId, $companyEntityId, (int) $profile->id);

        return [
            $this->publishedProfileRow($profile),
            $this->assessorsRow($tenantId, $companyEntityId, $skillIds),
            $this->gapsRow($tenantId, $companyEntityId, $organizationUnitEntityId, $employeeIds, $profile),
            $this->actionOwnersRow($tenantId, $companyEntityId, $employeeIds),
            $this->departmentHeadRow($companyEntityId, $employeeIds),
        ];
    }

    /** @return list<int> */
    private function employeeIdsInUnit(int $companyEntityId, int $organizationUnitEntityId): array
    {
        $employeeIds = EmployeeWorkProfile::query()
            ->where('organization_unit_id', $organizationUnitEntityId)
            ->pluck('employee_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($employeeIds === []) {
            return [];
        }

        return Employee::query()
            ->where('company_id', $companyEntityId)
            ->where('status', 'active')
            ->whereIn('id', $employeeIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function publishedProfileForUnit(
        int $tenantId,
        int $companyEntityId,
        int $organizationUnitEntityId,
    ): ?RequirementProfile {
        $profileIds = RequirementProfileSelector::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('selector_type', SelectorType::Department->value)
            ->where('selector_entity_id', $organizationUnitEntityId)
            ->pluck('profile_id');

        if ($profileIds->isEmpty()) {
            return null;
        }

        return RequirementProfile::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereIn('id', $profileIds)
            ->where('status', RequirementProfileStatus::Published->value)
            ->whereNotNull('published_at')
            ->orderByDesc('version')
            ->first();
    }

    /** @return list<int> */
    private function requiredSkillIds(int $tenantId, int $companyEntityId, int $profileId): array
    {
        return RequirementItem::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('profile_id', $profileId)
            ->where('active', true)
            ->pluck('skill_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{check: string, status: 'red'|'green', count: int, ids: list<int>}
     */
    private function publishedProfileRow(?RequirementProfile $profile): array
    {
        if ($profile === null) {
            return ['check' => 'published_profile', 'status' => 'red', 'count' => 0, 'ids' => []];
        }

        return [
            'check' => 'published_profile',
            'status' => 'green',
            'count' => 1,
            'ids' => [(int) $profile->id],
        ];
    }

    /**
     * Each required skill needs at least one active assessor assignment in
     * this company. Assignments are employee-scoped; company pin is what
     * keeps a sibling company's assessor from greening the row.
     *
     * @param  list<int>  $skillIds
     * @return array{check: string, status: 'red'|'green', count: int, ids: list<int>}
     */
    private function assessorsRow(int $tenantId, int $companyEntityId, array $skillIds): array
    {
        if ($skillIds === []) {
            return ['check' => 'assessors', 'status' => 'red', 'count' => 0, 'ids' => []];
        }

        $now = now();
        $hasActive = SkillAssessorAssignment::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('effective_from', '<=', $now)
            ->whereRaw('(effective_to is null or effective_to > ?)', [$now])
            ->exists();

        if ($hasActive) {
            return ['check' => 'assessors', 'status' => 'green', 'count' => 0, 'ids' => []];
        }

        return [
            'check' => 'assessors',
            'status' => 'red',
            'count' => count($skillIds),
            'ids' => array_values($skillIds),
        ];
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array{check: string, status: 'red'|'green', count: int, ids: list<int>, with_open_action: int}
     */
    private function gapsRow(
        int $tenantId,
        int $companyEntityId,
        int $organizationUnitEntityId,
        array $employeeIds,
        ?RequirementProfile $profile,
    ): array {
        if ($profile === null || $employeeIds === []) {
            return [
                'check' => 'gaps',
                'status' => 'green',
                'count' => 0,
                'ids' => [],
                'with_open_action' => 0,
            ];
        }

        $scores = EmployeeSkillScore::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereIn('employee_entity_id', $employeeIds)
            ->get()
            ->keyBy(fn (EmployeeSkillScore $score): string => $score->employee_entity_id.':'.$score->skill_id);

        $gapped = [];
        foreach ($employeeIds as $employeeId) {
            $required = $this->requirements->requirementsFor([
                'company_entity_id' => $companyEntityId,
                'department_entity_id' => $organizationUnitEntityId,
                'employee_entity_id' => $employeeId,
            ]);

            foreach ($required as $requirement) {
                $score = $scores->get($employeeId.':'.$requirement->skillId);
                if ($score === null || (int) $score->current_level < $requirement->requiredLevel) {
                    $gapped[$employeeId] = true;
                    break;
                }
            }
        }

        $gappedIds = array_map('intval', array_keys($gapped));
        $withOpenAction = 0;
        if ($gappedIds !== []) {
            $covered = DevelopmentAction::query()
                ->forCompany($tenantId, $companyEntityId)
                ->whereIn('employee_entity_id', $gappedIds)
                ->whereIn('closure_status', self::OPEN_CLOSURES)
                ->pluck('employee_entity_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique();
            $withOpenAction = $covered->intersect($gappedIds)->count();
        }

        // Green when every gap already has an open development action (or there
        // are no gaps). Red otherwise — the HOD still owes ownership of the gap.
        $status = $gappedIds === [] || $withOpenAction === count($gappedIds) ? 'green' : 'red';

        return [
            'check' => 'gaps',
            'status' => $status,
            'count' => count($gappedIds),
            'ids' => array_values($gappedIds),
            'with_open_action' => $withOpenAction,
        ];
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array{check: string, status: 'red'|'green', count: int, ids: list<int>}
     */
    private function actionOwnersRow(int $tenantId, int $companyEntityId, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return ['check' => 'action_owners', 'status' => 'green', 'count' => 0, 'ids' => []];
        }

        $open = DevelopmentAction::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereIn('employee_entity_id', $employeeIds)
            ->whereIn('closure_status', self::OPEN_CLOSURES)
            ->get(['id', 'owner_employee_entity_id']);

        $companyEmployeeIds = Employee::query()
            ->where('company_id', $companyEntityId)
            ->whereIn('id', $open->pluck('owner_employee_entity_id')->filter()->all())
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->flip();

        $ownerless = $open->filter(static function (DevelopmentAction $action) use ($companyEmployeeIds): bool {
            $ownerId = $action->owner_employee_entity_id;

            return $ownerId === null || ! $companyEmployeeIds->has((int) $ownerId);
        });

        $ids = $ownerless->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();

        return [
            'check' => 'action_owners',
            'status' => $ids === [] ? 'green' : 'red',
            'count' => count($ids),
            'ids' => $ids,
        ];
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array{check: string, status: 'red'|'green', count: int, ids: list<int>}
     */
    private function departmentHeadRow(int $companyEntityId, array $employeeIds): array
    {
        $headUserIds = [];
        foreach ($employeeIds as $employeeId) {
            $head = $this->heads->headUserOf($companyEntityId, $employeeId);
            if ($head !== null) {
                $headUserIds[$head] = true;
            }
        }

        $ids = array_map('intval', array_keys($headUserIds));

        return [
            'check' => 'department_head',
            'status' => $ids === [] ? 'red' : 'green',
            'count' => count($ids),
            'ids' => array_values($ids),
        ];
    }
}
