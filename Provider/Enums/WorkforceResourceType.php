<?php

namespace App\Domains\People\Provider\Enums;

enum WorkforceResourceType: string
{
    case Company = 'company';
    case OrganizationUnit = 'organization_unit';
    case Position = 'position';
    case Employee = 'employee';
    case User = 'user';
}
