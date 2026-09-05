<?php

namespace App\Domains\People\Training\Enums;

enum PerformanceOutcomeUse: string
{
    case EvidenceOnly = 'evidence_only';
    case TrainingCausedImprovement = 'training_caused_improvement';
    case ChangeCompetence = 'change_competence';
}
