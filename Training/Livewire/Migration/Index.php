<?php

namespace App\Domains\People\Training\Livewire\Migration;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Data\TrainingMigrationFieldMappingDraft;
use App\Domains\People\Training\Data\TrainingMigrationSourceDraft;
use App\Domains\People\Training\Enums\MigrationSourceKind;
use App\Domains\People\Training\Enums\MigrationWorkflow;
use App\Domains\People\Training\Enums\MigrationWriter;
use App\Domains\People\Training\Exceptions\InvalidTrainingMigrationMappingException;
use App\Domains\People\Training\Exceptions\InvalidTrainingMigrationSourceException;
use App\Domains\People\Training\Models\TrainingMigrationSource;
use App\Domains\People\Training\Services\MigrationLedger;
use App\Domains\People\Training\Services\TrainingMigrationMappingStore;
use App\Domains\People\Training\Services\TrainingMigrationSourceStore;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The migration source inventory page (0015-a): HR records each legacy
 * source, corrects it while unsigned, and signs it; HODs read. The field and
 * code mapping register and the writer windows (0015-b) sit on the same page,
 * appended through {@see TrainingMigrationMappingStore} and closed by one
 * sign-off of the mapping set.
 *
 * The page lists and edits nothing itself: every row comes from a store and
 * every change goes back through one, so the capability and company checks
 * live in one place. Whether the form is
 * rendered is a courtesy to the reader; the refusal that matters is the store's.
 */
final class Index extends Component
{
    use WithPagination;

    public bool $showForm = false;

    public string $search = '';

    public string $sortDirection = 'asc';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function sortSources(): void
    {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->resetPage();
    }

    public function createSource(): void
    {
        $this->requireCompany();
        abort_unless(app(AuthorizationService::class)->can(Actor::forUser($this->user()), TrainingMigrationSourceStore::MANAGE)->allowed, 403);
        $this->resetForm();
        $this->showForm = true;
    }

    public const VIEW_CAPABILITY = TrainingMigrationSourceStore::VIEW;

    public ?int $companyEntityId = null;

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

    /** @var array<int, string> */
    public array $signNote = [];

    public string $mappingSourceId = '';

    public string $mappingSourceField = '';

    public string $mappingSourceCode = '';

    public string $mappingTargetTable = '';

    public string $mappingTargetColumn = '';

    public string $mappingDedupRule = '';

    public string $windowWorkflow = MigrationWorkflow::Assessments->value;

    public string $windowWriter = MigrationWriter::Legacy->value;

    public string $windowStartsOn = '';

    public string $windowEndsOn = '';

