<?php

return [
    'domains' => [
        'people.attendance' => 'Attendance setup, clock events, timecards, overtime, absenteeism, payroll handoff, and reporting.',
    ],

    'capabilities' => [
        'people.attendance.view',
        'people.attendance.manage',
        'people.attendance.approve',
        'people.attendance.clock',
    ],
];
