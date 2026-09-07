<?php

return [
    'domains' => [
        'people' => 'People directory, organisation and workforce experiences.',
    ],

    'capabilities' => [
        'people.organisation.structure.view',
        'people.organisation.aggregate.view',
        'people.organisation.detail.view',
        'people.organisation.executive.view',
        'people.organisation.hod.view',
        'people.organisation.employee.view',
        'people.organisation.hr.view',
        'people.organisation.auditor.view',
    ],

    // The authorization matrix expressly creates no default grant. Operators
    // assign an operation and one audience scope independently.
    'roles' => [],
];
