<?php

namespace App\Domains\People\Training\Console\Commands;

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Training\Services\TrainingRequestReminders;

/**
 * Remind HR of approved training requests that still have no event after the
 * configured age, once per request, recipient and ISO week.
 *
 * Per company, because a reminder is somebody's inbox and inboxes belong to a
 * company. The tenant comes from --tenant, resolved and bound by
 * TenantScopedCommand before handle() runs (belimbing#710).
 */
final class RequestsDueCommand extends TenantScopedCommand
{
    protected $signature = 'people:training:requests-due
                            {--company= : Company workforce entity to run for}
                            {--dry-run : List what is due and record nothing}';

    protected $description = 'Remind HR of approved training requests still waiting for an event';

    public function handle(TenantContext $tenants, TrainingRequestReminders $reminders): int
    {
        $company = $this->option('company');

        if ($company === null || $company === '') {
            $this->error('A reminder run is per company: pass --company=<workforce company entity id>.');

            return self::FAILURE;
        }

        $tenantId = $tenants->requireTenantId();
        $companyId = (int) $company;

        $due = $reminders->due($tenantId, $companyId);

        foreach ($due as $row) {
            $this->line(sprintf(
                'request %d  approved %s  unlinked for %d day(s)  %s',
                $row->requestId,
                $row->approvedOn->toDateString(),
                $row->daysUnlinked,
                $row->need,
            ));
        }

        if ((bool) $this->option('dry-run')) {
            $this->line('Due: '.count($due).'. Nothing was recorded.');

            return self::SUCCESS;
        }

        $this->line('Reminded: '.count($reminders->remind($tenantId, $companyId)));

        return self::SUCCESS;
    }
}
