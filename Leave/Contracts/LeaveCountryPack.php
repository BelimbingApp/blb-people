<?php

namespace App\Domains\People\Leave\Contracts;

use App\Domains\People\Leave\Data\LeaveCountryPackManifest;

interface LeaveCountryPack
{
    public function manifest(): LeaveCountryPackManifest;

    public function statutoryLeaveTypes(): ProvidesStatutoryLeaveTypes;

    public function statutoryEntitlementPolicies(): ProvidesStatutoryEntitlementPolicies;

    public function publicHolidayCalendar(): ProvidesPublicHolidayCalendar;

    public function statuteValidator(): ValidatesLeaveAgainstStatute;
}
