<?php

namespace App\Domains\People\Performance\Console\Commands;

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Performance\Enums\OverdueReviewReason;
use App\Domains\People\Performance\Services\OverdueReviewEscalations;
use App\Domains\People\Performance\Services\OverdueReviewReminders;

/**
 * Record this week's reminders for reviews that have gone quiet.
 *
 * Per company, because a reminder is somebody's inbox and inboxes belong to a
 * company. The tenant comes from --tenant, resolved and bound by
 * TenantScopedCommand before handle() runs (#316).
 */
final class OverdueReviewsCommand extends TenantScopedCommand
{
    protected $signature = 'people:performance:overdue
                            {--company= : Company workforce entity to run for}
                            {--dry-run : Report what would be recorded and record nothing}';

    protected $description = 'Record weekly reminders for stale draft reviews and unanswered released reviews';

    public function handle(
        TenantContext $tenants,
        OverdueReviewReminders $reminders,
        OverdueReviewEscalations $escalations,
    ): int {

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
            // Escalations are read from the reminder rows already on the table,
            // so a dry run reports them from what is written today rather than
            // from the reminders this run would have added.
            $this->line('Would escalate: '.count($escalations->due($tenantId, $companyId)));
            $this->line('Nothing was recorded.');

            return self::SUCCESS;
        }

        $written = $reminders->remind($tenantId, $companyId);
        $this->report('Recorded', $this->countByReason(array_map(
            static fn (object $reminder): OverdueReviewReason => $reminder->reason,
            $written,
        )));

        // After reminding, not before: this week's reminder is half of what
        // makes two consecutive weeks, so escalating first would always be a
        // week behind.
        $this->line('Escalated: '.count($escalations->escalate($tenantId, $companyId)));

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
