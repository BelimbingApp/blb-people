<?php

namespace App\Modules\People\Settings\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Settings\Models\EmployeePortalAccess;
use App\Modules\People\Settings\Models\PeopleNotificationDeliveryLog;

class EmployeePortalAccessService
{
    public function provision(Employee $employee, ?User $user = null, ?string $loginIdentifier = null, ?string $email = null): EmployeePortalAccess
    {
        return EmployeePortalAccess::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'user_id' => $user?->id,
                'login_identifier' => $loginIdentifier ?? $user?->email ?? $employee->employee_number,
                'display_name' => $user?->name ?? $employee->displayName(),
                'email' => $email ?? $user?->email ?? $employee->email,
                'status' => EmployeePortalAccess::STATUS_PENDING,
            ],
        );
    }

    public function sendAccessInvitation(EmployeePortalAccess $access, ?int $companyId = null): PeopleNotificationDeliveryLog
    {
        $access->markInvited();

        return PeopleNotificationDeliveryLog::query()->create([
            'company_id' => $companyId ?? $access->employee?->company_id,
            'notifiable_type' => $access::class,
            'notifiable_id' => $access->id,
            'channel' => 'email',
            'recipient' => $access->email,
            'subject' => 'Employee access invitation',
            'status' => 'queued',
            'metadata' => [
                'login_identifier' => $access->login_identifier,
                'employee_id' => $access->employee_id,
            ],
        ]);
    }
}
