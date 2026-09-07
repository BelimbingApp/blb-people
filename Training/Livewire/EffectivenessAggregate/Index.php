<?php

namespace App\Domains\People\Training\Livewire\EffectivenessAggregate;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Training\Enums\EffectivenessCheckpoint;
use App\Domains\People\Training\Services\TrainingEffectivenessAggregate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * HR's view of what training is doing thirty, sixty and ninety days on.
 *
 * Read-only. Every number comes from {@see TrainingEffectivenessAggregate},
 * which is where the answer-rate denominator lives — the page must not be able
 * to compute a friendlier one.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = TrainingEffectivenessAggregate::VIEW;

    public ?int $departmentEntityId = null;

    public function mount(AuthorizationService $authorization): void
    {
        $this->assertMayView($authorization);
    }

    public function render(
        TrainingEffectivenessAggregate $aggregate,
        TenantContext $tenants,
        AuthorizationService $authorization,
    ): View {
        // Re-checked on every render, not only at mount: departmentEntityId is
        // a public property a client can set, and a capability can be revoked
        // between one request and the next.
        $this->assertMayView($authorization);
        $companyId = (int) Auth::user()->company_id;

        return view('people::livewire.effectiveness-aggregate.index', [
            'rows' => $aggregate->perCourse(
                $tenants->requireTenantId(),
                $companyId,
                $this->departmentEntityId,
            ),
            'checkpoints' => EffectivenessCheckpoint::cases(),
            'departments' => PeopleReferenceEntry::query()
                ->where('company_id', $companyId)
                ->where('type', PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
        ]);
    }

    private function assertMayView(AuthorizationService $authorization): void
    {
        $authorization->authorize(Actor::forUser(Auth::user()), self::VIEW_CAPABILITY);
    }
}
