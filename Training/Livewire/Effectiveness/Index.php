<?php

namespace App\Domains\People\Training\Livewire\Effectiveness;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Skills\Data\DevelopmentActionDraft;
use App\Domains\People\Skills\Enums\DevelopmentActionType;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Exceptions\InvalidDevelopmentActionException;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Training\Data\OpenEffectivenessCheckpoint;
use App\Domains\People\Training\Enums\EffectivenessCheckpoint;
use App\Domains\People\Training\Enums\EffectivenessOutcome;
use App\Domains\People\Training\Enums\EffectivenessReviewState;
use App\Domains\People\Training\Exceptions\InvalidEffectivenessReviewException;
use App\Domains\People\Training\Exceptions\InvalidTrainingEffectivenessException;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEffectivenessAnswer;
use App\Domains\People\Training\Models\TrainingEffectivenessReview;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Services\TrainingEffectivenessCheckpoints;
use App\Domains\People\Training\Services\TrainingEffectivenessStore;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Throwable;

/**
 * The 30/60/90-day effectiveness questions waiting on the signed-in HOD.
 *
 * The list is filtered to this actor's own department by the service, and the
 * answer goes back through the service too — so a participant id typed into a
 * request reaches the same department check either way. Rendering fewer rows
 * is a courtesy; the refusal that matters is the store's.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = 'people.training.effectiveness.review';

    /** @var array<int, int> */
    public array $rating = [];

    /** @var array<int, string> */
    public array $comment = [];

    /**
     * The follow-up form, keyed by review id like the checkpoint answers above.
     * One form per review keeps the page to a single action: the button hands
     * over the review id, and the fields for that review come with it.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $followUp = [];

    public function render(
        TrainingEffectivenessCheckpoints $checkpoints,
        TenantContext $tenants,
        ReadsWorkforceDirectory $directory,
    ): View {
        $actor = Auth::user();
        $companyId = (int) $actor->company_id;
        $tenantId = $tenants->requireTenantId();

        $mine = array_values(array_filter(
            $checkpoints->open($tenantId, $companyId),
            static fn (OpenEffectivenessCheckpoint $row): bool => $row->hodUserId === (int) $actor->getKey(),
        ));
        $reviews = $this->followUpReviews($tenantId, $companyId, $directory);

        return view('people::livewire.effectiveness.index', [
            'rows' => $mine,
            'names' => $this->names($companyId, $mine),
            'courses' => $this->courses($tenantId, $companyId, $mine),
            'answers' => $this->answers($tenantId, $companyId, $mine),
            'followUpReviews' => $reviews,
            'followUpSkills' => $this->followUpSkills($tenantId, $companyId, $reviews),
            'followUpEmployees' => Employee::query()
                ->where('company_id', $companyId)->where('status', 'active')
                ->orderBy('full_name')->pluck('full_name', 'id')->all(),
            'followUpTypes' => DevelopmentActionType::cases(),
            'followUpCriticalities' => RequirementCriticality::cases(),
        ]);
    }

    public function save(int $participantId, TrainingEffectivenessCheckpoints $checkpoints, TenantContext $tenants): void
    {
        $actor = Auth::user();
        $companyId = (int) $actor->company_id;

        $row = collect($checkpoints->open($tenants->requireTenantId(), $companyId))
            ->first(static fn (OpenEffectivenessCheckpoint $open): bool => $open->participantId === $participantId);

        try {
            $checkpoints->answer(
                $actor,
                $companyId,
                $participantId,
                $row->checkpoint ?? EffectivenessCheckpoint::Day30,
                (int) ($this->rating[$participantId] ?? 0),
                trim((string) ($this->comment[$participantId] ?? '')),
            );
        } catch (InvalidTrainingEffectivenessException $exception) {
            $this->addError('effectiveness', $exception->getMessage());

            return;
        }

        session()->flash('training-effectiveness-status', __('Your answer was recorded.'));
    }

    /**
     * Open the development action a review that did not find the training
     * effective owes somebody (0013-f).
     *
     * Everything the action is *about* — the employee, the levels, the skill —
     * comes from the review inside the store; the form only carries what a
     * development action needs and a review cannot know, such as who owns the
     * work and when it is due. The store's refusals are surfaced rather than
     * re-implemented here.
     */
    public function openFollowUp(int $reviewId, TrainingEffectivenessStore $store): void
    {
        $actor = Auth::user();
        $form = $this->followUp[$reviewId] ?? [];

        try {
            $store->openFollowUpAction($actor, (int) $actor->company_id, $reviewId, new DevelopmentActionDraft(
                // Overwritten from the review by the store; a value is required
                // by the constructor, and the store's is the one that counts.
                employeeEntityId: 0,
                type: DevelopmentActionType::tryFrom((string) ($form['type'] ?? '')) ?? DevelopmentActionType::Coaching,
                objective: trim((string) ($form['objective'] ?? '')),
                intervention: trim((string) ($form['intervention'] ?? '')),
                expectedEvidence: trim((string) ($form['evidence'] ?? '')),
                ownerEmployeeEntityId: (int) ($form['owner'] ?? 0),
                hrCoordinatorEmployeeEntityId: (int) ($form['coordinator'] ?? 0),
                startDate: $this->followUpDate($form['startDate'] ?? null, today()),
                dueDate: $this->followUpDate($form['dueDate'] ?? null, today()->addDays(30)),
                trainerEmployeeEntityId: ($form['trainer'] ?? null) === null || $form['trainer'] === ''
                    ? null
                    : (int) $form['trainer'],
                skillId: ($form['skill'] ?? null) === null || $form['skill'] === '' ? null : (int) $form['skill'],
                criticality: RequirementCriticality::tryFrom((string) ($form['criticality'] ?? ''))
                    ?? RequirementCriticality::Critical,
            ));
        } catch (InvalidEffectivenessReviewException|InvalidDevelopmentActionException $exception) {
            $this->addError('followUp', $exception->getMessage());

            return;
        }

        unset($this->followUp[$reviewId]);
        session()->flash('training-effectiveness-status', __('The follow-up development action is open.'));
    }

    private function followUpDate(mixed $value, DateTimeInterface $default): DateTimeInterface
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return $default;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            // A date the browser never sent falls back to the default, and the
            // store still refuses a due date before the start.
            return $default;
        }
    }

    /**
     * The reviews this HOD owes a follow-up on: an outcome that did not find
     * the training effective, still open, and nothing linked yet.
     *
     * @return list<TrainingEffectivenessReview>
     */
    private function followUpReviews(int $tenantId, int $companyId, ReadsWorkforceDirectory $directory): array
    {
        $employee = $directory->employeeForUser((string) $companyId, (int) Auth::user()->getKey());

        if ($employee === null) {
            return [];
        }

        return TrainingEffectivenessReview::query()->forCompany($tenantId, $companyId)
            ->where('reviewer_employee_entity_id', (int) $employee->reference->externalId)
            ->where('state', EffectivenessReviewState::OutcomeRecorded->value)
            ->whereIn('outcome', [
                EffectivenessOutcome::PartiallyEffective->value,
                EffectivenessOutcome::NotYetEffective->value,
            ])
            ->whereNull('development_action_id')
            ->orderBy('due_on')
            ->get()
            ->all();
    }

    /**
     * The skills each listed review's own course covers, so the form can ask
     * which one a multi-skill course's follow-up is about.
     *
     * @param  list<TrainingEffectivenessReview>  $reviews
     * @return array<int, array<int, string>> review id => skill id => name
     */
    private function followUpSkills(int $tenantId, int $companyId, array $reviews): array
    {
        if ($reviews === []) {
            return [];
        }

        $participants = TrainingParticipant::query()->forCompany($tenantId, $companyId)
            ->whereIn('id', array_map(static fn (TrainingEffectivenessReview $r): int => (int) $r->training_participant_id, $reviews))
            ->get()->keyBy('id');
        $events = TrainingEvent::query()->forCompany($tenantId, $companyId)
            ->whereIn('id', $participants->pluck('event_id')->unique())
            ->get()->keyBy('id');
        $courses = TrainingCourse::query()->forCompany($tenantId, $companyId)
            ->whereIn('id', $events->pluck('course_id')->unique())
            ->get()->keyBy('id');
        $names = Skill::query()->forCompany($tenantId, $companyId)->pluck('name', 'id')->all();

        $byReview = [];

        foreach ($reviews as $review) {
            $course = $courses->get($events->get(
                $participants->get($review->training_participant_id)?->event_id,
            )?->course_id);
            $byReview[(int) $review->id] = $course === null
                ? []
                : array_reduce($course->skillIds(), static function (array $carry, int $skillId) use ($names): array {
                    $carry[$skillId] = (string) ($names[$skillId] ?? __('Unknown skill'));

                    return $carry;
                }, []);
        }

        return $byReview;
    }

    /**
     * @param  list<OpenEffectivenessCheckpoint>  $rows
     * @return array<int, string>
     */
    private function names(int $companyId, array $rows): array
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', array_map(static fn (OpenEffectivenessCheckpoint $row): int => $row->employeeEntityId, $rows))
            ->pluck('full_name', 'id')
            ->all();
    }

    /**
     * @param  list<OpenEffectivenessCheckpoint>  $rows
     * @return array<int, string>
     */
    private function courses(int $tenantId, int $companyId, array $rows): array
    {
        return TrainingEvent::query()->forCompany($tenantId, $companyId)
            ->whereIn('id', array_map(static fn (OpenEffectivenessCheckpoint $row): int => $row->eventId, $rows))
            ->pluck('course_title_snapshot', 'id')
            ->all();
    }

    /**
     * @param  list<OpenEffectivenessCheckpoint>  $rows
     * @return array<int, TrainingEffectivenessAnswer>
     */
    private function answers(int $tenantId, int $companyId, array $rows): array
    {
        return TrainingEffectivenessAnswer::query()->forCompany($tenantId, $companyId)
            ->whereIn('participant_id', array_map(static fn (OpenEffectivenessCheckpoint $row): int => $row->participantId, $rows))
            ->get()
            ->keyBy('participant_id')
            ->all();
    }
}
