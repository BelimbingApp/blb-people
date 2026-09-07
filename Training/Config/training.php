<?php

return [
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
