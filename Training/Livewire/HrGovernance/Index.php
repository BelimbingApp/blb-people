<?php

namespace App\Domains\People\Training\Livewire\HrGovernance;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Enums\TrainingPlanStatus;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Models\TrainingPlan;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Services\TrainingPlanStore;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * HR governance queue (plan 0005, 0005-f): everything awaiting HR in the
 * acting user's company across Skills (requirement publication) and
 * Training (requests, plan approvals), with the approving actions.
 *
 * The page lists; it never decides. Every action is the owning store's own
 * method with its own capability and company checks, so nothing here can
 * approve what the store would refuse. The company is chosen from the HR
 * user's allowed companies and every query is pinned to it: an item of a
 * sibling company is never listed, whatever its state.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = 'people.skill.hr.view';

    public ?int $companyEntityId = null;

    /** @var array<int, string> */
    public array $profileComment = [];

    /** @var array<int, string> */
    public array $requestNotes = [];

    /** @var array<string, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $this->companyEntityId = $companies === [] ? null : (int) array_key_first($companies);
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
    }

    public function approveProfile(int $profileId): void
    {
        $companyEntityId = $this->requireCompany();
        $this->denialIs403(function () use ($companyEntityId, $profileId): void {
            app(RequirementProfileStore::class)->approveHr($this->user(), $companyEntityId, $profileId, $this->profileComment[$profileId] ?? '');
            unset($this->profileComment[$profileId]);
        });
    }

    public function returnProfile(int $profileId): void
    {
        $companyEntityId = $this->requireCompany();
        $this->denialIs403(function () use ($companyEntityId, $profileId): void {
            app(RequirementProfileStore::class)->returnByHr($this->user(), $companyEntityId, $profileId, $this->profileComment[$profileId] ?? '');
            unset($this->profileComment[$profileId]);
        });
    }

    public function publishProfile(int $profileId): void
    {
        $companyEntityId = $this->requireCompany();
        $this->denialIs403(function () use ($companyEntityId, $profileId): void {
            app(RequirementProfileStore::class)->publishApproved($this->user(), $companyEntityId, $profileId);
        });
    }

    public function reviewRequest(int $requestId): void
    {
        $companyEntityId = $this->requireCompany();
        $this->denialIs403(function () use ($companyEntityId, $requestId): void {
            app(TrainingRequestStore::class)->review($this->user(), $companyEntityId, $requestId, $this->notes($requestId));
            unset($this->requestNotes[$requestId]);
        });
    }

    public function rejectRequest(int $requestId): void
    {
        $companyEntityId = $this->requireCompany();
        $this->denialIs403(function () use ($companyEntityId, $requestId): void {
            app(TrainingRequestStore::class)->reject($this->user(), $companyEntityId, $requestId, $this->notes($requestId) ?? '');
            unset($this->requestNotes[$requestId]);
        });
    }

    public function approvePlan(int $planId): void
    {
        $companyEntityId = $this->requireCompany();
        $this->denialIs403(function () use ($companyEntityId, $planId): void {
            app(TrainingPlanStore::class)->approve($this->user(), $companyEntityId, $planId);
        });
    }

    public function render(): View
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        // The selected company is a public property, so a client can set it;
        // it is validated against the HR user's companies on every render,
        // not only when chosen through selectCompany().
        $companyEntityId = $this->companyEntityId === null ? null : $this->requireCompany();

        return view('people::livewire.hr-governance.index', [
            'companies' => $companies,
            'profiles' => $companyEntityId === null ? collect() : $this->pendingProfiles($companyEntityId),
            'requests' => $companyEntityId === null ? collect() : $this->pendingRequests($companyEntityId),
            'plans' => $companyEntityId === null ? collect() : $this->pendingPlans($companyEntityId),
        ]);
    }

    /**
     * Requirement profiles awaiting HR: pending HR review, or approved and
     * waiting to be published. The store's queue already applies the
     * governance audience; the status filter drops the HOD-stage items.
     *
     * @return Collection<int, RequirementProfile>
     */
    private function pendingProfiles(int $companyEntityId): Collection
    {
        return app(RequirementProfileStore::class)->reviewQueue($this->user(), $companyEntityId)
            ->filter(fn (RequirementProfile $profile): bool => in_array($profile->status, [
                RequirementProfileStatus::PendingHrReview,
                RequirementProfileStatus::Approved,
            ], true))
            ->values();
    }

    /** @return Collection<int, TrainingRequest> */
    private function pendingRequests(int $companyEntityId): Collection
    {
        return TrainingRequest::query()
            ->forCompany($this->tenantId(), $companyEntityId)
            ->where('status', TrainingRequestStatus::PendingHr->value)
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, TrainingPlan> */
    private function pendingPlans(int $companyEntityId): Collection
    {
        return TrainingPlan::query()
            ->forCompany($this->tenantId(), $companyEntityId)
            ->where('status', TrainingPlanStatus::Submitted->value)
            ->orderBy('id')
            ->get();
    }

    /**
     * A store's refusal on capability is the answer the page must give too:
     * a 403, not a recovered toast that reads as a transient failure.
     */
    private function denialIs403(\Closure $action): void
    {
        try {
            $action();
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    private function notes(int $requestId): ?string
    {
        $notes = trim($this->requestNotes[$requestId] ?? '');

        return $notes === '' ? null : $notes;
    }

    private function requireCompany(): int
    {
        $this->authorizeView();
        $companyEntityId = $this->companyEntityId;
        abort_unless($companyEntityId !== null && array_key_exists($companyEntityId, $this->allowedCompanies()), 404);

        return $companyEntityId;
    }

    private function authorizeView(): void
    {
        try {
            $audiences = app(SkillAudience::class)->authorizeAudience($this->user(), self::VIEW_CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        abort_unless(in_array(SkillAudience::HR, $audiences, true), 403);
    }

    /** @return array<int, string> */
    private function allowedCompanies(): array
    {
        return $this->allowedCompanies ??= app(SkillAudience::class)->allowedCompanies($this->user(), self::VIEW_CAPABILITY);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function tenantId(): int
    {
        return app(TenantContext::class)->requireTenantId();
    }
}
