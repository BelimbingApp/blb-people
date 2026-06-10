<?php

use App\Modules\People\Leave\Contracts\LeaveCountryPack;
use App\Modules\People\Leave\Contracts\ProvidesPublicHolidayCalendar;
use App\Modules\People\Leave\Contracts\ProvidesStatutoryEntitlementPolicies;
use App\Modules\People\Leave\Contracts\ProvidesStatutoryLeaveTypes;
use App\Modules\People\Leave\Contracts\ValidatesLeaveAgainstStatute;
use App\Modules\People\Leave\CountryPacks\Malaysia\MalaysiaLeaveCountryPack;
use App\Modules\People\Leave\CountryPacks\Malaysia\MalaysiaStatutoryLeaveTypes;
use App\Modules\People\Leave\Data\LeaveCountryPackManifest;
use App\Modules\People\Leave\Exceptions\LeaveCountryPackException;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicy;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicyBand;
use App\Modules\People\Leave\Models\LeaveType;
use App\Modules\People\Leave\Services\LeaveCountryPackRegistry;

test('malaysia leave pack registers under MY with the v0 core contract', function (): void {
    $registry = app(LeaveCountryPackRegistry::class);

    expect($registry->hasCountry('MY'))->toBeTrue()
        ->and($registry->hasCountry('SG'))->toBeFalse();

    $pack = $registry->forCountry('MY');
    expect($pack)->toBeInstanceOf(MalaysiaLeaveCountryPack::class);

    $manifest = $pack->manifest();
    expect($manifest)->toBeInstanceOf(LeaveCountryPackManifest::class)
        ->and($manifest->packIdentifier)->toBe('belimbing/leave-my')
        ->and($manifest->supportsCoreContract(LeaveCountryPackRegistry::CORE_CONTRACT_VERSION))->toBeTrue()
        ->and($manifest->declaredDemographicFields)->toEqualCanonicalizing(['gender', 'marital_status', 'citizenship_status']);
});

test('registry rejects packs with unsupported core contract version', function (): void {
    $registry = new LeaveCountryPackRegistry;

    $badPack = new class implements LeaveCountryPack
    {
        public function manifest(): LeaveCountryPackManifest
        {
            return new LeaveCountryPackManifest(
                countryIso: 'ZZ',
                packIdentifier: 'test/bad-pack',
                packVersion: '0.1.0',
                supportedCoreContracts: ['leave-country-pack-v99'],
            );
        }

        public function statutoryLeaveTypes(): ProvidesStatutoryLeaveTypes
        {
            return new class implements ProvidesStatutoryLeaveTypes
            {
                public function statutoryLeaveTypes(): array
                {
                    return [];
                }
            };
        }

        public function statutoryEntitlementPolicies(): ProvidesStatutoryEntitlementPolicies
        {
            return new class implements ProvidesStatutoryEntitlementPolicies
            {
                public function statutoryEntitlementPolicies(): array
                {
                    return [];
                }
            };
        }

        public function publicHolidayCalendar(): ProvidesPublicHolidayCalendar
        {
            return new class implements ProvidesPublicHolidayCalendar
            {
                public function publicHolidaysForYear(int $year, ?string $stateCode = null): array
                {
                    return [];
                }

                public function publishedYears(): array
                {
                    return [];
                }
            };
        }

        public function statuteValidator(): ValidatesLeaveAgainstStatute
        {
            return new class implements ValidatesLeaveAgainstStatute
            {
                public function validateEntitlementPolicy(LeaveEntitlementPolicy $policy): array
                {
                    return [];
                }
            };
        }
    };

    expect(fn () => $registry->register($badPack))
        ->toThrow(LeaveCountryPackException::class, 'does not support core contract');
});

test('malaysia pack exposes statutory types covering the Employment Act minima', function (): void {
    $pack = app(MalaysiaLeaveCountryPack::class);
    $codes = array_map(
        fn ($def) => $def->code,
        $pack->statutoryLeaveTypes()->statutoryLeaveTypes(),
    );

    expect($codes)->toEqualCanonicalizing([
        MalaysiaStatutoryLeaveTypes::CODE_ANNUAL,
        MalaysiaStatutoryLeaveTypes::CODE_SICK,
        MalaysiaStatutoryLeaveTypes::CODE_HOSPITALIZATION,
        MalaysiaStatutoryLeaveTypes::CODE_MATERNITY,
        MalaysiaStatutoryLeaveTypes::CODE_PATERNITY,
        MalaysiaStatutoryLeaveTypes::CODE_UNPAID,
        MalaysiaStatutoryLeaveTypes::CODE_UNAUTHORIZED_ABSENCE,
    ]);
});

