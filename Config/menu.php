<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/*
 * People domain anchor.
 *
 * Declares the `people` top-level bucket — humans only (employees today,
 * future HR / payroll / leave / performance modules later). Companies are
 * legal entities and live under `admin.companies` per D1; relationship-
 * filtered views may surface here later (people.licensee-company) per D10.
 *
 * The People domain currently has no leaf modules of its own — Employee
 * lives under Modules/Core/Employee/ and parents into `people`. That's a
 * pragmatic split: codebase namespace and menu navigation don't have to
 * match. A future move of Employee into Modules/People/Employee/ is a
 * separate refactor.
 */

return [
    'items' => [
        [
            'id' => 'people',
            'label' => 'People',
            'icon' => 'heroicon-o-user-group',
        ],
    ],
];
