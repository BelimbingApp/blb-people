<?php

namespace App\Domains\People\Settings\Services;

use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\PeopleNotificationDeliveryLog;

class EmployeePortalAccessService
{
    /**
     * Re-provisioning (e.g. to correct a login identifier or email) must not
     * demote an already-active or already-revoked record back to pending —
     * that status is now load-bearing for BelimbingApp/blb-people#25: the
     * first-party adapter only projects an employee's platform-user link
     * while this record reads active. Only a brand-new record defaults to
     * pending; an existing one keeps its status.
     */
    public function provision(Employee $employee, ?User $user = null, ?string $loginIdentifier = null, ?string $email = null): EmployeePortalAccess
    {
        $access = EmployeePortalAccess::query()->firstOrNew(['employee_id' => $employee->id]);
        $access->fill([
            'user_id' => $user?->id,
            'login_identifier' => $loginIdentifier ?? $user?->email ?? $employee->employee_number,
            'display_name' => $user?->name ?? $employee->displayName(),
            'email' => $email ?? $user?->email ?? $employee->email,
        ]);

        if (! $access->exists) {
            $access->status = EmployeePortalAccess::STATUS_PENDING;
        }

        $access->save();

        return $access;
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
