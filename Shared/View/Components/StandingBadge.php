<?php

namespace App\Domains\People\Shared\View\Components;

use App\Domains\People\Skills\Enums\AssessmentResultBand;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Proficiency badge over an already-authorized standing payload.
 *
 * Presentational only: every value arrives as a prop from a page that
 * enforced the read contract. Unpublished standing never renders — the
 * guard below is covered by SharedUiKitTest and must stay.
 *
 * The badge speaks AssessmentResultBand values: pages feed the enum value
 * from the standing read contract. The draft display labels stay accepted
 * so early kit consumers keep their tone, but new callers pass enum values.
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
        return match ($this->resultBand) {
            AssessmentResultBand::Exceeds->value, AssessmentResultBand::Meets->value,
            'exceeds requirement', 'meets requirement' => 'tone-met',
            AssessmentResultBand::MinorGap->value, AssessmentResultBand::MajorGap->value,
            AssessmentResultBand::CriticalGap->value, 'below requirement' => 'tone-gap',
            default => 'tone-unknown',
        };
    }

    public function bandLabel(): string
    {
        return match ($this->resultBand) {
            AssessmentResultBand::Exceeds->value => 'Exceeds requirement',
            AssessmentResultBand::Meets->value => 'Meets requirement',
            AssessmentResultBand::MinorGap->value, AssessmentResultBand::MajorGap->value,
            AssessmentResultBand::CriticalGap->value => 'Below requirement',
            AssessmentResultBand::NotAssessed->value => 'Unassessed',
            default => (string) $this->resultBand,
        };
    }
}
