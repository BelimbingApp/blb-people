<?php

namespace App\Modules\People\Payroll\Services;

use App\Modules\People\Payroll\Contracts\PayrollCountryPack;

/**
 * Discovers Payroll country packs declared in `Config/payroll.php` files across
 * modules and extensions, and registers them into PayrollCountryPackRegistry.
 *
 * A contributing bundle declares its packs by class:
 *
 *     return ['country_packs' => [\Vendor\Pack\SomeCountryPack::class]];
 *
 * Modelled on Commerce's extension-seam discovery (CommercePluginDiscoveryService),
 * but stricter: discovery does not swallow registration failures. The registry's
 * duplicate-country and unsupported-contract guards throw, so a misconfigured or
 * conflicting pack fails loudly rather than silently no-opping — financial and
 * regulatory code must never be quietly absent.
 */
class PayrollCountryPackDiscoveryService
{
    /**
     * @param  list<string>|null  $scanPatterns  glob patterns; null uses the defaults.
     */
    public function __construct(private readonly ?array $scanPatterns = null) {}

    public function discoverInto(PayrollCountryPackRegistry $registry): void
    {
        foreach ($this->packClasses() as $class) {
            $registry->register(app($class));
        }
    }

    /**
     * @return list<class-string<PayrollCountryPack>>
     */
    private function packClasses(): array
    {
        $classes = [];

        foreach ($this->scanPatterns ?? $this->defaultScanPatterns() as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $config = require $file;

                if (! is_array($config)) {
                    continue;
                }

                foreach ($config['country_packs'] ?? [] as $class) {
                    if (is_string($class) && is_subclass_of($class, PayrollCountryPack::class)) {
                        $classes[$class] = $class;
                    }
                }
            }
        }

        return array_values($classes);
    }

    /**
     * @return list<string>
     */
    private function defaultScanPatterns(): array
    {
        return [
            base_path('app/Modules/*/*/Config/payroll.php'),
            base_path('extensions/*/*/Config/payroll.php'),
        ];
    }
}
