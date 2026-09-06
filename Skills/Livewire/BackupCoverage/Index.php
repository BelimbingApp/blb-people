<?php

namespace App\Domains\People\Skills\Livewire\BackupCoverage;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Services\SkillAudience;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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

    /** Fewer than this many qualified holders is a single point of failure. */
    private const RESILIENT_HOLDERS = 2;

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function render(TenantContext $tenants): View
    {
        $this->authorizeView();
        $actor = Auth::user();

        return view('people::livewire.backup-coverage.index', [
            'rows' => $this->rows((int) $tenants->requireTenantId(), (int) $actor->company_id),
        ]);
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
        $rows = [];

        foreach ($scores->groupBy('skill_id') as $skillId => $group) {
            $holders = $group
                // At or above the requirement, and still valid. A score that
                // has lapsed is a record of past competence, not cover now.
                ->filter(fn (EmployeeSkillScore $score): bool => (int) $score->current_level >= (int) $score->required_level
                    && ($score->valid_until === null || $score->valid_until->toDateString() >= $today))
                ->map(fn (EmployeeSkillScore $score): string => (string) ($names[$score->employee_entity_id] ?? __('Unknown employee')))
                ->values()
                ->all();

            $rows[] = [
                'skill' => (string) ($skills[$skillId] ?? __('Unknown skill')),
                'covered' => count($holders),
                'single_point_of_failure' => count($holders) < self::RESILIENT_HOLDERS,
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
