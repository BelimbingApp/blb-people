<?php

namespace App\Domains\People\Training\Livewire\Budget;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Domains\People\Training\Exceptions\InvalidTrainingBudgetException;
use App\Domains\People\Training\Services\TrainingBudgetStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Department training budgets for one year (plan 0010, 0010-a).
 *
 * The page lists and edits nothing itself: every amount comes from
 * {@see TrainingBudgetStore} and every change goes back through it, so the
 * capability and company checks that guard the budget cannot be reached round.
 * Whether the editing controls are rendered is a courtesy to the reader; the
 * refusal that matters is the store's.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = TrainingBudgetStore::VIEW;

    public ?int $companyEntityId = null;

    public int $year;

    /** @var array<int, string> */
    public array $amount = [];

    /** @var array<int, string> */
    public array $reason = [];

    public function mount(?int $companyEntityId = null, ?int $year = null): void
    {
        $this->companyEntityId = $companyEntityId ?? (int) Auth::user()->company_id;
        $this->year = $year ?? (int) now()->year;
    }

    public function render(TrainingBudgetStore $budgets, AuthorizationService $authorization): View
    {
        $rows = $budgets->rollUp(Auth::user(), (int) $this->companyEntityId, $this->year);

        return view('people::livewire.budget.index', [
            'rows' => $rows,
            'mayManage' => $authorization->can(Actor::forUser(Auth::user()), TrainingBudgetStore::MANAGE)->allowed,
        ]);
    }

    public function save(int $departmentEntityId, TrainingBudgetStore $budgets): void
    {
        try {
            $budgets->setBudget(
                Auth::user(),
                (int) $this->companyEntityId,
                $departmentEntityId,
                $this->year,
                trim($this->amount[$departmentEntityId] ?? ''),
                trim($this->reason[$departmentEntityId] ?? ''),
            );
        } catch (InvalidTrainingBudgetException $exception) {
            $this->addError('budget', $exception->getMessage());

            return;
        }

        unset($this->amount[$departmentEntityId], $this->reason[$departmentEntityId]);
        session()->flash('training-budget-status', __('The budget was updated.'));
    }
}
