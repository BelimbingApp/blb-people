<?php

namespace App\Domains\People\Training\Livewire\Evaluation;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Exceptions\InvalidTrainingEvaluationException;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Services\TrainingEvaluationSubmissionStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class Index extends Component
{
    public const CAPABILITY = TrainingEvaluationSubmissionStore::SUBMIT;

    public ?int $companyEntityId = null;

    public ?int $selectedEventId = null;

    /**
     * Livewire property => stored column, for criteria version 0012-g.v1.
     *
     * @var array<string, string>
     */
    public const RATINGS = [
        'relevance' => 'relevance',
        'objectivesMet' => 'objectives_met',
        'contentQuality' => 'content_quality',
        'trainerEffectiveness' => 'trainer_effectiveness',
        'materialsExercises' => 'materials_exercises',
        'paceDuration' => 'pace_duration',
        'practicalUsefulness' => 'practical_usefulness',
        'overallSatisfaction' => 'overall_satisfaction',
    ];

    /** @var array<string, string> */
    public const FREE_TEXT = [
        'mostUsefulLearning' => 'most_useful_learning',
        'applicationCommitment' => 'application_commitment',
        'supportNeeded' => 'support_needed',
        'recommendation' => 'recommendation',
        'comment' => 'issues_or_improvements',
    ];

    public mixed $relevance = null;

    public mixed $objectivesMet = null;

    public mixed $contentQuality = null;

    public mixed $trainerEffectiveness = null;

    public mixed $materialsExercises = null;

    public mixed $paceDuration = null;

    public mixed $practicalUsefulness = null;

    public mixed $overallSatisfaction = null;

    public string $mostUsefulLearning = '';

    public string $applicationCommitment = '';

    public string $supportNeeded = '';

    public string $recommendation = '';

    public string $comment = '';

    /** @var array<int, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $companies = $this->allowedCompanies();
        $this->companyEntityId = $companies === [] ? null : (int) array_key_first($companies);
        $this->selectFirstEvent();
    }

    public function selectCompany(int $companyEntityId): void
    {
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
        $this->selectFirstEvent();
    }

    public function selectEvent(int $eventId): void
    {
        $event = collect($this->events())->firstWhere('event_id', $eventId);
        abort_unless($event !== null, 404);
        $this->selectedEventId = $eventId;
        $this->fillEvaluation($event['evaluation']);
        $this->resetValidation();
    }

    /** Keep what is answered so far without completing (0012-g). */
    public function saveDraft(int $eventId): void
    {
        $this->write($eventId, false);
    }

    /** Complete against the mandatory questions of the current criteria version. */
    public function submit(int $eventId): void
    {
        $this->write($eventId, true);
    }

    private function write(int $eventId, bool $complete): void
    {
        $companyEntityId = $this->requireCompany();
        $rules = [];
        foreach (array_keys(self::RATINGS) as $field) {
            $rules[$field] = [$complete ? 'required' : 'nullable', 'integer', 'between:1,5'];
        }
        foreach (array_keys(self::FREE_TEXT) as $field) {
            $rules[$field] = [$complete && $field === 'applicationCommitment' ? 'required' : 'nullable', 'string', 'max:2000'];
        }
        $validated = $this->validate($rules);

        $answers = [];
        foreach (self::RATINGS as $field => $column) {
            $value = $validated[$field] ?? null;
            $answers[$column] = $value === null || $value === '' ? null : (int) $value;
        }
        foreach (self::FREE_TEXT as $field => $column) {
            $answers[$column] = $validated[$field] ?? null;
        }

        try {
            $store = app(TrainingEvaluationSubmissionStore::class);
            $complete
                ? $store->complete($this->user(), $companyEntityId, $eventId, $answers)
                : $store->saveDraft($this->user(), $companyEntityId, $eventId, $answers);
        } catch (AuthorizationDeniedException) {
            abort(403);
        } catch (InvalidTrainingEvaluationException $refusal) {
            $this->addError('evaluation', $refusal->getMessage());

            return;
        }

        $this->selectedEventId = $eventId;
        session()->flash('training-evaluation-status', $complete
            ? __('Evaluation submitted. You can revise it until the evaluation window closes.')
            : __('Draft saved. Submit it before the evaluation window closes.'));
    }

    public function render(): View
    {
        return view('people::livewire.evaluation.index', [
            'companies' => $this->allowedCompanies(),
            'events' => $this->events(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        return $this->companyEntityId === null
            ? []
            : app(TrainingEvaluationSubmissionStore::class)->visibleEvents($this->user(), $this->requireCompany());
    }

    private function selectFirstEvent(): void
    {
        $event = collect($this->events())->first();
        $this->selectedEventId = $event['event_id'] ?? null;
        $this->fillEvaluation($event['evaluation'] ?? null);
        $this->resetValidation();
    }

    private function fillEvaluation(?TrainingEvaluation $evaluation): void
    {
        foreach (self::RATINGS as $field => $column) {
            $this->{$field} = $evaluation?->{$column};
        }
        foreach (self::FREE_TEXT as $field => $column) {
            $this->{$field} = (string) ($evaluation?->{$column} ?? '');
        }
    }

    private function requireCompany(): int
    {
        $companyEntityId = $this->companyEntityId;
        abort_unless($companyEntityId !== null && array_key_exists($companyEntityId, $this->allowedCompanies()), 404);

        return $companyEntityId;
    }

    /** @return array<int, string> */
    private function allowedCompanies(): array
    {
        try {
            return $this->allowedCompanies ??= app(SkillAudience::class)->allowedCompanies($this->user(), self::CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
