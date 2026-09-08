<?php

namespace App\Domains\People\Training\Enums;

/**
 * The workflows a cutover hands from the legacy portal to People one at a
 * time (0015-b). Fixed list: a writer window names one of these, and a
 * workflow nobody listed has no authoritative writer.
 */
enum MigrationWorkflow: string
{
    case SkillRequirements = 'skill_requirements';
    case Assessments = 'assessments';
    case TrainingRequests = 'training_requests';
    case Attendance = 'attendance';
    case Evidence = 'evidence';
    case Evaluations = 'evaluations';
    case Effectiveness = 'effectiveness';
    case Actions = 'actions';

    public function label(): string
    {
        return match ($this) {
            self::SkillRequirements => 'Skill requirements',
            self::Assessments => 'Assessments',
            self::TrainingRequests => 'Training requests',
            self::Attendance => 'Attendance',
            self::Evidence => 'Evidence',
            self::Evaluations => 'Evaluations',
            self::Effectiveness => 'Effectiveness',
            self::Actions => 'Development actions',
        };
    }
}
