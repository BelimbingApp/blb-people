<?php

return [
    'items' => [
        [
            'id' => 'people.attendance',
            'label' => 'Attendance',
            'icon' => 'heroicon-o-clock',
            'parent' => 'people',
        ],
        [
            'id' => 'people.attendance.my',
            'label' => 'My Attendance',
            'icon' => 'heroicon-o-calendar-days',
            'route' => 'people.attendance.index',
            'permission' => 'people.attendance.view',
            'parent' => 'people.attendance',
        ],
        [
            'id' => 'people.attendance.approvals',
            'label' => 'Approvals',
            'icon' => 'heroicon-o-check-badge',
            'route' => 'people.attendance.approvals',
            'permission' => 'people.attendance.approve',
            'parent' => 'people.attendance',
        ],
        [
            'id' => 'people.attendance.operations',
            'label' => 'Attendance Operations',
            'icon' => 'heroicon-o-clipboard-document-list',
            'route' => 'people.attendance.operations',
            'permission' => 'people.attendance.manage',
            'parent' => 'people.attendance',
        ],
        [
            'id' => 'people.attendance.settings',
            'label' => 'Attendance Settings',
            'icon' => 'heroicon-o-cog-6-tooth',
            'route' => 'people.attendance.settings',
            'permission' => 'people.attendance.manage',
            'parent' => 'people.attendance',
        ],
    ],
];
