<?php

namespace App\Modules\People\Payroll\Inventory;

use App\Base\Software\Inventory\Contracts\InventoryContributionProvider;
use App\Base\Software\Inventory\ContributionSummary;
use App\Modules\People\Payroll\Services\PayrollCountryPackRegistry;

/**
 * Reports the Payroll country packs registered on the country-pack seam to the
 * Software Inventory, so an operator sees "Payroll rules (MY)" as a contribution of
 * the Payroll engine without the inventory knowing how payroll is calculated.
 *
 * Read-only adapter over PayrollCountryPackRegistry — the host module owns the seam.
 */
class PayrollInventoryContributionProvider implements InventoryContributionProvider
{
    public function __construct(private readonly PayrollCountryPackRegistry $registry) {}

    /**
     * @return list<ContributionSummary>
     */
    public function contributions(): array
    {
        $summaries = [];

        foreach ($this->registry->all() as $pack) {
            $manifest = $pack->manifest();

            $summaries[] = new ContributionSummary(
                hostModule: 'people/payroll',
                seam: 'payroll.country-pack',
                kind: ContributionSummary::KIND_ADAPTER,
                label: __('Payroll rules (:country)', ['country' => $manifest->normalizedCountryIso()]),
                providerModule: 'people/payroll',
                metadata: [
                    'country' => $manifest->normalizedCountryIso(),
                    'pack' => $manifest->packIdentifier,
                    'version' => $manifest->packVersion,
                ],
            );
        }

        return $summaries;
    }
}
