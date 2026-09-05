<?php

namespace App\Domains\People\Organisation\Enums;

enum OrganisationIndicator: string
{
    case Headcount = 'headcount';
    case Vacancies = 'vacancies';
    case SkillCoverage = 'skill_coverage';
}
