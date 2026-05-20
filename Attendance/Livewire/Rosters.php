<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Modules\People\Attendance\Livewire\Concerns\BuildsRosterGrid;
use App\Modules\People\Attendance\Livewire\Concerns\BuildsRosterRenderingData;
use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Livewire\Concerns\ManagesRosterOperations;
use App\Modules\People\Attendance\Livewire\Concerns\ManagesRosterSelection;
use App\Modules\People\Attendance\Livewire\Concerns\ManagesRosterSelfService;
use App\Modules\People\Attendance\Livewire\Concerns\ResolvesRosterPolicySchedule;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Rosters extends Component
{
    use BuildsRosterGrid;
    use BuildsRosterRenderingData;
    use InteractsWithAttendanceScreen;
    use ManagesRosterOperations;
    use ManagesRosterSelection;
    use ManagesRosterSelfService;
    use ResolvesRosterPolicySchedule;
    use WithPagination;

    public string $mode = 'list';

    public string $rosterSearch = '';

    public string $rosterDepartmentId = '';

    public string $rosterSupervisorId = '';

    public string $rosterOrganizationUnitId = '';

    public string $rosterCostCenterId = '';

    public string $rosterWorkforceClassId = '';

    public string $rosterEmploymentGroupId = '';

    public string $rosterWorkCalendarId = '';

    public string $rosterPayRateType = '';

    public string $rosterEmployeeStatus = 'active';

    public bool $rosterSelectAllFiltered = false;

    /**
     * @var list<string>
     */
    public array $selectedRosterEmployeeIds = [];

    public string $rosterEmployeeId = '';

    /**
     * When non-empty, `saveRosterAssignment()` updates the named assignment
     * in place instead of creating new rows for the selected population.
     * Set by `editRosterAssignment($id)`; cleared by `resetForm()`.
     */
    public string $editingRosterAssignmentId = '';

    public string $rosterPatternId = '';

    public string $rosterShiftTemplateId = '';

    public string $rosterPolicyGroupId = '';

    public string $rosterEffectiveFrom = '';

    public string $rosterEffectiveTo = '';

    public string $rosterPublishState = 'draft';

    public string $rosterRevisionNote = '';

    public bool $rosterValidationRan = false;

    public bool $rosterWarningsAccepted = false;

    public string $rosterRequiredPerShift = '';

    public string $rosterTemplateKey = '';

    public string $swapFromEmployeeId = '';

    public string $swapToEmployeeId = '';

    public string $swapDate = '';

    public string $spreadsheetRosterRows = '';

    /**
     * Monday of the week being browsed in list mode. Empty defaults to today's
     * Monday. Drives the calendar grid that opens the page; isolated from
     * `rosterEffectiveFrom/To` so navigating the list doesn't mutate the form.
     */
    public string $listWeekAnchor = '';

    /**
     * Zoom level for the list-mode calendar. `week` shows Mon-Sun for the
     * anchor; `month` shows the full month containing the anchor. Period
     * navigation buttons step a week or a month at a time accordingly.
     */
    public string $listScope = 'week';

    /**
     * @var list<int>
     */
    public array $lastDraftAssignmentIds = [];

    /**
     * When true, the grid overlays actual attendance outcomes from
     * AttendanceDay records onto the planned cells.
     */
    public bool $actualMode = false;

    public function mount(): void
    {
        $this->rosterEffectiveFrom = now()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, $this->rosterFilterProperties(), true)) {
            $this->resetPage();
            $this->rosterSelectAllFiltered = false;
        }
    }

    public function render(): View
    {
        $companyId = $this->companyId();
        $schemaReady = $this->schemaReady();
        $isMySchedule = $this->isMyScheduleMode();
        $canManage = $this->canAttendance('people.attendance.manage');
        $canUnlock = $this->canAttendance('people.attendance.roster.unlock');

        $viewData = $schemaReady
            ? $this->renderDataForReadySchema($companyId)
            : $this->renderDataForUnreadySchema();

        $gridStart = $this->gridPeriodStart()->toDateString();
        $gridEnd = $this->gridPeriodEnd()->toDateString();
        $rosterGridRows = $viewData['rosterGridRows'] ?? collect();
        $lockedDates = $viewData['lockedDates'] ?? [];

        return view('livewire.people.attendance.rosters', [
            'schemaReady' => $schemaReady,
            'canManage' => $canManage,
            'canUnlock' => $canUnlock,
            'isMySchedule' => $isMySchedule,
            'actualMode' => $this->actualMode,
            'currentPeriodLocked' => ! empty($lockedDates),
            'acknowledgedForPeriod' => $isMySchedule && $schemaReady ? $this->acknowledgedForPeriod($gridStart, $gridEnd) : false,
            'acknowledgmentCount' => $canManage && $schemaReady ? $this->acknowledgmentCountForPeriod($rosterGridRows, $gridStart, $gridEnd) : null,
            'gridPeriodStart' => $gridStart,
            'gridPeriodEnd' => $gridEnd,
            'organizationUnits' => $this->referenceOptions(PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT, $schemaReady),
            'costCenters' => $this->referenceOptions(PeopleReferenceEntry::TYPE_COST_CENTER, $schemaReady),
            'employmentGroups' => $this->referenceOptions(PeopleReferenceEntry::TYPE_EMPLOYMENT_GROUP, $schemaReady),
            'workforceClasses' => $this->referenceOptions(PeopleReferenceEntry::TYPE_WORKFORCE_CLASS, $schemaReady),
            'workCalendars' => $this->referenceOptions(PeopleReferenceEntry::TYPE_WORK_CALENDAR, $schemaReady),
            ...$viewData,
        ]);
    }
}
