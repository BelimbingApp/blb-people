<?php

return [
    'items' => [
        [
            'id' => 'people.training-passport',
            'label' => 'Training passport',
            'icon' => 'heroicon-o-academic-cap',
            'route' => 'people.training.passport',
            'permission' => 'people.training.passport.view',
            'parent' => 'people',
        ],
        [
            'id' => 'people.employee-standing',
            'label' => 'My standing',
            'icon' => 'heroicon-o-chart-bar-square',
            'route' => 'people.employee-standing.show',
            'permission' => 'people.skill.assessment.view',
            'parent' => 'people',
        ],
        [
            'id' => 'people.employee',
            'label' => 'Employees',
            'icon' => 'heroicon-o-users',
            'route' => 'people.employees.index',
            'permission' => 'people.employee.list',
            'parent' => 'people',
        ],
    ],
];
