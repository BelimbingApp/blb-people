<?php

namespace App\Domains\People\Training\Livewire\Evidence;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Exceptions\InvalidTrainingEvidenceSubmissionException;
use App\Domains\People\Training\Livewire\HrGovernance\Index as HrGovernanceIndex;
use App\Domains\People\Training\Services\TrainingEvidenceSubmissionStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;

final class Index extends Component
{
    use WithFileUploads;

    public const CAPABILITY = TrainingEvidenceSubmissionStore::SUBMIT;

    public ?int $companyEntityId = null;

    /** Per-request memo; deliberately not Livewire state. */
    private ?bool $identityMissing = null;

    public string $reflection = '';

    public string $certificateNumber = '';

    public string $certificateExpiresOn = '';

    public mixed $document = null;

    /** @var array<int, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $companies = $this->allowedCompanies();
        $this->companyEntityId = $companies === [] ? null : (int) array_key_first($companies);
    }

    public function selectCompany(int $companyEntityId): void
    {
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
        $this->resetForm();
    }

    public function submit(int $eventId): void
    {
        $companyEntityId = $this->requireCompany();
        $this->validate([
            'reflection' => ['required', 'string', 'max:2000'],
            'certificateNumber' => ['nullable', 'string', 'max:160'],
            'certificateExpiresOn' => ['nullable', 'date_format:Y-m-d'],
            // MIME is enforced again by the store, where every caller passes.
            'document' => ['required', 'file', 'max:10240'],
        ]);

        try {
            app(TrainingEvidenceSubmissionStore::class)->submit(
                $this->user(), $companyEntityId, $eventId, $this->reflection,
                $this->certificateNumber, $this->certificateExpiresOn, $this->document,
            );
        } catch (AuthorizationDeniedException) {
            abort(403);
        } catch (InvalidTrainingEvidenceSubmissionException $refusal) {
            $this->addError('evidence', $refusal->getMessage());

            return;
        }

        $this->resetForm();
        session()->flash('training-evidence-status', __('Evidence submitted. Pending HR confirmation.'));
    }

    public function render(): View
    {
        $companyEntityId = $this->companyEntityId === null ? null : $this->requireCompany();
        $identityMissing = $this->employeeIdentityMissing();

        return view('people::livewire.evidence.index', [
            'companies' => $this->allowedCompanies(),
            'events' => $companyEntityId === null || $identityMissing
                ? []
                : app(TrainingEvidenceSubmissionStore::class)->visibleEvents($this->user(), $companyEntityId),
            'employeeIdentityMissing' => $identityMissing,
            'mayReviewEvidenceQueue' => $identityMissing && $this->mayReviewEvidenceQueue(),
        ]);
    }

    /**
     * Whether to offer the HR evidence queue as the next step.
     *
     * #434's explicit expectation: an HR manager with no personal employee
     * linkage is not stuck. The queue they want already exists at
     * people.hr-governance.index, so this points at it rather than implying
     * personal submission is the only door -- and it asks with that page's own
     * gate, capability plus HR audience, so the link never appears to someone
     * who would be refused on arrival.
     */
    private function mayReviewEvidenceQueue(): bool
    {
        try {
            $audiences = app(SkillAudience::class)->authorizeAudience($this->user(), HrGovernanceIndex::VIEW_CAPABILITY);
        } catch (AuthorizationDeniedException) {
            return false;
        }

        return in_array(SkillAudience::HR, $audiences, true);
    }

    /**
     * Whether this account is authorized here but has no employee identity.
     *
     * Asking before visibleEvents() is what keeps the first render from dying:
     * the store denies inside scope(), and that exception used to escape
     * render() as an unhandled 500 (#434). The action-level submit() catch
     * never covered the initial GET.
     */
    private function employeeIdentityMissing(): bool
    {
        if ($this->companyEntityId === null) {
            return false;
        }

        return $this->identityMissing ??= ! app(TrainingEvidenceSubmissionStore::class)
            ->hasEmployeeIdentity($this->user(), $this->requireCompany());
    }

    private function resetForm(): void
    {
        $this->reset('reflection', 'certificateNumber', 'certificateExpiresOn', 'document');
        $this->resetValidation();
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
