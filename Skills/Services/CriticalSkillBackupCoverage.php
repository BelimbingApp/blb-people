<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Department;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\Skill;
use Illuminate\Support\Collection;

/**
 * Whether each critical skill in a department has enough cover to survive one
 * person being away (0007-c).
 *
 * "Cover" is the narrow reading 0007-b established and this service now owns:
 * at or above the required level, not lapsed, in this company — and, added
 * here, in this department. Each of those is a way something can look like
 * cover and not be. A colleague in another department may be excellent and is
 * still not who the shift calls at 2am.
 *
 * The minimum is a setting rather than a constant because two is a sensible
 * default and a poor rule for a team of three.
 */
final class CriticalSkillBackupCoverage
{
    public const MINIMUM_SETTING = 'people-skills.backup_minimum';

    /** Enough people that one of them can be away. */
    public const DEFAULT_MINIMUM = 2;

    /**
     * One row per department and critical skill, worst cover first.
     *
     * A skill nobody has been assessed against does not appear: this reports
     * on cover that was measured, and inventing a zero row for every skill in
     * the catalogue would bury the ones that were.
     *
     * @param  int|null  $departmentId  one department (a head of department's
     *                                  view), or null for every department
     * @return list<array{department_id: int|null, department: string, skill_id: int, skill: string, required_level: int, holders: int, minimum: int, covered: bool}>
     */
    public function rows(int $tenantId, int $companyEntityId, ?int $departmentId = null): array
    {
        $scores = EmployeeSkillScore::query()->forCompany($tenantId, $companyEntityId)
            ->where('criticality', RequirementCriticality::Critical->value)
            ->get();

        if ($scores->isEmpty()) {
            return [];
        }

        $departmentOf = $this->departmentByEmployee($scores);
        $minimum = $this->minimum($tenantId);
        $today = now()->toDateString();
        $rows = [];

        $groups = $scores->groupBy(fn (EmployeeSkillScore $score): string => ($departmentOf[(int) $score->employee_entity_id] ?? 'none').':'.$score->skill_id);

        foreach ($groups as $group) {
            $first = $group->first();
            $employeeDepartment = $departmentOf[(int) $first->employee_entity_id] ?? null;

            if ($departmentId !== null && $employeeDepartment !== $departmentId) {
                continue;
            }

            $holders = $group->filter(static fn (EmployeeSkillScore $score): bool => $score->coversRequirement($today))->count();

            $rows[] = [
                'department_id' => $employeeDepartment,
                'department' => $this->departmentName($employeeDepartment),
                'skill_id' => (int) $first->skill_id,
                'skill' => $this->skillName($tenantId, $companyEntityId, (int) $first->skill_id),
                // The strictest requirement anyone in the department carries:
                // if two profiles disagree, cover has to satisfy the higher.
                'required_level' => (int) $group->max('required_level'),
                'holders' => $holders,
                'minimum' => $minimum,
                'covered' => $holders >= $minimum,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['holders'], $a['department'], $a['skill']] <=> [$b['holders'], $b['department'], $b['skill']]);

        return $rows;
    }

    /**
     * @param  Collection<int, EmployeeSkillScore>  $scores
     * @return array<int, int|null>
     */
    private function departmentByEmployee(Collection $scores): array
    {
        return Employee::query()
            ->whereIn('id', $scores->pluck('employee_entity_id')->unique()->all())
            ->pluck('department_id', 'id')
            ->map(static fn (mixed $id): ?int => $id === null ? null : (int) $id)
            ->all();
    }

    /**
     * How many holders a department needs before a critical skill is covered.
     *
     * Read at the current tenant's scope, so a tenant whose teams are small
     * can set its own without arguing with the platform default.
     */
    public function minimum(?int $tenantId = null): int
    {
        $tenantId ??= app(TenantContext::class)->currentTenantId();
        $scope = $tenantId === null ? null : Scope::tenant($tenantId);
        $configured = app(SettingsService::class)->get(self::MINIMUM_SETTING, $scope);

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_MINIMUM;
    }

    private function departmentName(?int $departmentId): string
    {
        if ($departmentId === null) {
            return (string) __('No department');
        }

        return (string) (Department::query()->with('type')->find($departmentId)?->name ?? __('Unknown department'));
    }

    private function skillName(int $tenantId, int $companyEntityId, int $skillId): string
    {
        return (string) (Skill::query()->forCompany($tenantId, $companyEntityId)->find($skillId)?->name ?? __('Unknown skill'));
    }
}
