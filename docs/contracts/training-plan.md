# Training plan contract ([0003-a])

**Document type:** People Training read/write contract
**Status:** Contract defined; implementation remains tracked by
[plan 0003](../plans/0003-people-training-planning-and-delivery.md).
**Issue:** BelimbingApp/blb-people#132
**Last updated:** 2026-09-05

This contract defines the governed plan that sits between identified training needs and
training delivery. An approved request, a course catalogue entry, or an event register is
not a substitute for an approved organizational or departmental plan revision.

## Ownership and identifiers

People owns the plan, its revisions, items, decisions, amendments, and links to execution.
Every record is tenant-scoped and carries the applicable workforce company scope described
by [the HR data boundary](hr-data-boundary.md). References to departments, workforce
subjects, cohorts, needs, requests, programmes, events, and evidence are opaque stable
identifiers; the plan does not copy those records or derive identity from display names.

A `TrainingPlan` has one stable identity across its revisions. Its typed scope contains:

- the tenant and workforce company;
- an organizational or departmental target scope, including the department when the plan
  is departmental;
- an explicit planning period, which may be annual or another approved period;
- the accountable owner, objectives, and target population or organizational scope; and
- an explicit budget mode: financial tracking enabled or not tracked.

Company-wide and departmental plans use the same contract. Cross-company delivery does not
merge plan ownership: each contributing approved item remains scoped and is reconciled by
an explicit allocation.

## Versioned plan record

Each `TrainingPlanRevision` belongs to the stable plan and exposes its revision identifier,
version, status, scope snapshot, items, approval history, and amendment provenance. Reads
must be able to return both the current revision and any retained historical revision.
Approved content is immutable; a later revision never rewrites the scope, items, decisions,
or execution context that applied to an earlier revision.

The plan lifecycle is the following closed set:

| Status | Meaning |
|---|---|
| `draft` | The current proposed revision may be edited by its authorized plan owner. |
| `submitted` | The revision is frozen for review and an approval decision is pending. |
| `approved` | The revision and its items are the authoritative approved plan scope. |
| `amended` | A controlled change to approved scope has completed its required impact review and reapproval. |
| `superseded` | A later approved or amended revision has replaced this revision for future action; its history remains authoritative for action already taken. |
| `closed` | Plan outcome review is complete and no new execution or amendment may be attached to the plan. |

Approval outcomes are not extra plan statuses. An approval decision is typed as approved,
rejected, or returned for revision and records actor, approval role, decision scope, date,
and reason. A rejection or return preserves the submitted revision and decision; continued
work starts from a new draft rather than overwriting that evidence. Execution, attendance,
cancellation, effectiveness, and competence states belong to their respective records and
must not be encoded in the plan status.

## Plan items

Every `TrainingPlanItem` is part of one revision and identifies:

- the training need and expected result;
- an optional existing request or nomination reference;
- the target cohort or governed participant scope;
- the proposed in-house, external, or mixed delivery approach;
- the responsible owner, intended timing, and evaluation approach;
- zero or more planned programme or event references; and
- an optional budget line when financial tracking is enabled.

Provider, trainer, session, and venue details may remain unresolved while they are inside
the approved policy envelope. A plan item may produce several events. An event serving
several approved items carries explicit scoped allocations so that participants and costs
are not double-counted.

A budget line distinguishes estimated, approved, committed, and actual amounts, including
currency and allocation basis. Commitments and actuals for the same obligation are not
added as separate spend. Refunds, cancellation charges, reallocations, and shared-event
allocations remain reconcilable to the approved item. Budget mode `not tracked` means the
plan has no People financial tracking requirement; it means neither zero cost nor unlimited
spending authority. Cost categories keep paid costs such as fees, travel, and materials
distinct from any valuation of internal trainer time. Unknown historical amounts remain
unknown; enabling tracking later requires the applicable approval, and disabling it for
future revisions never erases approved financial history. Regardless of budget mode, the
plan may record non-financial resource needs such as trainers, time, facilities, and
operational coverage.

## Approval roles and controlled amendment

The contract recognizes these responsibilities without fixing a company-specific hierarchy:

