<?php

return [
    'items' => [[
        'id' => 'people.training-catalog',
        'label' => 'Training catalog',
        'icon' => 'heroicon-o-academic-cap',
        'route' => 'people.training.catalog.index',
        'permission' => 'people.training.event.view',
        'condition' => 'people.training.event-audience',
        'parent' => 'people',
    ], [
        'id' => 'people.training-events',
        'label' => 'Training schedule',
        'icon' => 'heroicon-o-calendar-days',
        'route' => 'people.training.events.index',
        'permission' => 'people.training.event.view',
        'condition' => 'people.training.event-audience',
        'parent' => 'people',
    ], [
        'id' => 'people.hr-governance',
        'label' => 'HR governance',
        'icon' => 'heroicon-o-clipboard-document-check',
        'route' => 'people.hr-governance.index',
        'permission' => 'people.skill.hr.view',
        'parent' => 'people',
    ]],
];
