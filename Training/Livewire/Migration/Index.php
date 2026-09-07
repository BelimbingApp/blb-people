<?php

namespace App\Domains\People\Training\Livewire\Migration;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Data\TrainingMigrationSourceDraft;
use App\Domains\People\Training\Enums\MigrationSourceKind;
use App\Domains\People\Training\Enums\PilotSignoffRole;
use App\Domains\People\Training\Exceptions\InvalidPilotSignoffException;
use App\Domains\People\Training\Exceptions\InvalidTrainingMigrationSourceException;
use App\Domains\People\Training\Models\TrainingMigrationSource;
use App\Domains\People\Training\Models\TrainingPilotSignoff;
use App\Domains\People\Training\Services\DepartmentPilotReadiness;
use App\Domains\People\Training\Services\PilotSignoffStore;
use App\Domains\People\Training\Services\TrainingMigrationSourceStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The migration source inventory page (0015-a) plus department pilot
 * readiness and HOD/HR sign-off (0015-c).
 *
 * Inventory mutations stay on {@see TrainingMigrationSourceStore}. Pilot
 * sign-off stays on {@see PilotSignoffStore}. The page chooses one unit at
 * a time for the readiness table.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = TrainingMigrationSourceStore::VIEW;

    public ?int $companyEntityId = null;

    /** Organisation unit whose readiness is shown; one unit at a time. */
    public ?int $organizationUnitEntityId = null;

    /** The source being edited, or null while the form records a new one. */
    public ?int $editingId = null;

    public string $sourceKey = '';

    public string $name = '';

    public string $kind = MigrationSourceKind::Workbook->value;

    public string $format = '';

    public string $ownerEmployeeEntityId = '';

    public string $estimatedVolume = '';

    public string $retentionNote = '';

    public string $dataQualityNote = '';

    public string $pilotSignNote = '';

    /** @var array<int, string> */
    public array $signNote = [];

    /** @var array<string, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $this->companyEntityId = $companies === [] ? null : (int) array_key_first($companies);
        $this->selectDefaultUnit();
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
        $this->resetForm();
        $this->selectDefaultUnit();
    }

    public function selectUnit(int $organizationUnitEntityId): void
    {
        $this->authorizeView();
        $units = $this->unitsForCompany($this->requireCompany());
        abort_unless(array_key_exists($organizationUnitEntityId, $units), 404);
        $this->organizationUnitEntityId = $organizationUnitEntityId;
        $this->pilotSignNote = '';
        $this->resetErrorBag('pilot');
    }

    public function render(
        TrainingMigrationSourceStore $store,
        AuthorizationService $authorization,
        DepartmentPilotReadiness $readiness,
    ): View {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $companyEntityId = $this->companyEntityId === null ? null : $this->requireCompany();
        $units = $companyEntityId === null ? [] : $this->unitsForCompany($companyEntityId);
        $unitId = $this->organizationUnitEntityId;
        if ($unitId !== null && ! array_key_exists($unitId, $units)) {
            $unitId = null;
            $this->organizationUnitEntityId = null;
        }

        $actor = Actor::forUser($this->user());

        return view('people::livewire.migration.index', [
            'companies' => $companies,
            'sources' => $companyEntityId === null ? collect() : $store->inventory($this->user(), $companyEntityId),
            'kinds' => MigrationSourceKind::cases(),
            'mayManage' => $authorization->can($actor, TrainingMigrationSourceStore::MANAGE)->allowed,
            'signed' => $companyEntityId !== null && $store->signedInventory($companyEntityId),
            'units' => $units,
            'readinessRows' => $companyEntityId === null || $unitId === null
                ? []
                : $readiness->rows($companyEntityId, $unitId),
            'pilotSignoffs' => $companyEntityId === null || $unitId === null
                ? collect()
                : $this->pilotSignoffs($companyEntityId, $unitId),
            'maySignHod' => $authorization->can($actor, PilotSignoffStore::HOD_APPROVE)->allowed,
            'maySignHr' => $authorization->can($actor, PilotSignoffStore::APPROVE)->allowed,
        ]);
    }

    /** Record a new source, or save the one being edited. */
    public function save(TrainingMigrationSourceStore $store): void
    {
        $companyEntityId = $this->requireCompany();
        $kind = MigrationSourceKind::tryFrom($this->kind);
        if ($kind === null) {
            $this->addError('form', __('Choose the kind of source.'));

            return;
        }

        try {
            $draft = new TrainingMigrationSourceDraft(
                sourceKey: trim($this->sourceKey),
                name: trim($this->name),
                kind: $kind,
                format: trim($this->format),
                ownerEmployeeEntityId: trim($this->ownerEmployeeEntityId) === '' ? null : (int) $this->ownerEmployeeEntityId,
                estimatedVolume: trim($this->estimatedVolume) === '' ? null : (int) $this->estimatedVolume,
                retentionNote: $this->retentionNote,
                dataQualityNote: $this->dataQualityNote,
            );
            if ($this->editingId === null) {
                $store->record($this->user(), $companyEntityId, $draft);
                session()->flash('migration-status', __('The source was recorded.'));
            } else {
                $store->update($this->user(), $companyEntityId, $this->editingId, $draft);
                session()->flash('migration-status', __('The source was updated.'));
            }
        } catch (InvalidTrainingMigrationSourceException $exception) {
            $this->addError('form', $exception->getMessage());

            return;
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        $this->resetForm();
    }

    /** Load an unsigned source into the form. */
    public function edit(int $sourceId, TrainingMigrationSourceStore $store): void
    {
        $companyEntityId = $this->requireCompany();
        $source = $store->inventory($this->user(), $companyEntityId)->firstWhere('id', $sourceId);
        abort_unless($source instanceof TrainingMigrationSource, 404);

        $this->editingId = (int) $source->id;
        $this->sourceKey = (string) $source->source_key;
        $this->name = (string) $source->name;
        $this->kind = $source->kind->value;
        $this->format = (string) $source->format;
        $this->ownerEmployeeEntityId = $source->owner_employee_id === null ? '' : (string) $source->owner_employee_id;
        $this->estimatedVolume = $source->estimated_volume === null ? '' : (string) $source->estimated_volume;
        $this->retentionNote = (string) ($source->retention_note ?? '');
        $this->dataQualityNote = (string) ($source->data_quality_note ?? '');
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function sign(int $sourceId, TrainingMigrationSourceStore $store): void
    {
        $companyEntityId = $this->requireCompany();

        try {
            $store->sign($this->user(), $companyEntityId, $sourceId, $this->signNote[$sourceId] ?? null);
        } catch (InvalidTrainingMigrationSourceException $exception) {
            $this->addError('sign.'.$sourceId, $exception->getMessage());

            return;
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        unset($this->signNote[$sourceId]);
        session()->flash('migration-status', __('The source was signed.'));
    }

    public function signAsHod(PilotSignoffStore $store): void
    {
        $companyEntityId = $this->requireCompany();
        $unitId = $this->requireUnit();

        try {
            $store->signAsHod($this->user(), $companyEntityId, $unitId, $this->pilotSignNote);
        } catch (InvalidPilotSignoffException $exception) {
            $this->addError('pilot', $exception->getMessage());

            return;
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        $this->pilotSignNote = '';
        session()->flash('migration-status', __('HOD signed pilot readiness for this department.'));
    }

    public function signAsHr(PilotSignoffStore $store): void
    {
        $companyEntityId = $this->requireCompany();
        $unitId = $this->requireUnit();

        try {
            $store->signAsHr($this->user(), $companyEntityId, $unitId, $this->pilotSignNote);
        } catch (InvalidPilotSignoffException $exception) {
            $this->addError('pilot', $exception->getMessage());

            return;
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        $this->pilotSignNote = '';
        session()->flash('migration-status', __('HR signed pilot readiness for this department.'));
    }

    private function selectDefaultUnit(): void
    {
        if ($this->companyEntityId === null) {
            $this->organizationUnitEntityId = null;

            return;
        }

        $units = $this->unitsForCompany($this->companyEntityId);
        $this->organizationUnitEntityId = $units === [] ? null : (int) array_key_first($units);
    }

    /** @return array<int, string> */
    private function unitsForCompany(int $companyEntityId): array
    {
        $units = [];
        foreach (app(WorkforceSubjects::class)->organizationUnits($companyEntityId) as $unit) {
            if (ctype_digit($unit->reference->externalId)) {
                $units[(int) $unit->reference->externalId] = $unit->name;
            }
        }
        asort($units);

        return $units;
    }

    /** @return Collection<int, TrainingPilotSignoff> */
    private function pilotSignoffs(int $companyEntityId, int $organizationUnitEntityId)
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        return TrainingPilotSignoff::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('organization_unit_entity_id', $organizationUnitEntityId)
            ->orderBy('id')
            ->get()
            ->keyBy(fn (TrainingPilotSignoff $row): string => $row->role instanceof PilotSignoffRole
                ? $row->role->value
                : (string) $row->role);
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'sourceKey', 'name', 'format', 'ownerEmployeeEntityId', 'estimatedVolume', 'retentionNote', 'dataQualityNote');
        $this->kind = MigrationSourceKind::Workbook->value;
    }

    private function requireCompany(): int
    {
        $this->authorizeView();
        $companyEntityId = $this->companyEntityId;
        abort_unless($companyEntityId !== null && array_key_exists($companyEntityId, $this->allowedCompanies()), 404);

        return $companyEntityId;
    }

    private function requireUnit(): int
    {
        $companyEntityId = $this->requireCompany();
        $unitId = $this->organizationUnitEntityId;
        abort_unless($unitId !== null && array_key_exists($unitId, $this->unitsForCompany($companyEntityId)), 404);

        return $unitId;
    }

    /** HR or a HOD of the company: the capability opens the page, the audience says for which companies. */
    private function authorizeView(): void
    {
        try {
            $audiences = app(SkillAudience::class)->authorizeAudience($this->user(), self::VIEW_CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        abort_unless(in_array(SkillAudience::HR, $audiences, true) || in_array(SkillAudience::HOD, $audiences, true), 403);
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
}
