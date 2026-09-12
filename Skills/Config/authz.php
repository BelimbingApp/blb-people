<?php

return [
    'domains' => [
        'people' => 'People-owned skill catalog, assessments, development actions, and proficiency scales.',
    ],

    'capabilities' => [
        'people.skill.catalog.view',
        'people.skill.catalog.manage',

        /*
         * Uploading a starter-profile workbook (0008-a, #299). HR-only: the
         * page validates every row before writing skills and draft role
         * requirements through the catalog and profile stores.
         */
        'people.skill.catalog.import',
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

        /*
         * Downloading the backup-coverage rows as CSV (0007-d, #350).
         * HR-only: the file leaves the authorization boundary, so exporting
         * is a separate gate from viewing and is audited as one export
         * action. Uses the platform 'export' verb: verbs are a closed
         * platform vocabulary.
         */
        'people.skill.coverage.export',

        /*
         * Reading the HR-wide register of current released levels (0014-b).
         * HR-only: the page and its CSV export share this gate because the
         * file carries the same rows under the same filters, audited as one
         * export action. Uses the platform 'view' verb: verbs are a closed
         * platform vocabulary.
         */
        'people.skill.register.view',

        /*
         * Requesting a reassessment for a direct report's skill (0006-b).
         * HOD-only: HR sees the resulting queue but does not request, and
         * requesting never confers a wider skill audience.
         */
        'people.skill.reassessment.submit',

        /*
         * Reading the reassessment queue (0007-g). Distinct from submitting
         * and from performing: HR reads the queue without being able to
         * request one, which is exactly what the submit note above describes,
         * and a head reads their own reports' rows through the same audience
         * scoping every other Skills list uses.
         */
        'people.skill.reassessment.view',

        /*
         * Performing a requested reassessment (0006-c). HR-only: recording
         * the new released level closes the request and never rewrites
         * the previous assessment row. Uses the platform 'execute' verb:
         * verbs are a closed platform vocabulary and 'perform' is not
         * declared there.
         */
        'people.skill.reassessment.execute',

        /*
         * Reading your own released score history (0006-a). Employee-only:
         * the page binds the authenticated employee, never request input.
         * Uses the platform 'view' verb: verbs are a closed platform
         * vocabulary, and the employee boundary lives in the audience.
         */
        'people.skill.history.view',
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
                'people.skill.catalog.import',
                'people.skill.assessment.view',
                'people.skill.assessment.manage',
                'people.skill-requirement.submit',
                'people.skill-requirement.approve',
                'people.skill-requirement-publication.approve',
                'people.skill-requirement-retirement.approve',
                'people.skill.coverage.view',
                'people.skill.coverage.export',
                'people.skill.register.view',
                'people.skill.development-action.view',
                'people.skill.development-action.manage',
                'people.skill.assessment.submit',
                'people.skill.hr.view',
                'people.skill.reassessment.execute',
                'people.skill.reassessment.view',
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
                'people.skill.reassessment.submit',
                'people.skill.reassessment.view',
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
                'people.skill.history.view',
            ],
        ],
    ],
];
