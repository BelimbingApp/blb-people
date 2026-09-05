<?php

return [
    'domains' => [
        'people' => 'People-owned training catalog, schedules, and event register.',
    ],

    'capabilities' => [
        'people.training.event.view',
        'people.training.event.manage',
    ],

    'roles' => [
        'people_hr' => [
            'capabilities' => [
                'people.training.event.view',
                'people.training.event.manage',
            ],
        ],
        'people_hod' => [
            'capabilities' => [
                'people.training.event.view',
            ],
        ],
    ],
];
