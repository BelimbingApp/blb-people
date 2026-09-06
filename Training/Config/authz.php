<?php

return [
    'domains' => [
        'people' => 'People-owned training catalog, schedules, and event register.',
    ],

    'capabilities' => [
        'people.training.event.view',
        'people.training.event.manage',
        'people.training.plan.submit',
        'people.training.plan.approve',
        'people.training.request.submit',
        'people.training.request.hod-approve',
        'people.training.request.review',
        'people.training.request.approve',
        'people.training.participation.manage',
        'people.training.participation.verify',
        'people.training.participation.evidence.assign',
        'people.training.passport.view',
        'people.training.effectiveness.review',
        'people.training.effectiveness.close',

        /*
         * The participant evaluation read. A capability of its own rather than
         * reusing event.view: granting employees the events capability to reach
         * their own evaluation would widen menu and route access as a side
         * effect, and docs/contracts/training-evaluation.md asks for explicit
         * self-record access rather than access inherited from somewhere else.
         *
         * Deliberately not granted to people_training_trainer. That contract
         * defines no automatic evaluation audience for the role and says
         * teaching an event is insufficient; the refusal is the absence of this
         * grant rather than a special case in the reader.
         */
        'people.training.evaluation.view',
    ],

    'roles' => [
        'people_hr' => [
            'capabilities' => [
                'people.training.event.view',
                'people.training.evaluation.view',
                'people.training.event.manage',
                'people.training.plan.approve',
                'people.training.request.submit',
                'people.training.request.review',
                'people.training.participation.manage',
                'people.training.participation.verify',
                'people.training.participation.evidence.assign',
                'people.training.passport.view',
                'people.training.effectiveness.close',
            ],
        ],
        'people_training_trainer' => [
            'name' => 'People Training Trainer',
            'description' => 'Records participation for explicitly assigned training events.',
            'capabilities' => ['people.training.participation.manage', 'people.training.participation.evidence.assign'],
        ],
        'people_hod' => [
            'capabilities' => [
                'people.training.event.view',
                'people.training.evaluation.view',
                'people.training.plan.submit',
                'people.training.request.submit',
                'people.training.request.hod-approve',
                'people.training.effectiveness.review',
                'people.training.passport.view',
            ],
        ],
        'people_employee' => [
            // Submitting is drafting for oneself; the request page pins the
            // requestor to the bound employee (0005-i).
            'capabilities' => ['people.training.evaluation.view', 'people.training.passport.view', 'people.training.request.submit'],
        ],
        'people_training_approver' => [
            'name' => 'People Training Approver',
            'description' => 'Makes the final decision on fully reviewed training requests.',
            'capabilities' => ['people.training.request.approve'],
        ],
    ],
];
