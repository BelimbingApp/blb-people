<?php

namespace App\Domains\People\Employees\Livewire;

use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Training\Data\TrainingPassportCertificate;
use App\Domains\People\Training\Exceptions\TrainingPassportDenied;
use App\Domains\People\Training\Services\TrainingPassportReader;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class TrainingPassport extends Component
{
    #[Locked]
    public string $subjectId = '';

    public function mount(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User && $actor->employee_id !== null, 403);

        $this->subjectId = (string) $actor->employee_id;
    }

    public function eventStatusLabel(string $status): string
    {
        return (string) Str::headline($status);
    }

    public function eventStatusVariant(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'cancelled' => 'danger',
            'in_progress' => 'info',
            default => 'warning',
        };
    }

    public function certificateStatusLabel(TrainingPassportCertificate $certificate): string
    {
        return $certificate->expired ? __('Expired') : __('Current');
    }

    public function render(TrainingPassportReader $reader): View
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User && (string) $actor->employee_id === $this->subjectId, 403);

        try {
            $passport = $reader->read($actor, $this->subject($actor));
        } catch (TrainingPassportDenied) {
            abort(403);
        }

        return view('people-employees::livewire.people.employees.training-passport', compact('passport'));
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
}
