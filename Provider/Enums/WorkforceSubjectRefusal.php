<?php

namespace App\Domains\People\Provider\Enums;

enum WorkforceSubjectRefusal: string
{
    case Unknown = 'unknown';
    case WrongCompany = 'wrong_company';
    case Deactivated = 'deactivated';
}