test('malaysia pack ships maternity 98 and paternity 7 day floors per Act A1651', function (): void {
    $policies = app(MalaysiaLeaveCountryPack::class)->statutoryEntitlementPolicies()->statutoryEntitlementPolicies();
    $byCode = collect($policies)->keyBy('leaveTypeCode');

    expect((float) $byCode[MalaysiaStatutoryLeaveTypes::CODE_MATERNITY]->bands[0]->entitlementDays)->toBe(98.0)
        ->and((float) $byCode[MalaysiaStatutoryLeaveTypes::CODE_PATERNITY]->bands[0]->entitlementDays)->toBe(7.0)
        ->and((float) $byCode[MalaysiaStatutoryLeaveTypes::CODE_HOSPITALIZATION]->aggregateCapDays)->toBe(60.0);
});

test('malaysia pack publishes 2026 federal public holidays including National Day and Malaysia Day', function (): void {
    $holidays = app(MalaysiaLeaveCountryPack::class)->publicHolidayCalendar()->publicHolidaysForYear(2026);

    $names = array_map(fn ($h) => $h->name, $holidays);
    expect($holidays)->not->toBeEmpty()
        ->and($names)->toContain('National Day (Hari Merdeka)')
        ->and($names)->toContain('Malaysia Day');
});

test('malaysia pack publishes Kuala Lumpur state overlays with collision-safe substitutes', function (): void {
    $holidays = app(MalaysiaLeaveCountryPack::class)->publicHolidayCalendar()->publicHolidaysForYear(2026, 'KL');

    $byDate = collect($holidays)->keyBy(fn ($holiday) => $holiday->occursOn->format('Y-m-d'));

    expect($byDate->get('2026-02-01')?->name)->toContain('Federal Territory Day')
        ->and($byDate->get('2026-02-01')?->name)->toContain('Thaipusam')
        ->and($byDate->get('2026-02-02')?->name)->toContain('Federal Territory Day (Substitute)')
        ->and($byDate->get('2026-02-02')?->substitutedFrom?->format('Y-m-d'))->toBe('2026-02-01')
        ->and($byDate->get('2026-02-03')?->name)->toContain('Thaipusam (Substitute)');
});

test('malaysia pack publishes Selangor-only state holidays without Kuala Lumpur overlays', function (): void {
    $holidays = app(MalaysiaLeaveCountryPack::class)->publicHolidayCalendar()->publicHolidaysForYear(2026, 'SGR');

    $names = array_map(fn ($holiday) => $holiday->name, $holidays);

    expect($names)->toContain('Thaipusam')
        ->and($names)->toContain('Nuzul Al-Quran')
        ->and($names)->toContain("Sultan of Selangor's Birthday")
        ->and($names)->not->toContain('Federal Territory Day');
});

test('statute validator flags annual leave entitlement bands below the Act minima', function (): void {
    $pack = app(MalaysiaLeaveCountryPack::class);
    $validator = $pack->statuteValidator();

    $leaveType = new LeaveType(['code' => MalaysiaStatutoryLeaveTypes::CODE_ANNUAL, 'name' => 'Annual Leave']);
    $policy = new LeaveEntitlementPolicy;
    $policy->setRelation('leaveType', $leaveType);
    $policy->setRelation('bands', collect([
        new LeaveEntitlementPolicyBand(['min_years_of_service' => 0, 'max_years_of_service' => null, 'entitlement_days' => 5]),
    ]));

    $issues = $validator->validateEntitlementPolicy($policy);

    expect($issues)->not->toBeEmpty();
    $blocking = array_filter($issues, fn ($i) => $i->isBlocking());
    expect($blocking)->not->toBeEmpty();
    $codes = array_map(fn ($i) => $i->code, $issues);
    expect($codes)->toContain('below_statutory_floor');
});

test('statute validator passes when configured bands meet or exceed the Act floor', function (): void {
    $pack = app(MalaysiaLeaveCountryPack::class);
    $validator = $pack->statuteValidator();

    $leaveType = new LeaveType(['code' => MalaysiaStatutoryLeaveTypes::CODE_ANNUAL, 'name' => 'Annual Leave']);
    $policy = new LeaveEntitlementPolicy;
    $policy->setRelation('leaveType', $leaveType);
    $policy->setRelation('bands', collect([
        new LeaveEntitlementPolicyBand(['min_years_of_service' => 0, 'max_years_of_service' => 2, 'entitlement_days' => 12]),
        new LeaveEntitlementPolicyBand(['min_years_of_service' => 2, 'max_years_of_service' => 5, 'entitlement_days' => 14]),
        new LeaveEntitlementPolicyBand(['min_years_of_service' => 5, 'max_years_of_service' => null, 'entitlement_days' => 21]),
    ]));

    expect($validator->validateEntitlementPolicy($policy))->toBe([]);
});
