<?php

return [
    'domains' => [
        'people' => 'People directory, organisation and workforce experiences.',
    ],

    'capabilities' => [
        'people.organisation.structure.view',
        'people.organisation.aggregate.view',
        'people.organisation.detail.view',
        'people.organisation.audience.executive',
        'people.organisation.audience.hod',
        'people.organisation.audience.employee',
        'people.organisation.audience.hr',
        'people.organisation.audience.auditor',
    ],

    // The authorization matrix expressly creates no default grant. Operators
    // assign an operation and one audience scope independently.
    'roles' => [],
];
