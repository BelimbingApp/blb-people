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
use App\Domains\People\Skills\Models\SkillCertification;
use App\Domains\People\Skills\Models\SkillCertificationSkill;
use DateTimeImmutable;
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
     * @param  \DateTimeInterface|null  $asOf  the day cover is measured on: a
     *                                         certificate lapsed before it is
     *                                         not cover (0009-i asks per run)
     * @return list<array{department_id: int|null, department: string, skill_id: int, skill: string, required_level: int, holders: int, minimum: int, covered: bool}>
     */
    public function rows(int $tenantId, int $companyEntityId, ?int $departmentId = null, ?\DateTimeInterface $asOf = null): array
    {
        $scores = EmployeeSkillScore::query()->forCompany($tenantId, $companyEntityId)
            ->where('criticality', RequirementCriticality::Critical->value)
            ->get();

        if ($scores->isEmpty()) {
            return [];
        }

        $departmentOf = $this->departmentByEmployee($scores);
        $skillNames = $this->skillNames($tenantId, $companyEntityId, $scores);
        $departmentNames = $this->departmentNames($departmentOf);
        $minimum = $this->minimum($tenantId);
        $today = ($asOf ?? now())->format('Y-m-d');
        $certificationHolders = $this->certificationHolders(
            $tenantId,
            $companyEntityId,
            $departmentOf,
            $today,
        );
        $rows = [];

        $groups = $scores->groupBy(fn (EmployeeSkillScore $score): string => ($departmentOf[(int) $score->employee_entity_id] ?? 'none').':'.$score->skill_id);

        foreach ($groups as $group) {
            $first = $group->first();
            $employeeDepartment = $departmentOf[(int) $first->employee_entity_id] ?? null;

            if ($departmentId !== null && $employeeDepartment !== $departmentId) {
                continue;
            }

            // The strictest requirement anyone in the department carries. If two
            // roles disagree — an operator at 3, a supervisor at 5 — cover has
            // to satisfy the higher, so the count is measured against it too.
            // Somebody at 3 is doing their own job, not covering the other's.
            $requiredLevel = (int) $group->max('required_level');
            $scoreHolders = $group->filter(
                static fn (EmployeeSkillScore $score): bool => $score->coversRequirement($today, $requiredLevel)
            )->pluck('employee_entity_id')->map(intval(...))->flip()->all();
            $holders = count($scoreHolders + ($certificationHolders[($departmentOf[(int) $first->employee_entity_id] ?? 'none').':'.$first->skill_id] ?? []));

            $rows[] = [
                'department_id' => $employeeDepartment,
                'department' => $departmentNames[$employeeDepartment] ?? (string) __('No department'),
                'skill_id' => (int) $first->skill_id,
                'skill' => $skillNames[(int) $first->skill_id] ?? (string) __('Unknown skill'),
                'required_level' => $requiredLevel,
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

    /**
     * @param  array<int, int|null>  $departmentOf
     * @return array<int, string>
     */
    private function departmentNames(array $departmentOf): array
    {
        $ids = array_values(array_unique(array_filter($departmentOf, static fn (?int $id): bool => $id !== null)));

        if ($ids === []) {
            return [];
        }

        return Department::query()->with('type')->whereIn('id', $ids)->get()
            ->mapWithKeys(static fn (Department $department): array => [
                (int) $department->id => (string) ($department->name ?? __('Unknown department')),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, EmployeeSkillScore>  $scores
     * @return array<int, string>
     */
    private function skillNames(int $tenantId, int $companyEntityId, Collection $scores): array
    {
        return Skill::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('id', $scores->pluck('skill_id')->unique()->all())
            ->pluck('name', 'id')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();
    }

    /**
     * Current, explicitly mapped certifications supplement a score holder.
     * They only participate for a skill already represented by a critical
     * score group: a certificate is qualification evidence, not a way to
     * invent a critical requirement that the requirement/score projection did
     * not establish. Multiple records for one employee and skill count once.
     *
     * @param  array<int, int|null>  $departmentOf
     * @return array<string, array<int, bool>>
     */
    private function certificationHolders(
        int $tenantId,
        int $companyEntityId,
        array $departmentOf,
        string $today,
    ): array {
        $certifications = SkillCertification::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereDate('expires_on', '>=', $today)
            ->get()
            ->merge(SkillCertification::query()
                ->forCompany($tenantId, $companyEntityId)
                ->whereNull('expires_on')
                ->get());

        if ($certifications->isEmpty()) {
            return [];
        }

        $superseded = $certifications->pluck('supersedes_certification_id')
            ->filter()
            ->map(intval(...))
            ->flip();
        $certificationIds = $certifications->pluck('id')->map(intval(...))->all();
        $asOf = DateTimeImmutable::createFromFormat('!Y-m-d', $today) ?: new DateTimeImmutable('today');
        $holders = [];

        $mappings = SkillCertificationSkill::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereIn('certification_id', $certificationIds)
            ->get(['certification_id', 'skill_id']);

        foreach ($mappings as $mapping) {
            $certification = $certifications->firstWhere('id', (int) $mapping->certification_id);

            if ($certification === null
                || $superseded->has((int) $certification->id)
                || ! $certification->isCurrent($asOf)
                || ! array_key_exists((int) $certification->employee_entity_id, $departmentOf)) {
                continue;
            }

            $department = $departmentOf[(int) $certification->employee_entity_id] ?? 'none';
            $key = $department.':'.$mapping->skill_id;
            $holders[$key][(int) $certification->employee_entity_id] = true;
        }

        return $holders;
    }
}
