<?php

namespace App\Domains\People\Skills\Console\Commands;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Skills\Enums\ReminderRule;
use App\Domains\People\Skills\Services\ReminderRules;
use Illuminate\Console\Command;

/**
 * Report how many reminders are due, and send none.
 *
 * The scheduler skeleton for [0008-a]. Counting first is deliberate: it lets
 * the rules run on real data and be argued about before anybody's inbox is
 * involved, and a rule that is wrong is much cheaper to find here.
 */
final class RemindersDueCommand extends Command
{
    protected $signature = 'people:reminders-due
                            {--tenant= : Tenant to report on; defaults to the current tenant context}
                            {--company= : Company workforce entity to report on}
                            {--days= : Days ahead to treat a certificate as expiring}';

    protected $description = 'Report reminders that are due, without sending anything';

    public function handle(TenantContext $tenants, ReminderRules $rules): int
    {
        $tenantOption = $this->option('tenant');

        if ($tenantOption !== null && $tenantOption !== '') {
            $tenants->set((int) $tenantOption);
        }

        $company = $this->option('company');

        if ($company === null || $company === '') {
            $this->error('A reminder report is per company: pass --company=<workforce company entity id>.');

            return self::FAILURE;
        }

        $days = $this->option('days');
        $due = $rules->due(
            (int) $company,
            null,
            $days === null || $days === '' ? ReminderRules::DEFAULT_EXPIRING_WITHIN_DAYS : (int) $days,
        );

        $counts = [];

        foreach (ReminderRule::cases() as $rule) {
            $counts[$rule->value] = count(array_filter($due, static fn ($reminder): bool => $reminder->rule === $rule));
        }

        foreach ($counts as $rule => $count) {
            $this->line("{$rule}: {$count}");
        }

        $this->line('Nothing was sent.');

        return self::SUCCESS;
    }
}
