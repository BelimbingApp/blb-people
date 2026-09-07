<?php

return [
    'domains' => [
        'people' => 'People directory, organisation and workforce experiences.',
    ],

    'capabilities' => [
        'people.organisation.structure.view',
        'people.organisation.aggregate.view',
        'people.organisation.detail.view',
        'people.organisation.audience.executive.view',
        'people.organisation.audience.hod.view',
        'people.organisation.audience.employee.view',
        'people.organisation.audience.hr.view',
        'people.organisation.audience.auditor.view',
    ],

    // The authorization matrix expressly creates no default grant. Operators
    // assign an operation and one audience scope independently.
    'roles' => [],
];
