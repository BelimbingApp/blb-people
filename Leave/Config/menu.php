<?php

return [
    'items' => [
        [
            'id' => 'people.leave',
            'label' => 'Leave',
            'icon' => 'heroicon-o-calendar-days',
            'parent' => 'people',
        ],
        [
            'id' => 'people.leave.my',
            'label' => 'My Leave',
            'icon' => 'heroicon-o-paper-airplane',
            'route' => 'people.leave.index',
            'permission' => 'people.leave.view',
            'parent' => 'people.leave',
        ],
        [
            'id' => 'people.leave.approvals',
            'label' => 'Approvals',
            'icon' => 'heroicon-o-check-badge',
            'route' => 'people.leave.approvals',
            'permission' => 'people.leave.approve',
            'parent' => 'people.leave',
        ],
        [
            'id' => 'people.leave.admin',
            'label' => 'Leave Admin',
            'icon' => 'heroicon-o-cog-6-tooth',
            'route' => 'people.leave.admin',
            'permission' => 'people.leave.manage',
            'parent' => 'people.leave',
        ],
    ],
];
