<?php

return [
    'items' => [[
        // One Effectiveness area with Review and Summary tabs (#436). Two menu
        // rows, but they are mutually exclusive conditions, so any one actor
        // sees exactly one entry labelled "Training effectiveness" — never the
        // two identically-named entries this issue was filed about.
        //
        // Why not a single row pointing at a disjunction route: every Domain
        // route must carry `authz:<capability>` middleware, which
        // `blb:domain-routes --audit` enforces on composed CI, and that
        // middleware takes one capability. A route admitting "either of two"
        // would either need a new capability of its own or an in-closure check
        // the audit rightly refuses. Each row below therefore lands on the
        // page the actor already holds, and the tab strip carries them across.
        'id' => 'people.training-effectiveness',
        'label' => 'Training effectiveness',
        'icon' => 'heroicon-o-clipboard-document-check',
        'route' => 'people.training.effectiveness.index',
        'permission' => 'people.training.effectiveness.review',
        'parent' => 'people',
    ], [
        // The same area for somebody who reads the roll-up but answers no
        // checkpoints. `condition` excludes anyone the row above already
        // serves, so the two are never offered together.
        'id' => 'people.training-effectiveness-summary',
        'label' => 'Training effectiveness',
        'icon' => 'heroicon-o-clipboard-document-check',
        'route' => 'people.training.effectiveness.summary',
        'permission' => 'people.training.effectiveness-aggregate.view',
        'condition' => 'people.training.effectiveness-summary-only',
        'parent' => 'people',
    ], [
        'id' => 'people.training-budget',
        'label' => 'Training budget',
        'icon' => 'heroicon-o-banknotes',
        'route' => 'people.training.budget.index',
        'permission' => 'people.training.budget.view',
        'condition' => 'people.training.budget-audience',
        'parent' => 'people',
    ], [
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
        'id' => 'people.training-calendar',
        'label' => 'Training calendar',
        'icon' => 'heroicon-o-calendar',
        'route' => 'people.training.calendar',
        'permission' => 'people.training.calendar.view',
        'condition' => 'people.training.calendar-audience',
        'parent' => 'people',
    ], [
        // 0007-f (#389): HR-only training KPI dashboard.
        'id' => 'people.training-kpi',
        'label' => 'Training KPIs',
        'icon' => 'heroicon-o-chart-bar',
        'route' => 'people.training.kpi',
        'permission' => 'people.training.kpi.view',
        'condition' => 'people.training.kpi-audience',
        'parent' => 'people',
    ], [
        'id' => 'people.training-migration-sources',
        'label' => 'Migration sources',
        'icon' => 'heroicon-o-archive-box-arrow-down',
        'route' => 'people.training.migration.index',
        'permission' => 'people.training.migration.view',
        'parent' => 'people',
    ], [
        'id' => 'people.hr-governance',
        'label' => 'HR governance',
        'icon' => 'heroicon-o-clipboard-document-check',
        'route' => 'people.hr-governance.index',
        'permission' => 'people.skill.hr.view',
        'condition' => 'people.training.hr-governance-audience',
        'parent' => 'people',
    ]],
];
