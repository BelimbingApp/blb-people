<?php

return [
    'items' => [
        [
            'id' => 'people.claim',
            'label' => 'Claims',
            'icon' => 'heroicon-o-receipt-percent',
            'route' => 'people.claim.index',
            'permission' => 'people.claim.view',
            'parent' => 'people',
        ],
    ],
];
