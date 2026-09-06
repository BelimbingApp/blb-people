<?php

namespace App\Domains\People\Employees\Livewire;

use App\Core\User\Models\User;
use App\Domains\People\Employees\Services\EmployeeStandingReader;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Data\OwnAssessmentOutcome;
use App\Domains\People\Skills\Enums\SelfStandingRefusal;
use App\Domains\People\Skills\Exceptions\SelfStandingDenied;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class MyStanding extends Component
{
    #[Locked]
    public string $subjectId = '';

    public function mount(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User && $actor->employee_id !== null, 403);

        $this->subjectId = (string) $actor->employee_id;
    }

    public function standingLabel(OwnAssessmentOutcome $outcome): string
    {
        return match (true) {
            $this->isExpired($outcome) => __('Expired'),
            $outcome->assessedLevel === null => __('Unassessed'),
            ($outcome->gap ?? 1) > 0 => __('Below requirement'),
            default => __('Competent'),
        };
    }

    public function standingVariant(OwnAssessmentOutcome $outcome): string
    {
        return match (true) {
            $this->isExpired($outcome) => 'danger',
            $outcome->assessedLevel === null => 'warning',
            ($outcome->gap ?? 1) > 0 => 'warning',
            default => 'success',
        };
    }

    public function render(EmployeeStandingReader $reader): View
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User && (string) $actor->employee_id === $this->subjectId, 403);

        $standing = null;
        $unavailable = null;

        try {
            $standing = $reader->read($actor, $this->subject($actor));
        } catch (SelfStandingDenied $exception) {
            if (in_array($exception->reason, [
                SelfStandingRefusal::Unauthorized,
                SelfStandingRefusal::MissingScope,
                SelfStandingRefusal::SubjectMismatch,
            ], true)) {
                abort(403);
            }

            $unavailable = match ($exception->reason) {
                SelfStandingRefusal::BindingUnavailable => __('Your employee account link is not confirmed. Ask an administrator to check your People access.'),
                SelfStandingRefusal::Unpublished => __('Your standing is not ready to publish yet. Ask your manager or People team when it will be available.'),
                default => __('Your standing cannot be loaded right now. Try again later or ask your People administrator for help.'),
            };
        }

        return view('people-employees::livewire.people.employees.my-standing', [
            'standing' => $standing,
            'unavailable' => $unavailable,
        ]);
    }

    private function subject(User $actor): WorkforceSubject
    {
        abort_unless($actor->tenant_id !== null && $actor->company_id !== null, 403);

        return new WorkforceSubject(
            (int) $actor->tenant_id,
            (int) $actor->company_id,
            WorkforceResourceType::Employee,
            $this->subjectId,
            new ExternalReference(WorkforceResourceType::Employee, $this->subjectId),
        );
    }

    private function isExpired(OwnAssessmentOutcome $outcome): bool
    {
        return $outcome->validUntil !== null
            && CarbonImmutable::parse($outcome->validUntil)->isBefore(today());
    }
}