- a HOD proposes departmental needs, target capability, participant scope, and timing;
- HR consolidates needs, coordinates the plan, and submits a revision; and
- the configured final approver or delegated authority records the decision required by
  the applicable company and QMS procedure.

Plan approval and expenditure approval are distinct decisions. Approval of a plan is not a
blanket purchase authorization, and an approved policy envelope need not trigger a redundant
approval for each covered scheduling or nomination action. Financial thresholds,
delegations, and exception routes are company policy, not constants in this contract.

Changing approved scope creates a new revision or an explicit controlled change linked to
the approved revision. The write records the change reason, initiating actor, impacted
items, impact review, and any required reapproval. The prior revision becomes superseded
only when the successor is approved. Executed records retain the revision that authorized
them. Urgent or unplanned training uses an explicit authorized exception followed by
reconciliation; approval is never backdated or manufactured.

## Read and write surface

The read contract provides the stable plan, its current and historical revisions, scoped
items, approval and amendment history, plan-to-event allocations, and plan outcome view.
The outcome view keeps planned and delivered activities and people, unmet or deferred
needs, cancellations, overdue follow-up, evaluation/effectiveness measures, and financial
variance when enabled distinguishable from one another.

The write contract accepts authorized intent to create or edit a draft, submit a revision,
record an approval decision, create a controlled amendment, supersede or close a plan, link
approved items to delivery, and reconcile outcomes. Every mutation fails closed without
tenant, company, actor, and applicable authorization context.

The contract does not prescribe transport classes, storage tables, route names, approval
hierarchy, financial thresholds, or a generic workflow engine. Those implementation choices
must preserve the lifecycle and authority boundaries above.

## Authority boundaries

The request, recommendation, approval-decision, and approved-budget records in
[issue #33](https://github.com/BelimbingApp/blb-people/issues/33) remain their own
authoritative history and are reused rather than copied into the plan. The plan consolidates
those records with other needs into approved organizational scope. A request can therefore
be approved but not yet included in a plan, or included but not yet linked to a scheduled
event, without either record pretending to be the other. Plan-level budget approval remains
distinct from a request's approved amount and from expenditure authority.

| Record | Authoritative for | Not authoritative for |
|---|---|---|
| Training need / skill gap | The competence or business problem and its provenance | Plan approval, attendance, or final competence |
| Request / nomination | Proposed participation, its recommendation and individual authorization trail | Approved organizational scope or plan version |
| Training plan revision | Approved scope, objectives, items, responsibilities, timing envelope, and plan-level budget authorization | Purchase approval, scheduled delivery, attendance, effectiveness, or a skill score |
| Programme / course | Reusable learning content and objectives | A scheduled occurrence or approved participant |
| Delivery event / session | Schedule, provider or trainer, capacity, delivery, attendance, results, and certificates | The plan revision or competence closure |
| Evaluation / reassessment | Participant feedback, workplace effectiveness, and verified competence evidence | Retrospective plan approval or payroll reward |

A request may exist before plan approval and may later be linked to an item. Only an
approved or amended plan revision authorizes plan-to-event execution within its policy
envelope. Approved-but-unscheduled items remain visible. Rescheduling, partial delivery,
no-shows, cancellation, and actual results change execution records and reconciliation,
not the historical plan revision. A cancelled event does not close its underlying need.

Skills remains authoritative for finalized competence. Training may link or request a
verified reassessment but may not write a skill score. Progression and payroll may consume
governed results, but neither approval nor attendance is itself a salary decision.

## Control and evidence traceability

ISO and company-control traceability is expressed through evidence links, not through a
mandated annual template, approval hierarchy, budget, or certification claim. A trace link
classifies its source as an applicable normative requirement, guidance-informed design, or
company policy and, where verified, records standard, edition, clause, business control,
software behavior, evidence, accountable owner, validation, and approved exception.

Plan objectives, actions, responsibilities, approval decisions, delivery records, outcome
reviews, and competence evidence may satisfy different controls and therefore remain
separately addressable. Applicability and interpretation belong to the QMS owner using
authorized standards. The software and this contract do not claim ISO conformity.
