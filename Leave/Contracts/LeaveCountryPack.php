<?php

namespace App\Modules\People\Leave\Contracts;

use App\Modules\People\Leave\Data\LeaveCountryPackManifest;

interface LeaveCountryPack
{
    public function manifest(): LeaveCountryPackManifest;

    public function statutoryLeaveTypes(): ProvidesStatutoryLeaveTypes;

    public function statutoryEntitlementPolicies(): ProvidesStatutoryEntitlementPolicies;

    public function publicHolidayCalendar(): ProvidesPublicHolidayCalendar;

    public function statuteValidator(): ValidatesLeaveAgainstStatute;
}
