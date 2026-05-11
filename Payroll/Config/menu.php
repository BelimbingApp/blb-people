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
    ],
];
