<?php

namespace App\Modules\People\Settings\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Settings\Models\EmployeePortalAccess;
use App\Modules\People\Settings\Models\EmployeeProfileChangeRequest;
use App\Modules\People\Settings\Models\PeopleImportJob;
use App\Modules\People\Settings\Models\PeopleNotificationDeliveryLog;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use App\Modules\People\Settings\Models\PeopleRestrictedPersonEntry;
use App\Modules\People\Settings\Services\PeopleReferenceImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $tab = 'reference-data';

    public string $search = '';

    public bool $showReferenceEntryModal = false;

    public string $referenceType = PeopleReferenceEntry::TYPE_COST_CENTER;

    public string $entryCode = '';

    public string $entryName = '';

    public ?string $entryLevel = null;

    public ?string $entrySourceLabel = null;

    public function setTab(string $tab): void
    {
        $allowed = ['reference-data', 'portal-access', 'requests', 'restricted', 'imports', 'operations'];

        if (in_array($tab, $allowed, true)) {
            $this->tab = $tab;
            $this->resetPage();
        }
    }

    public function createReferenceEntry(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'referenceType' => ['required', 'string', 'in:'.implode(',', array_keys(PeopleReferenceEntry::labels()))],
            'entryCode' => ['required', 'string', 'max:80'],
            'entryName' => ['required', 'string', 'max:255'],
            'entryLevel' => ['nullable', 'string', 'max:80'],
            'entrySourceLabel' => ['nullable', 'string', 'max:120'],
        ]);

        PeopleReferenceEntry::query()->updateOrCreate(
            [
                'company_id' => $this->companyId(),
                'type' => $validated['referenceType'],
                'code' => mb_strtoupper($validated['entryCode']),
            ],
            [
                'name' => $validated['entryName'],
                'level' => $validated['entryLevel'] ?: null,
                'source_system' => $validated['entrySourceLabel'] !== null && $validated['entrySourceLabel'] !== '' ? 'manual' : null,
                'source_label' => $validated['entrySourceLabel'] ?: null,
                'source_code' => $validated['entryCode'],
                'status' => PeopleReferenceEntry::STATUS_ACTIVE,
            ],
        );

        $this->reset('entryCode', 'entryName', 'entryLevel', 'entrySourceLabel');
        $this->showReferenceEntryModal = false;
        session()->flash('success', __('Reference entry saved.'));
    }

    public function dryRunSampleImport(PeopleReferenceImportService $imports): void
    {
        $this->authorizeManage();

        $imports->import(
            companyId: $this->companyId(),
            targetType: $this->referenceType,
            rows: [],
            dryRun: true,
            sourceLabel: PeopleReferenceEntry::labels()[$this->referenceType] ?? $this->referenceType,
            createdByUserId: Auth::id(),
        );

        session()->flash('success', __('Empty dry-run import recorded. Upload parsing is intentionally scoped to dedicated import jobs.'));
    }

    public function render(): View
    {
        $companyId = $this->companyId();
        $canManage = app(AuthorizationService::class)
            ->can(Actor::forUser(Auth::user()), 'people.settings.manage')
            ->allowed;
        $canViewSensitive = app(AuthorizationService::class)
            ->can(Actor::forUser(Auth::user()), 'people.settings.restricted.view')
            ->allowed;

        return view('people-settings::livewire.people.settings.index', [
            'tabs' => [
                ['id' => 'reference-data', 'label' => __('Reference Data'), 'icon' => 'heroicon-o-table-cells'],
                ['id' => 'portal-access', 'label' => __('Portal Access'), 'icon' => 'heroicon-o-key'],
                ['id' => 'requests', 'label' => __('Profile Requests'), 'icon' => 'heroicon-o-inbox-arrow-down'],
                ['id' => 'restricted', 'label' => __('Restricted People'), 'icon' => 'heroicon-o-shield-exclamation'],
                ['id' => 'imports', 'label' => __('Imports'), 'icon' => 'heroicon-o-arrow-up-tray'],
                ['id' => 'operations', 'label' => __('Operations'), 'icon' => 'heroicon-o-bell-alert'],
            ],
            'referenceTypes' => PeopleReferenceEntry::labels(),
            'referenceEntries' => PeopleReferenceEntry::query()
                ->forCompany($companyId)
                ->when($this->search !== '', fn ($query) => $query->where(fn ($q) => $q
                    ->where('code', 'like', '%'.$this->search.'%')
                    ->orWhere('name', 'like', '%'.$this->search.'%')
                    ->orWhere('source_label', 'like', '%'.$this->search.'%')))
                ->orderBy('type')
                ->orderBy('code')
                ->paginate(15, pageName: 'referencePage'),
            'portalAccesses' => EmployeePortalAccess::query()
                ->with('employee', 'user')
                ->whereHas('employee', fn ($query) => $query->where('company_id', $companyId))
                ->latest('id')
                ->limit(20)
                ->get(),
            'profileRequests' => EmployeeProfileChangeRequest::query()
                ->with('employee', 'requestedBy')
                ->whereHas('employee', fn ($query) => $query->where('company_id', $companyId))
                ->latest('id')
                ->limit(20)
                ->get(),
            'restrictedEntries' => $canViewSensitive
                ? PeopleRestrictedPersonEntry::query()->where('company_id', $companyId)->latest('id')->limit(20)->get()
                : collect(),
            'importJobs' => PeopleImportJob::query()->where('company_id', $companyId)->latest('id')->limit(20)->get(),
            'notificationLogs' => PeopleNotificationDeliveryLog::query()->where('company_id', $companyId)->latest('id')->limit(20)->get(),
            'canManage' => $canManage,
            'canViewSensitive' => $canViewSensitive,
        ]);
    }

    private function companyId(): int
    {
        return Auth::user()?->company_id ?? Company::LICENSEE_ID;
    }

    private function authorizeManage(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'people.settings.manage',
        );
    }
}
