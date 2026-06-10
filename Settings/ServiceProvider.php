<?php

namespace App\Modules\People\Settings;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Settings\Models\EmployeePortalAccess;
use App\Modules\People\Settings\Models\EmployeeProfileChangeRequest;
use App\Modules\People\Settings\Models\EmployeeWorkProfile;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people-settings');

        $this->registerEmployeeRelations();
    }

    /**
     * Attach People-owned relations to the Core Employee model.
     *
     * Registered here (not on the Employee class) so Core never imports
     * People classes; consumers keep using the same relation names.
     */
    private function registerEmployeeRelations(): void
    {
        Employee::resolveRelationUsing('workProfile', fn (Employee $employee) => $employee->hasOne(EmployeeWorkProfile::class, 'employee_id'));
        Employee::resolveRelationUsing('portalAccess', fn (Employee $employee) => $employee->hasOne(EmployeePortalAccess::class, 'employee_id'));
        Employee::resolveRelationUsing('profileChangeRequests', fn (Employee $employee) => $employee->hasMany(EmployeeProfileChangeRequest::class, 'employee_id'));
    }
}
