<?php

namespace App\Domains\People\Employees\Livewire;

use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Training\Exceptions\TrainingPassportDenied;
use App\Domains\People\Training\Services\TrainingPassportDocumentStore;
use App\Domains\People\Training\Services\TrainingPassportReader;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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

    /**
     * Render and store the printable passport (0014-a). The subject is the
     * locked, authenticated employee; nothing from the request can choose
     * whose passport is produced.
     */
    public function generatePassportPdf(TrainingPassportDocumentStore $documents): void
    {
        $actor = $this->actor();

        try {
            $document = $documents->generate($actor, $this->subject($actor));
        } catch (TrainingPassportDenied) {
            abort(403);
        }

        $this->dispatch('training-passport-generated', documentId: (int) $document->id);
    }

    public function render(TrainingPassportReader $reader, TrainingPassportDocumentStore $documents): View
    {
        $actor = $this->actor();

        try {
            $subject = $this->subject($actor);
            $passport = $reader->read($actor, $subject);
        } catch (TrainingPassportDenied) {
            abort(403);
        }
        $passportDocuments = $documents->documentsFor($actor, $subject);

        return view('people-employees::livewire.people.employees.training-passport', compact('passport', 'passportDocuments'));
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User && (string) $actor->employee_id === $this->subjectId, 403);

        return $actor;
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
