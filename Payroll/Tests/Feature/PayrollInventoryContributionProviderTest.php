<?php

use App\Base\Software\Services\InventoryContributionRegistry;
use App\Modules\People\Payroll\Inventory\PayrollInventoryContributionProvider;

it('reports each registered country pack as an inventory contribution', function (): void {
    $malaysia = collect(app(PayrollInventoryContributionProvider::class)->contributions())
        ->firstWhere(fn ($contribution): bool => ($contribution->metadata['country'] ?? null) === 'MY');

    expect($malaysia)->not->toBeNull()
        ->and($malaysia->hostModule)->toBe('people/payroll')
        ->and($malaysia->seam)->toBe('payroll.country-pack')
        ->and($malaysia->kind)->toBe('adapter')
        ->and($malaysia->providerModule)->toBe('people/payroll');
});

it('is discovered into the Base inventory contribution registry via Config/inventory.php', function (): void {
    $hasMalaysia = collect(app(InventoryContributionRegistry::class)->contributions())
        ->contains(fn ($contribution): bool => $contribution->hostModule === 'people/payroll'
            && ($contribution->metadata['country'] ?? null) === 'MY');

    expect($hasMalaysia)->toBeTrue();
});
