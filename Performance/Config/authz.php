<?php

return [
    'domains' => ['people.performance' => 'KPI assignment review and employee publication.'],
    'capabilities' => [
        'people.performance.kpi.submit',
        'people.performance.kpi.review',
        'people.performance.kpi.approve',
        'people.performance.kpi.view',
    ],
    'roles' => [
        'people_hod' => ['capabilities' => ['people.performance.kpi.submit']],
        'people_hr' => ['capabilities' => [
            'people.performance.kpi.review',
            'people.performance.kpi.approve',
        ]],
        'people_employee' => ['capabilities' => ['people.performance.kpi.view']],
    ],
];
