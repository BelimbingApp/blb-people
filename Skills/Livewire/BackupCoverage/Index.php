<?php

namespace App\Domains\People\Skills\Livewire\BackupCoverage;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Department;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Services\CriticalSkillBackupCoverage;
use App\Domains\People\Skills\Services\SkillAudience;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * How many people actually cover each critical skill, and which ones rest on
 * one person.
 *
 * "Covers" is deliberately narrow: at or above the required level, not expired,
 * in this company. Each of those is a way something can look like cover and not
 * be — a lapsed certificate is not somebody you can call at 2am, and a
 * colleague in a sibling company is not your cover either.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = 'people.skill.coverage.view';

    /**
     * Department id to narrow the per-department table to, or empty for all.
     * In the URL so a coverage-gap reminder can land on the department it
     * names (0009-i).
     */
    #[Url(as: 'department')]
    public string $department = '';

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function render(TenantContext $tenants): View
    {
        $this->authorizeView();
        $actor = Auth::user();

        $tenantId = (int) $tenants->requireTenantId();
        $companyId = (int) $actor->company_id;
        $coverage = app(CriticalSkillBackupCoverage::class);

        return view('people::livewire.backup-coverage.index', [
            'rows' => $this->rows($tenantId, $companyId),
            // 0007-c: the same question asked per department and against the
            // tenant's own minimum, rather than company-wide against two.
            'departmentRows' => $coverage->rows(
                $tenantId,
                $companyId,
                $this->department === '' ? null : (int) $this->department,
            ),
            'departments' => $this->departmentNames($companyId),
            'minimum' => $coverage->minimum($tenantId),
        ]);
    }

    /**
     * Department id => name, for the filter. Named from the department type,
     * which is where a department's name actually lives.
     *
     * @return array<int, string>
     */
    private function departmentNames(int $companyId): array
    {
        return Department::query()->where('company_id', $companyId)->with('type')->get()
            ->mapWithKeys(static fn (Department $department): array => [
                (int) $department->id => (string) ($department->name ?? __('Unnamed department')),
            ])
            ->all();
    }

    /**
     * @return list<array{skill: string, covered: int, single_point_of_failure: bool, holders: list<string>}>
     */
    private function rows(int $tenantId, int $companyId): array
    {
        $scores = EmployeeSkillScore::query()->forCompany($tenantId, $companyId)
            ->where('criticality', RequirementCriticality::Critical->value)
            ->get();

        if ($scores->isEmpty()) {
            return [];
        }

        $names = Employee::query()->whereIn('id', $scores->pluck('employee_entity_id')->unique())
            ->pluck('full_name', 'id');
        $skills = Skill::query()->forCompany($tenantId, $companyId)
            ->whereIn('id', $scores->pluck('skill_id')->unique())->pluck('name', 'id');

        $today = now()->toDateString();
        // The same minimum the per-department report uses (0007-c): one page
        // calling a team resilient while the other calls it exposed would be
        // worse than either answer alone.
        $minimum = app(CriticalSkillBackupCoverage::class)->minimum();
        $rows = [];

        foreach ($scores->groupBy('skill_id') as $skillId => $group) {
            $holders = $group
                ->filter(static fn (EmployeeSkillScore $score): bool => $score->coversRequirement($today))
                ->map(fn (EmployeeSkillScore $score): string => (string) ($names[$score->employee_entity_id] ?? __('Unknown employee')))
                ->values()
                ->all();

            $rows[] = [
                'skill' => (string) ($skills[$skillId] ?? __('Unknown skill')),
                'covered' => count($holders),
                'single_point_of_failure' => count($holders) < $minimum,
                'holders' => $holders,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['covered'], $a['skill']] <=> [$b['covered'], $b['skill']]);

        return $rows;
    }

    private function authorizeView(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience(Auth::user(), self::VIEW_CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }
}
