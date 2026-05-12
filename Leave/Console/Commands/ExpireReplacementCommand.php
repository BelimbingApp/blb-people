<?php

namespace App\Modules\People\Leave\Console\Commands;

use App\Modules\People\Leave\Services\ReplacementLeaveExpiryService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:leave:expire-replacement')]
class ExpireReplacementCommand extends Command
{
    protected $description = 'Sweep replacement-leave ledger entries past their expiry and record expired reversals';

    protected $signature = 'blb:leave:expire-replacement
                            {--as-of= : ISO date to evaluate against (defaults to today)}
                            {--dry-run : Report what would expire without writing ledger entries}';

    public function handle(ReplacementLeaveExpiryService $service): int
    {
        $asOf = $this->option('as-of') ? new DateTimeImmutable($this->option('as-of')) : null;
        $dryRun = (bool) $this->option('dry-run');

        $count = $service->sweep($asOf, $dryRun);

        $this->info(sprintf(
            '%s%d replacement-leave entry(ies) expired%s.',
            $dryRun ? '[dry-run] ' : '',
            $count,
            $dryRun ? ' (no ledger writes)' : '',
        ));

        return self::SUCCESS;
    }
}
