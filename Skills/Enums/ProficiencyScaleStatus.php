<?php

namespace App\Domains\People\Skills\Enums;

/**
 * Scale lifecycle. Draft is editable; published is immutable so historical
 * scores keep their meaning; retired stays readable for history but is no
 * longer the current scale.
 */
enum ProficiencyScaleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
