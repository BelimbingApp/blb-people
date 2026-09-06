<?php

namespace App\Domains\People\Shared\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Proficiency badge over an already-authorized standing payload.
 *
 * Presentational only: every value arrives as a prop from a page that
 * enforced the read contract. Unpublished standing never renders — the
 * guard below is covered by SharedUiKitTest and must stay.
 */
final class StandingBadge extends Component
{
    public function __construct(
        public string $skill,
        public ?int $assessedLevel = null,
        public ?int $requiredLevel = null,
        public ?int $gap = null,
        public ?string $resultBand = null,
        public bool $published = false,
        public ?string $assessedAt = null,
    ) {}

    public function render(): View|string
    {
        if (! $this->published) {
            return '';
        }

        return view('people::shared.standing-badge');
    }

    public function bandTone(): string
    {
        return match (strtolower((string) $this->resultBand)) {
            'exceeds requirement', 'meets requirement' => 'tone-met',
            'below requirement' => 'tone-gap',
            default => 'tone-unknown',
        };
    }
}
