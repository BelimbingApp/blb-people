<?php

namespace App\Domains\People\Performance\Console\Commands;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Performance\Enums\OverdueReviewReason;
use App\Domains\People\Performance\Services\OverdueReviewReminders;
use Illuminate\Console\Command;

/**
 * Record this week's reminders for reviews that have gone quiet.
 *
 * Per company, because a reminder is somebody's inbox and inboxes belong to a
 * company. Tenancy is taken from --tenant, or from the ambient context when
 * the command runs inside a request-shaped scope.
 */
final class OverdueReviewsCommand extends Command
{
    protected $signature = 'people:performance:overdue
                            {--tenant= : Tenant to run for; defaults to the current tenant context}
                            {--company= : Company workforce entity to run for}
                            {--dry-run : Report what would be recorded and record nothing}';

    protected $description = 'Record weekly reminders for stale draft reviews and unanswered released reviews';

    public function handle(TenantContext $tenants, OverdueReviewReminders $reminders): int
    {
        $tenantOption = $this->option('tenant');

        if ($tenantOption !== null && $tenantOption !== '') {
            $tenants->set((int) $tenantOption);
        }

        $company = $this->option('company');

        if ($company === null || $company === '') {
            $this->error('A reminder run is per company: pass --company=<workforce company entity id>.');

            return self::FAILURE;
        }

        $tenantId = $tenants->requireTenantId();
        $companyId = (int) $company;

        if ((bool) $this->option('dry-run')) {
            $due = $reminders->due($tenantId, $companyId);
            $this->report('Would record', $this->countByReason(array_map(
                static fn (object $overdue): OverdueReviewReason => $overdue->reason,
                $due,
            )));
            $this->line('Nothing was recorded.');

            return self::SUCCESS;
        }

        $written = $reminders->remind($tenantId, $companyId);
        $this->report('Recorded', $this->countByReason(array_map(
            static fn (object $reminder): OverdueReviewReason => $reminder->reason,
            $written,
        )));

        return self::SUCCESS;
    }

    /**
     * @param  list<OverdueReviewReason>  $reasons
     * @return array<string, int>
     */
    private function countByReason(array $reasons): array
    {
        $counts = [];

        foreach (OverdueReviewReason::cases() as $reason) {
            $counts[$reason->value] = count(array_filter(
                $reasons,
                static fn (OverdueReviewReason $seen): bool => $seen === $reason,
            ));
        }

        return $counts;
    }

    /** @param  array<string, int>  $counts */
    private function report(string $verb, array $counts): void
    {
        foreach ($counts as $reason => $count) {
            $this->line("{$verb} {$reason}: {$count}");
        }
    }
}
