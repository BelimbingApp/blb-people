<?php

use App\Modules\People\Payroll\CountryPacks\Malaysia\MalaysiaPayrollCountryPack;
use App\Modules\People\Payroll\Services\PayrollCountryPackDiscoveryService;
use App\Modules\People\Payroll\Services\PayrollCountryPackRegistry;
use Illuminate\Support\Facades\File;

it('discovers the Malaysia country pack at boot through Config/payroll.php', function (): void {
    $registry = app(PayrollCountryPackRegistry::class);

    expect($registry->hasCountry('MY'))->toBeTrue()
        ->and($registry->forCountry('MY'))->toBeInstanceOf(MalaysiaPayrollCountryPack::class);
});

it('registers a country pack declared in a discovered payroll config', function (): void {
    $root = storage_path('framework/testing/payroll-discovery-'.bin2hex(random_bytes(4)));
    $configDir = $root.'/People/Payroll/Config';
    File::ensureDirectoryExists($configDir);
    file_put_contents(
        $configDir.'/payroll.php',
        '<?php return [\'country_packs\' => [\\'.MalaysiaPayrollCountryPack::class.'::class]];',
    );

    try {
        $registry = new PayrollCountryPackRegistry;
        (new PayrollCountryPackDiscoveryService([$root.'/*/*/Config/payroll.php']))->discoverInto($registry);

        expect($registry->hasCountry('MY'))->toBeTrue();
    } finally {
        File::deleteDirectory($root);
    }
});

it('ignores discovery files that declare no country packs', function (): void {
    $root = storage_path('framework/testing/payroll-discovery-'.bin2hex(random_bytes(4)));
    $configDir = $root.'/People/Payroll/Config';
    File::ensureDirectoryExists($configDir);
    file_put_contents($configDir.'/payroll.php', '<?php return [];');

    try {
        $registry = new PayrollCountryPackRegistry;
        (new PayrollCountryPackDiscoveryService([$root.'/*/*/Config/payroll.php']))->discoverInto($registry);

        expect($registry->countries())->toBe([]);
    } finally {
        File::deleteDirectory($root);
    }
});
