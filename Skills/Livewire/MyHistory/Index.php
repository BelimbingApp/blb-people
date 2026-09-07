<?php

namespace App\Domains\People\Skills\Livewire\MyHistory;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\SkillAudience;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The signed-in employee's own released score history (0006-a).
 *
 * The subject is always the audience's bound employee, never request
 * input: there is no employee id to supply, so another employee's rows
 * cannot be reached by asking for them. Read-only: no actions.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = 'people.skill.history.view';

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function render(SkillAudience $audience, TenantContext $tenants): View
    {
        $this->authorizeView();
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $companyId = (int) $actor->company_id;
        $tenantId = (int) $tenants->requireTenantId();

        $employeeId = $audience->boundEmployeeEntityId($actor, $companyId);
        abort_unless($employeeId !== null, 404);

        $assessments = SkillAssessment::query()
            ->forCompany($tenantId, $companyId)
            ->where('employee_entity_id', $employeeId)
            ->where('status', AssessmentStatus::Finalized->value)
            ->orderByDesc('assessed_at')
            ->orderByDesc('id')
            ->get();

        $today = CarbonImmutable::today();
        $currentId = $assessments
            ->first(static fn (SkillAssessment $row): bool => ! self::isExpired($row, $today))
            ?->getKey();

        $skills = Skill::query()
            ->forCompany($tenantId, $companyId)
            ->whereIn('id', $assessments->pluck('skill_id')->all())
            ->pluck('name', 'id')
            ->map(static fn ($name): string => (string) $name)
            ->all();

        $assessors = User::query()
            ->whereIn('id', $assessments->pluck('assessor_user_id')->filter()->all())
            ->pluck('name', 'id')
            ->map(static fn ($name): string => (string) $name)
            ->all();

        $groups = $assessments
            ->groupBy('skill_id')
            ->map(static fn ($rows, $skillId): array => [
                'skill' => $skills[$skillId] ?? __('Unknown skill'),
                'entries' => $rows->map(static fn (SkillAssessment $row): array => [
                    'id' => (int) $row->id,
                    'level' => (int) $row->assessed_level,
                    'assessedAt' => $row->assessed_at,
                    'assessor' => $assessors[$row->assessor_user_id] ?? __('Unknown assessor'),
                    'validUntil' => $row->valid_until,
                    'expired' => self::isExpired($row, $today),
                    'current' => (int) $row->id === (int) $currentId,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return view('people::livewire.my-history.index', ['groups' => $groups]);
    }

    private function authorizeView(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            $audiences = app(SkillAudience::class)->authorizeAudience($user, self::VIEW_CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        abort_unless(in_array(SkillAudience::EMPLOYEE, $audiences, true), 403);
    }

    private static function isExpired(SkillAssessment $row, CarbonImmutable $today): bool
    {
        return $row->valid_until !== null
            && CarbonImmutable::parse($row->valid_until)->startOfDay()->lessThan($today);
    }
}
