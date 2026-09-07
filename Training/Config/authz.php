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

        /*
         * The training calendar read. A capability of its own rather than
         * reusing event.view, for the same reason evaluation.view states:
         * granting employees the events capability to reach the calendar
         * would widen menu and route access (catalog, schedule management
         * surfaces) as a side effect. Trainers hold it so they can see the
         * events they teach; the calendar discloses nothing an employee
         * cannot already see, and enrolment is always self-only.
         */
        'people.training.calendar.view',
    ],

    'roles' => [
        'people_hr' => [
            'capabilities' => [
                'people.training.calendar.view',
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
            'capabilities' => ['people.training.calendar.view', 'people.training.participation.manage', 'people.training.participation.evidence.assign'],
        ],
        'people_hod' => [
            'capabilities' => [
                'people.training.calendar.view',
                'people.training.event.view',
                'people.training.evaluation.view',
                'people.training.plan.submit',
                'people.training.request.hod-approve',
                'people.training.effectiveness.review',
                'people.training.passport.view',
            ],
        ],
        'people_employee' => [
            'capabilities' => ['people.training.calendar.view', 'people.training.evaluation.view', 'people.training.passport.view'],
        ],
        'people_training_approver' => [
            'name' => 'People Training Approver',
            'description' => 'Makes the final decision on fully reviewed training requests.',
            'capabilities' => ['people.training.request.approve'],
        ],
    ],
];
