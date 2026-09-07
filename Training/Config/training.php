<?php

/*
|--------------------------------------------------------------------------
| Participant evaluation criteria versions (docs/contracts/training-evaluation.md)
|--------------------------------------------------------------------------
|
| A row stores the version it was answered under; that stored string, not
| the current pointer, decides its mandatory set when it is completed and
| how a report reads it. Renaming or removing a version here never
| reinterprets a stored row: readers look the stored version up, and an
| unknown version reads as "no configured mandatory set", not as the
| current one.
*/

return [
    'evaluation' => [
        'current_criteria_version' => '0012-g.v1',

        'criteria_versions' => [
            // Five ratings and one optional comment (blb-people#273).
            '0012-a.v1' => [
                'ratings' => ['relevance', 'trainer_effectiveness', 'materials_exercises', 'pace_duration', 'practical_usefulness'],
                'free_text' => ['issues_or_improvements'],
                'mandatory' => ['relevance', 'trainer_effectiveness', 'materials_exercises', 'pace_duration', 'practical_usefulness'],
            ],

            // All eight workbook ratings and the four free-text answers
            // (blb-people#360). Every rating and the job-application
            // commitment are mandatory to complete; the rest may stay
            // unanswered and are stored as null, never as a score.
            '0012-g.v1' => [
                'ratings' => [
                    'relevance', 'objectives_met', 'content_quality', 'trainer_effectiveness',
                    'materials_exercises', 'pace_duration', 'practical_usefulness', 'overall_satisfaction',
                ],
                'free_text' => ['most_useful_learning', 'application_commitment', 'support_needed', 'recommendation', 'issues_or_improvements'],
                'mandatory' => [
                    'relevance', 'objectives_met', 'content_quality', 'trainer_effectiveness',
                    'materials_exercises', 'pace_duration', 'practical_usefulness', 'overall_satisfaction',
                    'application_commitment',
                ],
            ],
        ],
    ],
];
