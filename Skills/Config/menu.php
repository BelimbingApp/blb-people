<?php

return [
    'items' => [
        [
            // Renders under the People bucket when the People domain provides
            // it; with no People anchor installed the item stays hidden until
            // a connector-owned anchor lands with the adapter work.
            'id' => 'people.skills',
            'label' => 'Skills',
            'icon' => 'heroicon-o-academic-cap',
            'route' => 'people.skill.catalog.index',
            'permission' => 'people.skill.catalog.view',
            'condition' => 'people.skill.catalog-audience',
            'parent' => 'people',
        ],
        [
            'id' => 'people.skill-assessments',
            'label' => 'Skill assessments',
            'icon' => 'heroicon-o-clipboard-document-check',
            'route' => 'people.skill.assessment.matrix',
            'permission' => 'people.skill.assessment.view',
            'condition' => 'people.skill.assessment-audience',
            'parent' => 'people',
        ],
        [
            'id' => 'people.skill-development-actions',
            'label' => 'Development actions',
            'icon' => 'heroicon-o-arrow-trending-up',
            'route' => 'people.skill.development-actions.index',
            'permission' => 'people.skill.development-action.view',
            'parent' => 'people',
        ],
    ],
];
