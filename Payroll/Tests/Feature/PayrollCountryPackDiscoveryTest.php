<?php

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Services\DomainState;
use App\Domains\People\Payroll\CountryPacks\Malaysia\MalaysiaPayrollCountryPack;
use App\Domains\People\Payroll\Data\CountryPackManifest;
use App\Domains\People\Payroll\Services\PayrollCountryPackDiscoveryService;
use App\Domains\People\Payroll\Services\PayrollCountryPackRegistry;
use Illuminate\Support\Facades\File;

final class PayrollCountryPackDiscoveryDisabledDomainPack extends MalaysiaPayrollCountryPack
{
    public function manifest(): CountryPackManifest
    {
        return new CountryPackManifest(
            countryIso: 'ZZ',
            packIdentifier: 'test/disabled-domain-payroll-pack',
            packVersion: 'test',
            supportedCoreContracts: [PayrollCountryPackRegistry::CORE_CONTRACT_VERSION],
        );
    }
}

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

it('excludes payroll packs contributed by disabled Domains', function (): void {
    $domain = 'ZzPayrollDiscovery'.bin2hex(random_bytes(4));
    $domainPath = ApplicationTopology::domainPath($domain);
    $configDir = $domainPath.DIRECTORY_SEPARATOR.'Probe'.DIRECTORY_SEPARATOR.'Config';
    File::ensureDirectoryExists($configDir);
    File::put(
        $configDir.DIRECTORY_SEPARATOR.'payroll.php',
        '<?php return [\'country_packs\' => [\\'.PayrollCountryPackDiscoveryDisabledDomainPack::class.'::class]];',
    );

    try {
        $enabledRegistry = new PayrollCountryPackRegistry;
        (new PayrollCountryPackDiscoveryService)->discoverInto($enabledRegistry);

        expect($enabledRegistry->hasCountry('ZZ'))->toBeTrue();

        DomainState::disable($domain);

        $disabledRegistry = new PayrollCountryPackRegistry;
        (new PayrollCountryPackDiscoveryService)->discoverInto($disabledRegistry);

        expect($disabledRegistry->hasCountry('MY'))->toBeTrue()
            ->and($disabledRegistry->hasCountry('ZZ'))->toBeFalse();
    } finally {
        DomainState::enable($domain);
        File::deleteDirectory($domainPath);
    }
});
