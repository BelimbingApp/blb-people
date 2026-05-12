<?php

return [
    'items' => [
        [
            'id' => 'people.settings',
            'label' => 'People Settings',
            'icon' => 'heroicon-o-adjustments-horizontal',
            'route' => 'people.settings.index',
            'permission' => 'people.settings.view',
            'parent' => 'people',
        ],
    ],
];
