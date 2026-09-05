<?php

namespace App\Domains\People\Training\Enums;

enum TrainingPlanStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Amended = 'amended';
    case Superseded = 'superseded';
    case Closed = 'closed';
}
