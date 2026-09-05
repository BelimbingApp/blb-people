<?php

return [
    'domains' => [
        'people' => 'People-owned training catalog, schedules, and event register.',
    ],

    'capabilities' => [
        'people.training.event.view',
        'people.training.event.manage',
        'people.training.participation.manage',
        'people.training.participation.verify',
        'people.training.participation.evidence.assign',
    ],

    'roles' => [
        'people_hr' => [
            'capabilities' => [
                'people.training.event.view',
                'people.training.event.manage',
                'people.training.participation.manage',
                'people.training.participation.verify',
                'people.training.participation.evidence.assign',
            ],
        ],
        'people_training_trainer' => [
            'name' => 'People Training Trainer',
            'description' => 'Records participation for explicitly assigned training events.',
            'capabilities' => ['people.training.participation.manage', 'people.training.participation.evidence.assign'],
        ],
        'people_hod' => [
            'capabilities' => [
                'people.training.event.view',
            ],
        ],
    ],
];
