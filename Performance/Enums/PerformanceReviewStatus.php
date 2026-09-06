<?php

namespace App\Domains\People\Performance\Enums;

enum PerformanceReviewStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Superseded = 'superseded';
}
