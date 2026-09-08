<?php

namespace App\Domains\People\Training\Livewire\EffectivenessAggregate;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Department;
use App\Core\User\Models\User;
use App\Domains\People\Training\Enums\EffectivenessCheckpoint;
use App\Domains\People\Training\Exceptions\InvalidTrainingEffectivenessException;
use App\Domains\People\Training\Livewire\Effectiveness\Index as ReviewIndex;
use App\Domains\People\Training\Models\TrainingEffectivenessCheckpointPolicy;
use App\Domains\People\Training\Services\TrainingEffectivenessAggregate;
use App\Domains\People\Training\Services\TrainingEffectivenessPolicy;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Throwable;

/**
 * HR's view of what training is doing thirty, sixty and ninety days on, and
 * the governed checkpoint offsets that date those questions.
 *
 * Aggregate numbers come from {@see TrainingEffectivenessAggregate}; every
 * policy change goes through {@see TrainingEffectivenessPolicy}. The page does
 * not re-compute rates or re-implement the store's refusals — it surfaces them.
 * Whether the set-policy form is rendered is a courtesy; the refusal that
 * matters is the service's.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = TrainingEffectivenessAggregate::VIEW;

    /**
     * Deliberately not URL-bound. This page filters by Core Department
     * (#437) and the training KPI dashboard counts by workforce organisation
     * unit, so a `?department=` from there would filter to nothing; the KPI's
     * effectiveness drills link here without one (#389).
     */
    public ?int $departmentEntityId = null;

    public string $day30 = '30';

    public string $day60 = '60';

    public string $day90 = '90';

    public string $effectiveFrom = '';

    public string $reason = '';

    public function mount(AuthorizationService $authorization): void
    {
        $this->assertMayView($authorization);
        $this->effectiveFrom = CarbonImmutable::today()->toDateString();
    }

    public function render(
        TrainingEffectivenessAggregate $aggregate,
        TrainingEffectivenessPolicy $policies,
        TenantContext $tenants,
        AuthorizationService $authorization,
    ): View {
        // Re-checked on every render, not only at mount: departmentEntityId is
        // a public property a client can set, and a capability can be revoked
        // between one request and the next.
        $this->assertMayView($authorization);
        $companyId = (int) Auth::user()->company_id;
        $tenantId = $tenants->requireTenantId();
        $mayManage = $authorization->can(
            Actor::forUser(Auth::user()),
            TrainingEffectivenessPolicy::MANAGE_CAPABILITY,
        )->allowed;
        $policyHistory = $policies->history($tenantId, $companyId);

        return view('people::livewire.effectiveness-aggregate.index', [
            'rows' => $aggregate->perCourse(
                $tenantId,
                $companyId,
                $this->departmentEntityId,
            ),
            'checkpoints' => EffectivenessCheckpoint::cases(),
            // Core departments, not People reference units: perCourse()
            // attributes a row by Employee.department_id, so an option from any
            // other identity space filters to nothing unless the two ids happen
            // to coincide (#437). DepartmentHeads and BackupCoverage already
            // treat Core Department as the authoritative department identity.
            'departments' => $this->departmentOptions($companyId),
            'mayManage' => $mayManage,
            'policyHistory' => $policyHistory,
            'setByNames' => $this->setByNames($policyHistory),
            'canReviewEffectiveness' => $authorization->can(
                Actor::forUser(Auth::user()),
                ReviewIndex::VIEW_CAPABILITY,
            )->allowed,
            'canSummarizeEffectiveness' => true,
            'activeEffectivenessTab' => 'summary',
        ]);
    }

    /**
     * Append the next governed checkpoint policy for this company.
     *
     * Refusals stay as {@see InvalidTrainingEffectivenessException}
     * (a RuntimeException): RecoverFromActionFailure turns them into an error
     * toast rather than a silent no-op or a hand-rolled try/catch that would
     * restate the store.
     */
    public function setPolicy(TrainingEffectivenessPolicy $policies): void
    {
        $policies->set(
            Auth::user(),
            (int) Auth::user()->company_id,
            (int) $this->day30,
            (int) $this->day60,
            (int) $this->day90,
            $this->effectiveFromDate(),
            $this->reason,
        );

        $this->reason = '';
        $this->effectiveFrom = CarbonImmutable::today()->toDateString();
        session()->flash('effectiveness-policy-status', __('The checkpoint policy was recorded.'));
    }

    private function effectiveFromDate(): DateTimeInterface
    {
        $value = trim($this->effectiveFrom);

        if ($value === '') {
            return CarbonImmutable::today();
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            // An unparseable date reaches the store as "before today" once we
            // fall back to a past sentinel, so the user still sees a refusal
            // toast rather than a silent no-op.
            return CarbonImmutable::yesterday();
        }
    }

    /**
     * @param  list<TrainingEffectivenessCheckpointPolicy>  $history
     * @return array<int, string>
     */
    private function setByNames(array $history): array
    {
        if ($history === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map(
            static fn ($row): int => (int) $row->set_by_user_id,
            $history,
        )));

        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /** @return array<int, string> Core department id => display name, ordered by name */
    private function departmentOptions(int $companyId): array
    {
        return Department::query()
            ->where('company_id', $companyId)
            ->with('type')
            ->get()
            ->mapWithKeys(static fn (Department $department): array => [
                (int) $department->id => (string) ($department->name ?? __('Unnamed department')),
            ])
            ->sort()
            ->all();
    }

    private function assertMayView(AuthorizationService $authorization): void
    {
        $authorization->authorize(Actor::forUser(Auth::user()), self::VIEW_CAPABILITY);
    }
}
