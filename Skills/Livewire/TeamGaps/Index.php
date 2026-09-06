<?php

namespace App\Domains\People\Skills\Livewire\TeamGaps;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Models\TrainingRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The critical-skill gaps of this HOD's own direct reports, and whether
 * anything already targets each one.
 *
 * The subject set is the audience's, not the request's: there is no employee
 * id to supply, so a peer department's report cannot be reached by asking for
 * it. "Planned" is a request pointing at the very assessment that established
 * the gap, never a title or skill-name match — two people can have the same
 * skill short by different amounts, and only the assessment says whose.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = 'people.skill.gaps.view-team';

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function render(SkillAudience $audience, TenantContext $tenants): View
    {
        $this->authorizeView();
        $actor = Auth::user();
        $companyId = (int) $actor->company_id;
        $tenantId = (int) $tenants->requireTenantId();

        $visible = $audience->visibleEmployeeEntityIdsFor(
            $actor,
            $companyId,
            self::VIEW_CAPABILITY,
            includeSelf: false,
        );

        // includeSelf only governs the self-audience path; a head is still a
        // member of the department they run, so their own row has to come out
        // explicitly. This is a reports' page, and a manager's own gap belongs
        // on their manager's.
        $visible = array_values(array_filter(
            $visible,
            static fn (int $employeeId): bool => $employeeId !== (int) $actor->employee_id,
        ));

        return view('people::livewire.team-gaps.index', [
            'rows' => $visible === [] ? [] : $this->rows($tenantId, $companyId, $visible),
        ]);
    }

    /**
     * @param  list<int>  $visible
     * @return list<array{employee: string, skill: string, required_level: int, current_level: int, assessed_at: mixed, planned: bool}>
     */
    private function rows(int $tenantId, int $companyId, array $visible): array
    {
        $scores = EmployeeSkillScore::query()->forCompany($tenantId, $companyId)
            ->whereIn('employee_entity_id', $visible)
            ->where('criticality', RequirementCriticality::Critical->value)
            // A gap is a shortfall. At or above the required level there is
            // nothing to plan for, and listing it would bury the real ones.
            ->whereColumn('current_level', '<', 'required_level')
            ->orderBy('employee_entity_id')
            ->get();

        if ($scores->isEmpty()) {
            return [];
        }

        $names = Employee::query()->whereIn('id', $scores->pluck('employee_entity_id')->unique())
            ->pluck('full_name', 'id');
        $skills = Skill::query()->forCompany($tenantId, $companyId)
            ->whereIn('id', $scores->pluck('skill_id')->unique())->pluck('name', 'id');
        $targeted = TrainingRequest::query()->forCompany($tenantId, $companyId)
            ->whereIn('skill_gap_assessment_id', $scores->pluck('source_assessment_id')->filter()->unique())
            ->whereNotIn('status', [TrainingRequestStatus::Rejected->value, TrainingRequestStatus::Cancelled->value])
            ->pluck('skill_gap_assessment_id')
            ->all();

        return $scores->map(fn (EmployeeSkillScore $score): array => [
            'employee' => (string) ($names[$score->employee_entity_id] ?? __('Unknown employee')),
            'skill' => (string) ($skills[$score->skill_id] ?? __('Unknown skill')),
            'required_level' => (int) $score->required_level,
            'current_level' => (int) $score->current_level,
            'assessed_at' => $score->assessed_at,
            'planned' => in_array((int) $score->source_assessment_id, array_map('intval', $targeted), true),
        ])->values()->all();
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
