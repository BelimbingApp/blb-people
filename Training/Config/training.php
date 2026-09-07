<?php

return [
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
];
