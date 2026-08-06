<?php

namespace App\Domains\People\Employees\Http\Controllers;

use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\People\Employees\Services\EmployeePayrollReadinessService;
use App\Domains\People\Employees\Services\EmployeeWorkbenchExportBuilder;
use App\Domains\People\Employees\Services\EmployeeWorkbenchQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeWorkbenchExportController
{
    public function __invoke(
        Request $request,
        EmployeeWorkbenchQuery $workbenchQuery,
        EmployeePayrollReadinessService $readiness,
        EmployeeWorkbenchExportBuilder $exportBuilder,
    ): StreamedResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $query = $workbenchQuery->build($this->companyTreeIds((int) $user->company_id));
        $workbenchQuery->applyFilters($query, $request->query(), $readiness);

        $export = $exportBuilder->csv(
            $query
                ->orderBy('employees.full_name')
                ->orderBy('employees.id')
                ->get()
        );

        return new StreamedResponse(
            function () use ($export): void {
                echo $export['content'];
            },
            200,
            [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
                'Cache-Control' => 'no-store, max-age=0',
            ],
        );
    }

    /**
     * @return list<int>
     */
    private function companyTreeIds(int $rootCompanyId): array
    {
        $ids = [];
        $queue = [$rootCompanyId ?: Company::LICENSEE_ID];

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

        return array_values(array_unique($ids));
    }
}
