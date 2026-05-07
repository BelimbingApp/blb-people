<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

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
