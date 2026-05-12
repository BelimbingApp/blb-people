<?php

namespace App\Modules\People\Leave\Contracts;

use App\Modules\People\Leave\Data\StatutoryLeaveTypeDefinition;

interface ProvidesStatutoryLeaveTypes
{
    /** @return list<StatutoryLeaveTypeDefinition> */
    public function statutoryLeaveTypes(): array;
}