    public string $mappingSignNote = '';

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
        $this->search = '';
        $this->resetPage();
        $this->resetForm();
    }

    public function render(TrainingMigrationSourceStore $store, MigrationLedger $ledger, TrainingMigrationMappingStore $mappings, AuthorizationService $authorization): View
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $companyEntityId = $this->companyEntityId === null ? null : $this->requireCompany();
        $user = $this->user();

        $employees = $companyEntityId === null ? collect() : collect(app(WorkforceSubjects::class)->employees($companyEntityId))
            ->mapWithKeys(fn ($employee): array => [(int) $employee->reference->externalId => $employee->displayName])
            ->sort();
        $inventory = $companyEntityId === null ? collect() : $store->inventory($this->user(), $companyEntityId);
        $filtered = $inventory->filter(fn ($source): bool => $this->search === '' || str_contains(
            mb_strtolower($source->name.' '.$source->source_key.' '.($employees[$source->owner_employee_id] ?? '')),
            mb_strtolower(trim($this->search)),
        ))->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE, $this->sortDirection === 'desc')->values();
        $sources = new LengthAwarePaginator($filtered->forPage($this->getPage(), 15)->values(), $filtered->count(), 15, $this->getPage());

        return view('people::livewire.migration.index', [
            'employees' => $employees,
            'inventoryEmpty' => $inventory->isEmpty(),
            'companies' => $companies,
            'sources' => $sources,
            'rejected' => $companyEntityId === null ? collect() : $ledger->listRejected($companyEntityId),
            'kinds' => MigrationSourceKind::cases(),
            'mayManage' => $authorization->can(Actor::forUser($user), TrainingMigrationSourceStore::MANAGE)->allowed,
            'signed' => $companyEntityId !== null && $store->signedInventory($companyEntityId),
            'mappings' => $companyEntityId === null ? collect() : $mappings->mappings($user, $companyEntityId),
            'windows' => $companyEntityId === null ? collect() : $mappings->writerWindows($user, $companyEntityId),
            'mappingSignoff' => $companyEntityId === null ? null : $mappings->mappingSignoff($user, $companyEntityId),
            'workflows' => MigrationWorkflow::cases(),
            'writers' => MigrationWriter::cases(),
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

        abort_unless($source->signoff === null, 403);
        abort_unless(app(AuthorizationService::class)->can(Actor::forUser($this->user()), TrainingMigrationSourceStore::MANAGE)->allowed, 403);
        $this->showForm = true;
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

    /** Append one field or code mapping to the register. */
    public function mapField(TrainingMigrationMappingStore $store): void
    {
        $companyEntityId = $this->requireCompany();

        try {
            $store->map($this->user(), $companyEntityId, new TrainingMigrationFieldMappingDraft(
                sourceId: (int) $this->mappingSourceId,
                sourceField: $this->mappingSourceField,
                sourceCode: $this->mappingSourceCode,
                targetTable: $this->mappingTargetTable,
                targetColumn: $this->mappingTargetColumn,
                dedupRule: $this->mappingDedupRule,
            ));
        } catch (InvalidTrainingMigrationMappingException $exception) {
            $this->addError('mappingForm', $exception->getMessage());

            return;
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        $this->reset('mappingSourceField', 'mappingSourceCode', 'mappingTargetColumn', 'mappingDedupRule');
        session()->flash('migration-status', __('The mapping was recorded.'));
    }

    /** Declare the authoritative writer of one workflow for a cutover window. */
    public function declareWriter(TrainingMigrationMappingStore $store): void
    {
        $companyEntityId = $this->requireCompany();
        $workflow = MigrationWorkflow::tryFrom($this->windowWorkflow);
        $writer = MigrationWriter::tryFrom($this->windowWriter);
        $startsOn = $this->date($this->windowStartsOn);
        $endsOn = trim($this->windowEndsOn) === '' ? null : $this->date($this->windowEndsOn);
        if ($workflow === null || $writer === null || $startsOn === null || (trim($this->windowEndsOn) !== '' && $endsOn === null)) {
            $this->addError('windowForm', __('Choose a workflow, a writer and a start date (and a valid end date, if any).'));

            return;
        }

        try {
            $store->declareWriter($this->user(), $companyEntityId, $workflow, $writer, $startsOn, $endsOn);
        } catch (InvalidTrainingMigrationMappingException $exception) {
            $this->addError('windowForm', $exception->getMessage());

            return;
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        $this->reset('windowStartsOn', 'windowEndsOn');
        session()->flash('migration-status', __('The writer window was declared.'));
    }

    /** Sign the company's mapping set; nothing is appended to either register afterwards. */
    public function signMappings(TrainingMigrationMappingStore $store): void
    {
        $companyEntityId = $this->requireCompany();

        try {
            $store->signMappings($this->user(), $companyEntityId, $this->mappingSignNote);
        } catch (InvalidTrainingMigrationMappingException $exception) {
            $this->addError('mappingSign', $exception->getMessage());

            return;
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        $this->reset('mappingSignNote');
        session()->flash('migration-status', __('The mapping set was signed.'));
    }

    private function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date !== false && $date->format('Y-m-d') === trim($value) ? $date : null;
    }

    private function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'sourceKey', 'name', 'format', 'ownerEmployeeEntityId', 'estimatedVolume', 'retentionNote', 'dataQualityNote');
        $this->kind = MigrationSourceKind::Workbook->value;
    }

    private function requireCompany(): int
    {
        $this->authorizeView();
        $companyEntityId = $this->companyEntityId;
        abort_unless($companyEntityId !== null && array_key_exists($companyEntityId, $this->allowedCompanies()), 404);

        return $companyEntityId;
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
