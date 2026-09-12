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

    /*
     * The workbook's 30/60/90-day effectiveness checkpoints, as the fallback a
     * company that has never set a policy runs on. They are defaults, not a
     * standards requirement: TrainingEffectivenessPolicy overrides them per
     * company, and docs/contracts/training-effectiveness.md requires that the
     * trigger date be settled by governed policy rather than hardcoded here.
     *
     * Keyed by EffectivenessCheckpoint::value so an offsets array reads the
     * same whether it came from this file or from a policy row.
     */
    'effectiveness' => [
        'checkpoint_days' => [
            'day_30' => 30,
            'day_60' => 60,
            'day_90' => 90,
        ],
    ],

    'requests' => [
        /*
         * Days an approved training request may wait without a scheduled
         * event before HR is reminded of it (0009-h). Two weeks: long enough
         * for the ordinary case where an event is being arranged, short
         * enough that a forgotten approval surfaces within the month.
         */
        'unlinked_reminder_days' => 14,
    ],

    'passport' => [
        /*
         * Hours a workforce observation stays fresh on a training passport
         * (0014-d). Past this age as of generation, the context is marked
         * stale and the pages warn; the directory being unreadable marks it
         * unavailable instead. One day: workforce data moves daily, so
         * anything older deserves a warning but not a failure.
         */
        'workforce_context_max_age_hours' => 24,
    ],
];
