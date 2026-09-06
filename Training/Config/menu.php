<?php

return [
    'items' => [[
        'id' => 'people.training-evaluations',
        'label' => 'Training evaluations',
        'icon' => 'heroicon-o-chat-bubble-left-right',
        'route' => 'people.training.evaluations.index',
        'permission' => 'people.training.evaluation.submit',
        'parent' => 'people',
    ], [
        'id' => 'people.training-evidence',
        'label' => 'Training evidence',
        'icon' => 'heroicon-o-document-arrow-up',
        'route' => 'people.training.evidence.index',
        'permission' => 'people.training.participation.evidence.submit',
        'parent' => 'people',
    ], [
        'id' => 'people.team-training-passports',
        'label' => 'Team training passports',
        'icon' => 'heroicon-o-identification',
        'route' => 'people.training.team-passports',
        'permission' => 'people.training.passport.view-team',
        'parent' => 'people',
    ], [
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
