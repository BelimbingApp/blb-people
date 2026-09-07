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

    public mixed $relevance = null;

    public mixed $trainerEffectiveness = null;

    public mixed $materialsExercises = null;

    public mixed $paceDuration = null;

    public mixed $practicalUsefulness = null;

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

    public function submit(int $eventId): void
    {
        $companyEntityId = $this->requireCompany();
        $validated = $this->validate([
            'relevance' => ['required', 'integer', 'between:1,5'],
            'trainerEffectiveness' => ['required', 'integer', 'between:1,5'],
            'materialsExercises' => ['required', 'integer', 'between:1,5'],
            'paceDuration' => ['required', 'integer', 'between:1,5'],
            'practicalUsefulness' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            app(TrainingEvaluationSubmissionStore::class)->submit($this->user(), $companyEntityId, $eventId, [
                'relevance' => (int) $validated['relevance'],
                'trainer_effectiveness' => (int) $validated['trainerEffectiveness'],
                'materials_exercises' => (int) $validated['materialsExercises'],
                'pace_duration' => (int) $validated['paceDuration'],
                'practical_usefulness' => (int) $validated['practicalUsefulness'],
            ], $validated['comment']);
        } catch (AuthorizationDeniedException) {
            abort(403);
        } catch (InvalidTrainingEvaluationException $refusal) {
            $this->addError('evaluation', $refusal->getMessage());

            return;
        }

        $this->selectedEventId = $eventId;
        session()->flash('training-evaluation-status', __('Evaluation saved. You can revise it until the evaluation window closes.'));
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
        $this->relevance = $evaluation?->relevance;
        $this->trainerEffectiveness = $evaluation?->trainer_effectiveness;
        $this->materialsExercises = $evaluation?->materials_exercises;
        $this->paceDuration = $evaluation?->pace_duration;
        $this->practicalUsefulness = $evaluation?->practical_usefulness;
        $this->comment = (string) ($evaluation?->issues_or_improvements ?? '');
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
