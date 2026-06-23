<?php

namespace App\Modules\People\Payroll\Services;

use App\Modules\People\Payroll\Exceptions\PayrollCountryPackException;
use App\Modules\People\Payroll\Models\PayrollRun;

/**
 * Guards payroll finalization against a missing statutory country pack.
 *
 * The engine calculates a run even when no country pack is installed for its country
 * (the calculator records a `no_country_pack` status rather than failing), so a run
 * can look complete while omitting every statutory deduction. This guard turns that
 * into a readiness gap and a hard stop on approval/close/export — the points where an
 * incomplete run would otherwise be treated as final.
 */
class PayrollRunCountryPackGuard
{
    public function __construct(private readonly PayrollCountryPackRegistry $registry) {}

    /**
     * The run's country ISO when its statutory pack is NOT installed — a readiness gap.
     * Null when the country has an installed pack, or when the calendar has no country
     * (a separate calendar-configuration concern, not a missing pack).
     */
    public function missingCountryPack(PayrollRun $run): ?string
    {
        $countryIso = $run->calendar?->country_iso;

        if (! is_string($countryIso) || $countryIso === '') {
            return null;
        }

        return $this->registry->hasCountry($countryIso) ? null : strtoupper($countryIso);
    }

    /**
     * @throws PayrollCountryPackException when the run's country has no installed pack.
     */
    public function assertReadyForFinalization(PayrollRun $run): void
    {
        $missing = $this->missingCountryPack($run);

        if ($missing !== null) {
            throw PayrollCountryPackException::companyCountryHasNoInstalledPack($missing);
        }
    }
}
