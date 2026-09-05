<?php

namespace App\Domains\People\Skills\Enums;

enum PerformanceCompetenceEffect: string
{
    case EvidenceOnly = 'evidence_only';
    case SetProficiencyLevel = 'set_proficiency_level';
    case SatisfyCriticalGate = 'satisfy_critical_gate';
    case RevokeCompetence = 'revoke_competence';
}
