<?php

namespace App\Domains\People\Shared\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Requirement version pill linking the pinned version a record was
 * assessed against. The destination URL comes from the owning page —
 * this component performs no lookups.
 */
final class RequirementVersionPill extends Component
{
    public function __construct(
        public string $reference,
        public int|string $version,
        public string $url,
        public ?string $status = null,
    ) {}

    public function render(): View
    {
        return view('people::shared.requirement-version-pill');
    }
}
