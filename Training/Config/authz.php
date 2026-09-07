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
        /*
         * HR register of every training request in the company (0010-c). The
         * issue named it view-all; verbs are a closed platform vocabulary and
         * 'list' is the one that means company-wide reading.
         */
        'people.training.request.list',
        'people.training.request.hod-approve',
        'people.training.request.review',
        'people.training.request.approve',
        'people.training.participation.manage',
        'people.training.participation.verify',
        'people.training.participation.evidence.assign',
        'people.training.participation.evidence.submit',

        /*
         * HR decisions on submitted evidence (0011-b). Uses the platform
         * 'verify' verb: verbs are a closed platform vocabulary and
         * 'confirm' is not declared there.
         */
        'people.training.participation.evidence.verify',
        'people.training.passport.view',
        'people.training.passport.view-team',
        'people.training.effectiveness.review',
        'people.training.effectiveness.close',

        /*
         * Participant evaluation access has capabilities of its own rather than
         * reusing event.view: granting employees the events capability to reach
         * their own evaluation would widen menu and route access as a side
         * effect, and docs/contracts/training-evaluation.md asks for explicit
         * self-record access rather than access inherited from somewhere else.
         *
         * Submit is employee-only. Neither capability is granted to the
         * people_training_trainer role: the contract defines no automatic
         * evaluation audience for it and says teaching an event is insufficient;
         * the refusal is the absence of this grant rather than a special case.
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
        'people.training.evaluation.submit',
        'people.training.evaluation-aggregate.view',

        /*
         * HR follow-up on evaluation support requests and provider concerns
         * (0012-c). HR-only on purpose: HOD keeps read-only visibility through
         * evaluation.view, and the participant's answers are never editable
         * through a follow-up, so no other role needs this.
         */
        'people.training.evaluation.followup.manage',

        /*
         * The department budget (0010-a). Viewing is separate from changing
         * because a HOD is meant to see what their department has left without
         * being able to award themselves more: the roll-up answers "can we
         * afford this request", and only HR answers "what is the allocation".
         */
        'people.training.budget.view',
        'people.training.budget.manage',

        /*
         * Approving a request that takes a department past its allocation
         * (0010-b). Declared here and granted to no role on purpose: an
         * exception everybody in a role holds is not an exception, so it is
         * given to a named principal who then has to state a reason, which the
         * budget audit keeps.
         *
         * The verb is `unlock` rather than `override` because `override` is
         * not in the platform's declared verb list, and an unknown verb is
         * filtered rather than refused — the capability would silently cease
         * to exist. `unlock` is declared and already means this in People.
         */
        'people.training.budget.unlock',

        /*
         * The 30/60/90-day effectiveness roll-up (0013-b).
         *
         * The aggregate is the RESOURCE, not the verb, matching
         * people.training.evaluation-aggregate.view above. #303 asked for
         * people.training.effectiveness.view-aggregate, but `view-aggregate`
         * is not a declared verb and the grammar reads the last segment as
         * the action — that key would be dropped from the registry and denied
         * for everybody, quietly. Recorded on the issue.
         */
        'people.training.effectiveness-aggregate.view',
    ],

    'roles' => [
        'people_hr' => [
            'capabilities' => [
                'people.training.calendar.view',
                'people.training.event.view',
                'people.training.evaluation.view',
                'people.training.evaluation.followup.manage',
                'people.training.event.manage',
                'people.training.plan.approve',
                'people.training.request.submit',
                'people.training.request.list',
                'people.training.request.review',
                'people.training.participation.manage',
                'people.training.participation.verify',
                'people.training.participation.evidence.assign',
                'people.training.participation.evidence.verify',
                'people.training.passport.view',
                'people.training.effectiveness.close',
                'people.training.evaluation-aggregate.view',
                'people.training.budget.view',
                'people.training.budget.manage',
                'people.training.effectiveness-aggregate.view',
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
                'people.training.request.submit',
                'people.training.request.hod-approve',
                'people.training.effectiveness.review',
                'people.training.evaluation-aggregate.view',
                'people.training.passport.view',
                'people.training.passport.view-team',
                'people.training.budget.view',
            ],
        ],
        'people_employee' => [
            // Submitting is drafting for oneself; the request page pins the
            // requestor to the bound employee (0005-i).
            'capabilities' => [
                'people.training.calendar.view',
                'people.training.evaluation.view',
                'people.training.passport.view',
                'people.training.request.submit',
                'people.training.participation.evidence.submit',
                'people.training.evaluation.submit',
            ],
        ],
        'people_training_approver' => [
            'name' => 'People Training Approver',
            'description' => 'Makes the final decision on fully reviewed training requests.',
            'capabilities' => ['people.training.request.approve'],
        ],
    ],
];
