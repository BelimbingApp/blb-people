<?php

return [
    'domains' => [
        'people' => 'People-owned skill catalog, assessments, development actions, and proficiency scales.',
    ],

    'capabilities' => [
        'people.skill.catalog.view',
        'people.skill.catalog.manage',
        'people.skill.assessment.view',
        'people.skill.assessment.manage',
        'people.skill-requirement.submit',
        'people.skill-requirement.hod-approve',
        'people.skill-requirement.approve',
        'people.skill-requirement-publication.approve',
        'people.skill-requirement-retirement.approve',
        'people.skill.development-action.view',
        'people.skill.development-action.manage',
        'people.skill.assessment.submit',
        'people.skill.assessment.verify',
        'people.skill.assessment.approve',
        'people.skill.hr.view',
        'people.skill.hod.view',
        'people.skill.assessor.view',
        'people.skill.employee.view',
        'people.skill.gaps.view-team',
        'people.skill.coverage.view',
    ],

    // Audience capabilities identify why a principal may see People-owned
    // competence data. SkillAudience still resolves the employee boundary;
    // these grants alone never authorize a row. In particular, grant_all
    // platform roles are rejected unless one of these roles is also assigned.
    'roles' => [
        'people_hr' => [
            'name' => 'People HR',
            'description' => 'Governs People-owned skill catalogues and assessments for an attributed company.',
            'capabilities' => [
                'people.skill.catalog.view',
                'people.skill.catalog.manage',
                'people.skill.assessment.view',
                'people.skill.assessment.manage',
                'people.skill-requirement.submit',
                'people.skill-requirement.approve',
                'people.skill-requirement-publication.approve',
                'people.skill-requirement-retirement.approve',
                'people.skill.coverage.view',
                'people.skill.development-action.view',
                'people.skill.development-action.manage',
                'people.skill.assessment.submit',
                'people.skill.hr.view',
            ],
        ],
        'people_hod' => [
            'name' => 'People HOD / Manager',
            'description' => 'Views and assesses only employees in the holder’s projected department or reporting team.',
            'capabilities' => [
                'people.skill.catalog.view',
                'people.skill.assessment.view',
                'people.skill.assessment.manage',
                'people.skill-requirement.hod-approve',
                'people.skill.development-action.view',
                'people.skill.development-action.manage',
                'people.skill.assessment.submit',
                'people.skill.assessment.verify',
                'people.skill.gaps.view-team',
                'people.skill.assessment.approve',
                'people.skill.hod.view',
            ],
        ],
        'people_assessor' => [
            'name' => 'People Assessor',
            'description' => 'Views the skill catalogue and assesses only explicitly assigned employees.',
            'capabilities' => [
                'people.skill.catalog.view',
                'people.skill.assessment.view',
                'people.skill.assessment.manage',
                'people.skill.assessment.submit',
                'people.skill.assessor.view',
            ],
        ],
        'people_employee' => [
            'name' => 'People Employee',
            'description' => 'Views the skill catalogue and only the holder’s own People-owned assessment record.',
            'capabilities' => [
                'people.skill.catalog.view',
                'people.skill.assessment.view',
                'people.skill.employee.view',
            ],
        ],
    ],
];
