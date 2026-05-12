<?php

namespace App\Modules\People\Leave\CountryPacks\Malaysia;

use App\Modules\People\Leave\Contracts\LeaveCountryPack;
use App\Modules\People\Leave\Contracts\ProvidesPublicHolidayCalendar;
use App\Modules\People\Leave\Contracts\ProvidesStatutoryEntitlementPolicies;
use App\Modules\People\Leave\Contracts\ProvidesStatutoryLeaveTypes;
use App\Modules\People\Leave\Contracts\ValidatesLeaveAgainstStatute;
use App\Modules\People\Leave\Data\LeaveCountryPackManifest;
use App\Modules\People\Leave\Services\LeaveCountryPackRegistry;

class MalaysiaLeaveCountryPack implements LeaveCountryPack
{
    public const COUNTRY_ISO = 'MY';

    public const PACK_IDENTIFIER = 'belimbing/leave-my';

    public const PACK_VERSION = '2026.dev';

    public function __construct(
        private readonly MalaysiaStatutoryLeaveTypes $statutoryLeaveTypes,
        private readonly MalaysiaStatutoryEntitlementPolicies $statutoryEntitlementPolicies,
        private readonly MalaysiaPublicHolidayCalendar $publicHolidayCalendar,
        private readonly MalaysiaLeaveStatuteValidator $statuteValidator,
    ) {}

    public function manifest(): LeaveCountryPackManifest
    {
        return new LeaveCountryPackManifest(
            countryIso: self::COUNTRY_ISO,
            packIdentifier: self::PACK_IDENTIFIER,
            packVersion: self::PACK_VERSION,
            supportedCoreContracts: [LeaveCountryPackRegistry::CORE_CONTRACT_VERSION],
            statutoryDataVersions: ['2026.dev'],
            declaredDemographicFields: ['gender', 'marital_status', 'citizenship_status'],
            metadata: [
                'repository' => 'belimbing/blb-payroll-my (provisional; pack home pending Phase 0 decision)',
                'incubation' => 'internal-extension-shaped-pack',
                'act_reference' => 'Employment Act 1955 as amended by Act A1651 (2023-01-01)',
            ],
        );
    }

    public function statutoryLeaveTypes(): ProvidesStatutoryLeaveTypes
    {
        return $this->statutoryLeaveTypes;
    }

    public function statutoryEntitlementPolicies(): ProvidesStatutoryEntitlementPolicies
    {
        return $this->statutoryEntitlementPolicies;
    }

    public function publicHolidayCalendar(): ProvidesPublicHolidayCalendar
    {
        return $this->publicHolidayCalendar;
    }

    public function statuteValidator(): ValidatesLeaveAgainstStatute
    {
        return $this->statuteValidator;
    }
}
