<?php

return [
    'domains' => [
        'people.performance' => 'Versioned job descriptions and KPI performance records.',
    ],
    'capabilities' => [
        'people.performance.job-description.manage',
        'people.performance.job-description.view',
        'people.performance.kpi.submit',
        'people.performance.kpi.review',
        'people.performance.kpi.approve',
        'people.performance.kpi.view',
        'people.performance.kpi.evidence.view',
        'people.performance.review.view',
    ],
    'roles' => [
        'people_hod' => ['capabilities' => ['people.performance.kpi.submit', 'people.performance.review.view']],
        'people_hr' => [
            'capabilities' => [
                'people.performance.job-description.manage',
                'people.performance.kpi.review',
                'people.performance.kpi.approve',
            ],
        ],
        'people_employee' => ['capabilities' => ['people.performance.kpi.view']],
    ],
];
