<?php

namespace App\Domains\People\Skills\Livewire\MyHistory;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Models\SkillReassessmentRequest;
use App\Domains\People\Skills\Services\AssessmentLogImporter;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The signed-in employee's own released score history (0006-a).
 *
 * The subject is always the audience's bound employee, never request
 * input: there is no employee id to supply, so another employee's rows
 * cannot be reached by asking for them. Read-only: no actions.
 *
 * Reassessment requests opened for the employee (0006-b, 0006-e) are listed
 * with their source, so a confirmed training that opened one is visible
 * before any score moves.
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

        $requests = SkillReassessmentRequest::query()
            ->forCompany($tenantId, $companyId)
            ->where('employee_entity_id', $employeeId)
            ->orderByDesc('id')
            ->get();

        $skills = Skill::query()
            ->forCompany($tenantId, $companyId)
            ->whereIn('id', $assessments->pluck('skill_id')->merge($requests->pluck('skill_id'))->unique()->all())
            ->pluck('name', 'id')
            ->map(static fn ($name): string => (string) $name)
            ->all();

        $people = User::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $assessments->pluck('assessor_user_id')
                ->merge($assessments->whereNotNull('source')->pluck('finalized_by_user_id'))
                ->filter()
                ->unique()
                ->all())
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
                    'assessor' => $people[$row->assessor_user_id] ?? __('Unknown assessor'),
                    'recordChannel' => self::recordChannel($row),
                    'importedBy' => $row->source === null
                        ? null
                        : ($people[$row->finalized_by_user_id] ?? __('Unknown importer')),
                    'validUntil' => $row->valid_until,
                    'expired' => self::isExpired($row, $today),
                    'current' => (int) $row->id === (int) $currentId,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return view('people::livewire.my-history.index', [
            'groups' => $groups,
            'requests' => $this->requestRows($tenantId, $companyId, $requests, $skills),
        ]);
    }

    /**
     * @param  Collection<int, SkillReassessmentRequest>  $requests
     * @param  array<int, string>  $skills
     * @return list<array{id: int, skill: string, source: string, dueAt: CarbonInterface, status: string}>
     */
    private function requestRows(int $tenantId, int $companyId, Collection $requests, array $skills): array
    {
        $factIds = $requests->pluck('source_participation_fact_id')->filter()->map(intval(...))->unique()->values()->all();
        $eventOfFact = $factIds === [] ? collect() : TrainingParticipationFact::query()
            ->forCompany($tenantId, $companyId)->whereIn('id', $factIds)->pluck('event_id', 'id');
        $eventTitles = $eventOfFact->isEmpty() ? collect() : TrainingEvent::query()
            ->forCompany($tenantId, $companyId)->whereIn('id', $eventOfFact->values()->all())->pluck('course_title_snapshot', 'id');

        return $requests->map(static function (SkillReassessmentRequest $request) use ($skills, $eventOfFact, $eventTitles): array {
            $event = $request->isFromTraining()
                ? $eventTitles[$eventOfFact[(int) $request->source_participation_fact_id] ?? 0] ?? null
                : null;

            return [
                'id' => (int) $request->id,
                'skill' => $skills[$request->skill_id] ?? __('Unknown skill'),
                'source' => $event === null ? __('From head of department') : __('From training :event', ['event' => $event]),
                'dueAt' => $request->due_at,
                'status' => $request->status->label(),
            ];
        })->values()->all();
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

    private static function recordChannel(SkillAssessment $assessment): string
    {
        if ($assessment->source === null) {
            return __('Signed-in assessor submission');
        }

        return $assessment->source === AssessmentLogImporter::SOURCE
            ? __('Verified assessment-log import')
            : __('Governed import');
    }

    private static function isExpired(SkillAssessment $row, CarbonImmutable $today): bool
    {
        return $row->valid_until !== null
            && CarbonImmutable::parse($row->valid_until)->startOfDay()->lessThan($today);
    }
}
