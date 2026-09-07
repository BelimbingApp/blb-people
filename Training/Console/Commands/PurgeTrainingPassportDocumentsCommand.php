<?php

namespace App\Domains\People\Training\Console\Commands;

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Domains\People\Training\Services\TrainingPassportDocumentStore;

/**
 * Retention for printable training passports (0014-a): drops the stored PDF
 * of every document past its 30-day window in the bound tenant (`--tenant`).
 * The document row and its audit trail remain.
 */
final class PurgeTrainingPassportDocumentsCommand extends TenantScopedCommand
{
    protected $signature = 'people:training-passport:purge';

    protected $description = 'Delete the stored PDF of training passport documents past their retention window in one tenant.';

    public function handle(TrainingPassportDocumentStore $documents): int
    {
        $purged = $documents->purgeExpired();
        $this->info(sprintf('Purged %d expired training passport document(s).', $purged));

        return self::SUCCESS;
    }
}
