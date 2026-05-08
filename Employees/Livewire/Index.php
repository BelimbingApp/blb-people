<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\People\Employees\Livewire;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $sortBy = 'full_name';

    public string $sortDir = 'asc';

    private const SORTABLE = [
        'full_name' => 'employees.full_name',
        'company_name' => 'companies.name',
        'employee_type_label' => 'employee_types.label',
        'status' => 'employees.status',
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'full_name' => 'asc',
                'company_name' => 'asc',
                'employee_type_label' => 'asc',
                'status' => 'asc',
            ],
        );
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'terminated' => 'danger',
            'probation' => 'warning',
            'inactive', 'pending' => 'default',
            default => 'default',
        };
    }

    public function render(): View
    {
        $companyIds = $this->licenseeGroupIds();
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'employees.full_name';

        return view('livewire.people.employees.index', [
            'employees' => Employee::query()
                ->select('employees.*')
                ->with('company', 'department.type', 'employeeType')
                ->leftJoin('companies', 'employees.company_id', '=', 'companies.id')
                ->leftJoin('employee_types', 'employees.employee_type', '=', 'employee_types.code')
                ->whereIn('employees.company_id', $companyIds)
                ->when($this->search !== '', function (Builder $query): void {
                    $this->applySearch($query, $this->search);
                })
                ->orderBy($sortColumn, $this->sortDir)
                ->orderByDesc('employees.id')
                ->paginate(15),
        ]);
    }

    private function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $q) use ($search): void {
            $q->where('employees.full_name', 'like', '%'.$search.'%')
                ->orWhere('employees.short_name', 'like', '%'.$search.'%')
                ->orWhere('employees.employee_number', 'like', '%'.$search.'%')
                ->orWhere('employees.email', 'like', '%'.$search.'%')
                ->orWhere('employees.designation', 'like', '%'.$search.'%');
        });
    }

    /**
     * BFS over the licensee company tree.
     *
     * Returns the licensee company ID plus all descendant IDs so employees
     * from subsidiaries are included without relying on recursive SQL.
     *
     * @return list<int>
     */
    private function licenseeGroupIds(): array
    {
        $ids = [];
        $queue = [Company::LICENSEE_ID];

        while ($queue !== []) {
            $batch = $queue;
            $queue = [];
            array_push($ids, ...$batch);

            $children = Company::query()
                ->whereIn('parent_id', $batch)
                ->pluck('id')
                ->all();

            array_push($queue, ...$children);
        }

        return $ids;
    }
}
