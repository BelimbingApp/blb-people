<?php

namespace App\Domains\People\Performance\Enums;

enum JobDescriptionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
}
