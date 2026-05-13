<?php

namespace App\Modules\People\Payroll\Console\Commands;

use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Services\PayrollContributionIntake;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Safety-net sweep that materialises pending payroll contributions into
 * the open run(s) covering their period_anchor.
 *
 * Pending materialisation runs automatically on PayrollRun creation; this
 * command is for cases where a run was created before pending rows arrived,
 * or where a run was reopened (status returned from reviewed/approved back
 * to draft/calculated) and we want to sweep the backlog.
 *
 * See docs/architecture/payroll-intake.md.
 */
#[AsCommand(name: 'blb:payroll:materialize-pending')]
class MaterializePendingContributionsCommand extends Command
{
    protected $description = 'Sweep pending payroll contributions into open runs that cover their period anchor';

    protected $signature = 'blb:payroll:materialize-pending
                            {--company= : Limit to a single company ID}
                            {--run= : Sweep against one specific payroll run ID}';

    public function handle(PayrollContributionIntake $intake): int
    {
        $runs = PayrollRun::query()
            ->whereIn('status', [PayrollRun::STATUS_DRAFT, PayrollRun::STATUS_CALCULATED])
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', (int) $id))
            ->when($this->option('run'), fn ($q, $id) => $q->whereKey((int) $id))
            ->with('period')
            ->orderBy('id')
            ->get();

        if ($runs->isEmpty()) {
            $this->info('No open runs match the filters; nothing to sweep.');

            return self::SUCCESS;
        }

        $totals = ['candidates' => 0, 'materialised' => 0, 'rejected_locked' => 0, 'skipped' => 0];

        foreach ($runs as $run) {
            $summary = $intake->materializePendingForRun($run);
            foreach ($totals as $k => $_) {
                $totals[$k] += $summary[$k];
            }

            $this->line(sprintf(
                'Run %d (%s, period %s..%s): %d candidates, %d materialised, %d rejected_locked, %d skipped.',
                $run->id,
                $run->code,
                $run->period?->starts_on?->toDateString() ?? '?',
                $run->period?->ends_on?->toDateString() ?? '?',
                $summary['candidates'],
                $summary['materialised'],
                $summary['rejected_locked'],
                $summary['skipped'],
            ));
        }

        $this->info(sprintf(
            'Totals across %d run(s): %d candidates, %d materialised, %d rejected_locked, %d skipped.',
            $runs->count(),
            $totals['candidates'],
            $totals['materialised'],
            $totals['rejected_locked'],
            $totals['skipped'],
        ));

        return self::SUCCESS;
    }
}
