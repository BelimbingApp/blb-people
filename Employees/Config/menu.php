<?php
return [
    'items' => [
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
