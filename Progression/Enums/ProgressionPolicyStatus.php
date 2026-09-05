<?php

namespace App\Domains\People\Progression\Enums;

enum ProgressionPolicyStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
}
