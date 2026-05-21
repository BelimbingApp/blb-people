<?php

namespace App\Modules\People\Attendance\Livewire\Concerns;

trait ManagesRosterSelection
{
    public function selectVisibleRosterEmployees(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $ids = $this->filteredEmployeesQuery()
            ->orderBy('employees.full_name')
            ->orderBy('employees.id')
            ->forPage($this->getPage(), 25)
            ->pluck('employees.id')
            ->map(fn (int $id): string => (string) $id)
            ->all();

        $this->selectedRosterEmployeeIds = array_values(array_unique([
            ...$this->selectedRosterEmployeeIds,
            ...$ids,
        ]));
    }

    public function selectAllFilteredRosterEmployees(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->rosterSelectAllFiltered = true;
        $this->selectedRosterEmployeeIds = [];
    }

    public function clearRosterSelection(): void
    {
        $this->rosterSelectAllFiltered = false;
        $this->selectedRosterEmployeeIds = [];
        $this->rosterEmployeeId = '';
    }

    public function clearRosterFilters(): void
    {
        $this->reset($this->rosterFilterProperties());
        $this->rosterEmployeeStatus = 'active';
        $this->clearRosterSelection();
        $this->resetPage();
    }

    public function goToPreviousWeek(): void
    {
        if ($this->listScope === 'month') {
            $this->listWeekAnchor = $this->listScopeStart()->subMonth()->toDateString();

            return;
        }

        $this->listWeekAnchor = $this->listWeekStart()->subDays(7)->toDateString();
    }

    public function goToNextWeek(): void
    {
        if ($this->listScope === 'month') {
            $this->listWeekAnchor = $this->listScopeStart()->addMonth()->toDateString();

            return;
        }

        $this->listWeekAnchor = $this->listWeekStart()->addDays(7)->toDateString();
    }

    public function goToThisWeek(): void
    {
        $this->listWeekAnchor = '';
    }

    public function setListScope(string $scope): void
    {
        $this->listScope = in_array($scope, ['week', 'month'], true) ? $scope : 'week';
    }
}
