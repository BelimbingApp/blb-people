<?php
return [
    'items' => [
        [
            'id' => 'people.payroll',
            'label' => 'Payroll',
            'icon' => 'heroicon-o-banknotes',
            'route' => 'people.payroll.index',
            'permission' => 'people.payroll.view',
            'parent' => 'people',
        ],
        [
            'id' => 'people.payroll.attendance-allowance-mapping',
            'label' => 'Attendance pay-item mapping',
            'icon' => 'heroicon-o-link',
            'route' => 'people.payroll.attendance-allowance-mapping',
            'permission' => 'people.payroll.manage',
            'parent' => 'people.payroll',
        ],
    ],
];
