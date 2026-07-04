<?php

return [
    'widgets' => [
        [
            'id' => 'people.leave.pending-approvals',
            'label' => 'Leave Approvals',
            'description' => 'Leave requests waiting for review.',
            'icon' => 'heroicon-o-check-badge',
            'permission' => 'people.leave.approve',
            'component' => 'people.leave.widgets.pending-approvals',
            'size' => 1,
        ],
    ],
];
