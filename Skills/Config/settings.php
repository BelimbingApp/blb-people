<?php

return [
    'definitions' => [
        /*
         * How many people must hold a critical skill at its required level
         * before a department counts as covered for it (0007-c). Two is the
         * default because one holder is a single point of failure; a tenant
         * whose teams are small may set its own, so the scope is per tenant.
         */
        'people-skills.backup_minimum' => [
            'type' => 'integer',
            'scopes' => ['global', 'tenant'],
            'default' => 2,
            'nullable' => false,
            'encrypted' => false,
            'rules' => ['integer', 'min:1'],
            'label' => 'Critical-skill backup minimum',
            'help' => 'Holders at or above the required level a department needs before a critical skill is covered.',
        ],

        /*
         * Days HR has to reassess a skill after confirmed training with a
         * pass or certificate opened the request (0006-e). The request is
         * due that many days after the confirmation date; the score never
         * moves until the reassessment is performed.
         */
        'people-skills.reassessment_after_training_days' => [
            'type' => 'integer',
            'scopes' => ['global', 'tenant'],
            'default' => 30,
            'nullable' => false,
            'encrypted' => false,
            'rules' => ['integer', 'min:1'],
            'label' => 'Reassessment due after training',
            'help' => 'Days after a confirmed pass or certificate by which the opened skill reassessment is due.',
        ],
    ],
];
