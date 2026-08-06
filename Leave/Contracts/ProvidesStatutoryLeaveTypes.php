<?php

namespace App\Domains\People\Leave\Contracts;

use App\Domains\People\Leave\Data\StatutoryLeaveTypeDefinition;

interface ProvidesStatutoryLeaveTypes
{
    /** @return list<StatutoryLeaveTypeDefinition> */
    public function statutoryLeaveTypes(): array;
}
