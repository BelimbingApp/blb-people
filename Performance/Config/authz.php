<?php

return [
    'domains' => [
        'people.performance' => 'Versioned job descriptions and performance records.',
    ],
    'capabilities' => [
        'people.performance.job-description.manage',
    ],
    'roles' => [
        'people_hr' => [
            'capabilities' => ['people.performance.job-description.manage'],
        ],
    ],
];
