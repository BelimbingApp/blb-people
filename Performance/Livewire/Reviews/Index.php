<?php

namespace App\Domains\People\Performance\Livewire\Reviews;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Models\PerformanceReview;
use App\Domains\People\Performance\Services\PerformanceReviewStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The reviews this manager released, with the correction chain, read-only.
 *
 * Two things this page must not do. It must not widen the author scope — a
 * peer's review is not the acting manager's to read, and the scope comes from
 * the store rather than from anything the request can set. And it must not
 * re-read a superseded review through its correction: the rationale a manager
 * released is what the page shows for that version, which is the guarantee
 * JP-A07 buys and the easiest one for a list page to quietly undo.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = 'people.performance.review.view';

    /** Set only through select(), and only to a review inside the author scope. */
    #[Locked]
    public ?int $selected = null;

    public function mount(): void
    {
        $this->authorizeActor();
    }

    public function select(int $reviewId): void
    {
        $actor = $this->authorizeActor();
        $mine = array_map(
            static fn (PerformanceReview $review): int => (int) $review->id,
            app(PerformanceReviewStore::class)->authoredBy($actor, (int) $actor->company_id),
        );

        if (! in_array($reviewId, $mine, true)) {
            $this->addError('selected', __('That review is not one you released.'));

            return;
        }

        $this->selected = $reviewId;
    }

    public function render(PerformanceReviewStore $store): View
    {
        $actor = $this->authorizeActor();
        $reviews = $store->authoredBy($actor, (int) $actor->company_id);

        return view('people::livewire.performance.reviews.index', [
            'reviews' => array_map(static fn (PerformanceReview $review): array => [
                'id' => (int) $review->id,
                'version' => (int) $review->version,
                'status' => $review->status->value,
                'outcome' => $review->outcome->value,
                'outcome_label' => $review->outcome->label(),
                'period' => $review->period_start->format('Y-m-d').' — '.$review->period_end->format('Y-m-d'),
                // Read straight off this row. Resolving the current version
                // here would show a corrected rationale against a version that
                // never said it.
                'rationale' => (string) $review->rationale,
                'correction_reason' => $review->correction_reason === null ? null : (string) $review->correction_reason,
                'supersedes_review_id' => $review->supersedes_review_id === null ? null : (int) $review->supersedes_review_id,
                'finalized_at' => $review->finalized_at,
            ], $reviews),
        ]);
    }

    private function authorizeActor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User && $actor->tenant_id !== null && $actor->company_id !== null, 403);

        try {
            app(AuthorizationService::class)->authorize(Actor::forUser($actor), self::VIEW_CAPABILITY);
        } catch (\Throwable) {
            abort(403);
        }

        return $actor;
    }
}
