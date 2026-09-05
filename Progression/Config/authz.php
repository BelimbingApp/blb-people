<?php

return [
    'domains' => [
        'people.progression' => 'Published progression policy versions; no eligibility or compensation decisions.',
    ],

    'capabilities' => [
        'people.progression.policy.view',
        'people.progression.policy.manage',
    ],

    'roles' => [
        'people_hr' => [
            'capabilities' => [
                'people.progression.policy.view',
                'people.progression.policy.manage',
            ],
        ],
        'people_hod' => [
            'capabilities' => [
                'people.progression.policy.view',
            ],
        ],
    ],
];
