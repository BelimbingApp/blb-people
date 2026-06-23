<?php

use App\Modules\People\Payroll\Exceptions\PayrollCountryPackException;
use App\Modules\People\Payroll\Models\PayrollCalendar;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Services\PayrollRunCountryPackGuard;

function payrollRunForCountry(?string $iso): PayrollRun
{
    $run = new PayrollRun;

    if ($iso === null) {
        $run->setRelation('calendar', null);

        return $run;
    }

    $calendar = new PayrollCalendar;
    $calendar->country_iso = $iso;
    $run->setRelation('calendar', $calendar);

    return $run;
}

it('flags a run whose company country has no installed pack as a readiness gap', function (): void {
    $guard = app(PayrollRunCountryPackGuard::class);

    expect($guard->missingCountryPack(payrollRunForCountry('SG')))->toBe('SG')
        ->and($guard->missingCountryPack(payrollRunForCountry('MY')))->toBeNull()
        ->and($guard->missingCountryPack(payrollRunForCountry(null)))->toBeNull();
});

it('blocks finalization when the country pack is missing and allows it when installed', function (): void {
    $guard = app(PayrollRunCountryPackGuard::class);

    expect(fn () => $guard->assertReadyForFinalization(payrollRunForCountry('SG')))
        ->toThrow(PayrollCountryPackException::class, 'no [SG] payroll country pack is installed');

    $guard->assertReadyForFinalization(payrollRunForCountry('MY'));

    expect(true)->toBeTrue();
});
