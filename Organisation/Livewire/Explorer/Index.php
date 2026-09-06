<?php

namespace App\Domains\People\Organisation\Livewire\Explorer;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Organisation\Contracts\ReadsOrganisationExplorer;
use App\Domains\People\Organisation\Data\OrganisationAggregate;
use App\Domains\People\Organisation\Data\OrganisationDrillThrough;
use App\Domains\People\Organisation\Data\OrganisationNode;
use App\Domains\People\Organisation\Enums\OrganisationIndicator;
use App\Domains\People\Organisation\Enums\OrganisationPurpose;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Organisation explorer — presentation over the organisation read contract.
 *
 * Every rendered node passes through `structureNode`, children through
 * `drillThrough`, and badges through `aggregateIndicator`. A refused subject
 * renders as absent: no placeholder, no label, no count. The directory is
 * used only to enumerate candidate subjects when the company root itself is
 * refused (HOD, employee and other scoped audiences); enumeration never
 * authorizes and never renders. There is no second authorization engine here.
 */
class Index extends Component
{
    private const MAX_DEPTH = 5;

    public string $asOf = '';

    /** @var list<string> expanded subject keys (type:stableId) */
    public array $expanded = [];

    /** @var list<string> drill-through subject keys (type:stableId) */
    public array $details = [];

    public function mount(): void
    {
        $this->authorizeView();
        $this->asOf = now()->toDateString();
    }

    public function toggle(string $type, string $stableId): void
    {
        $this->authorizeView();
        $node = $this->resolveNode($type, $stableId);

        if ($node === null) {
            $this->expanded = array_values(array_diff($this->expanded, [$type.':'.$stableId]));

            return;
        }

        $key = $type.':'.$stableId;
        $this->expanded = in_array($key, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$key]))
            : [...$this->expanded, $key];
    }

    public function showDetail(string $type, string $stableId): void
    {
        $this->authorizeView();
        $node = $this->resolveNode($type, $stableId);
        $key = $type.':'.$stableId;

        if ($node === null || ! $this->drill($node) instanceof OrganisationDrillThrough) {
            $this->details = array_values(array_diff($this->details, [$key]));

            return;
        }

        if (! in_array($key, $this->details, true)) {
            $this->details[] = $key;
        }
    }

    public function hideDetail(string $type, string $stableId): void
    {
        $this->authorizeView();
        $key = $type.':'.$stableId;
        $this->details = array_values(array_diff($this->details, [$key]));
    }

    #[On('standing-as-of-changed')]
    public function setAsOf(string $date): void
    {
        $this->authorizeView();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > now()->toDateString()) {
            return;
        }

        $this->asOf = $date;
        $this->reset('expanded', 'details');
    }

    public function render(): View
    {
        $this->authorizeView();

        return view('people::livewire.explorer.index', [
            'tree' => $this->tree(),
            'asOfLabel' => $this->asOf,
            'historical' => $this->asOf !== now()->toDateString(),
        ]);
    }

    /**
     * @return list<array{node: OrganisationNode, key: string, badge: ?int, children: mixed, detail: ?OrganisationDrillThrough}>
     */
    private function tree(): array
    {
        return array_map(
            fn (OrganisationNode $node): array => $this->branch($node, 0),
            $this->rootNodes(),
        );
    }

    /** @return list<OrganisationNode> */
    private function rootNodes(): array
    {
        $actor = $this->actor();
        $reader = $this->reader();
        $asOf = $this->asOfDate();

        $company = new WorkforceSubject(
            app(TenantContext::class)->requireTenantId(),
            $actor->companyId,
            WorkforceResourceType::Company,
            (string) $actor->companyId,
        );
        $root = $reader->structureNode($actor, $company, $asOf);

        if ($root instanceof OrganisationNode) {
            return [$root];
        }

        $nodes = [];

        foreach (app(ReadsWorkforceDirectory::class)->organizationUnits((string) $actor->companyId) as $unit) {
            $candidate = $reader->structureNode(
                $actor,
                new WorkforceSubject(
                    $company->tenantId,
                    $actor->companyId,
                    WorkforceResourceType::OrganizationUnit,
                    $unit->reference->externalId,
                ),
                $asOf,
            );

            if ($candidate instanceof OrganisationNode) {
                $nodes[] = $candidate;
            }
        }

        return $nodes;
    }

    /**
     * @return array{node: OrganisationNode, key: string, badge: ?int, children: mixed, detail: ?OrganisationDrillThrough}
     */
    private function branch(OrganisationNode $node, int $depth): array
    {
        $key = $node->subject->type->value.':'.$node->subject->stableId;
        $children = [];
        $detail = null;

        if ($depth < self::MAX_DEPTH && in_array($key, $this->expanded, true)) {
            $drill = $this->reader()->drillThrough($this->actor(), $node, OrganisationPurpose::Structure);

            if ($drill instanceof OrganisationDrillThrough) {
                $children = array_map(
                    fn (OrganisationNode $child): array => $this->branch($child, $depth + 1),
                    $drill->nodes,
                );
            }
        }

        if (in_array($key, $this->details, true)) {
            $detail = $this->drill($node);
        }

        return [
            'node' => $node,
            'key' => $key,
            'badge' => $this->headcount($node),
            'children' => $children,
            'detail' => $detail,
        ];
    }

    private function resolveNode(string $type, string $stableId): ?OrganisationNode
    {
        $resource = WorkforceResourceType::tryFrom($type);

        if ($resource === null || trim($stableId) === '') {
            return null;
        }

        $actor = $this->actor();
        $node = $this->reader()->structureNode(
            $actor,
            new WorkforceSubject(
                app(TenantContext::class)->requireTenantId(),
                $actor->companyId,
                $resource,
                $stableId,
            ),
            $this->asOfDate(),
        );

        return $node instanceof OrganisationNode ? $node : null;
    }

    private function drill(OrganisationNode $node): ?OrganisationDrillThrough
    {
        $drill = $this->reader()->drillThrough($this->actor(), $node, OrganisationPurpose::IndividualDetail);

        return $drill instanceof OrganisationDrillThrough ? $drill : null;
    }

    private function headcount(OrganisationNode $node): ?int
    {
        $aggregate = $this->reader()->aggregateIndicator(
            $this->actor(),
            $node->subject,
            OrganisationIndicator::Headcount,
            $this->asOfDate(),
        );

        return $aggregate instanceof OrganisationAggregate ? $aggregate->value : null;
    }

    private function actor(): Actor
    {
        return Actor::forUser(Auth::user());
    }

    private function reader(): ReadsOrganisationExplorer
    {
        return app(ReadsOrganisationExplorer::class);
    }

    private function asOfDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->asOf !== '' ? $this->asOf : now()->toDateString(), new DateTimeZone('UTC'));
    }

    private function authorizeView(): void
    {
        $decision = app(AuthorizationService::class)->can(
            Actor::forUser(Auth::user()),
            'people.organisation.structure.view',
        );

        abort_unless($decision->allowed, 403);
    }
}
