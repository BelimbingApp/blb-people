# Five-department skill and training rollout

**Status:** In progress — configuration contract defined; product workflows and pilot evidence remain open
**Last Updated:** 2026-09-05
**Sources:** [#38](https://github.com/BelimbingApp/blb-people/issues/38), [#9](https://github.com/BelimbingApp/blb-people/issues/9), [#17](https://github.com/BelimbingApp/blb-people/issues/17), [#18](https://github.com/BelimbingApp/blb-people/issues/18), [#76](https://github.com/BelimbingApp/blb-people/issues/76), connector [#99](https://github.com/BelimbingApp/blb-people-connector/issues/99), [#108](https://github.com/BelimbingApp/blb-people-connector/issues/108), and [#109](https://github.com/BelimbingApp/blb-people-connector/issues/109), `docs/modules/workflow/design.md` in Belimbing, owner-recorded `no-legacy-migration` decision
**Agents:** desktop-terra/unknown-model, desktop-sol-migration/gpt-5

## Problem Essence

SBTG needs to introduce the connector-owned Skill and Training Management System
to Production, Engineering, QAC/R&D, Planning, and IT. The earlier plan assumed a
populated legacy training portal, but the owner confirmed that the portal is
empty and unusable; treating it as a migration source would manufacture data,
cutover risk, and approval work that do not exist.

## Desired Outcome

The five departments start from reviewed draft profiles and execute the same
configurable requirement-to-effective-training lifecycle. HR governs the
configuration and operating queues; department heads remain accountable through
configured capabilities, persons-in-charge, notifications, and service levels.
The pilot proves those controls in retained product history rather than through
external signature documents.

This cohort is rollout configuration, not a product limit. Another company may
choose any departments and positions without changing application code.

## Top-Level Components

- **Connector-owned product records:** requirement profiles, assessments,
  development actions, training requests and events, participant records,
  evaluations, effectiveness reviews, reassessments, and score history remain
  authoritative in `blb-people-connector`.
- **Provider-neutral organization context:** company, employee, department,
  position, tier, manager, and employment facts resolve through the connector
  contract. Neither `blb-people` nor HR2000 becomes the skill/training system of
  record.
- **Base Workflow configuration:** each aggregate owns one truthful lifecycle.
  Statuses, transitions, capability gates, PICs, notifications, and SLAs use
  BLB Workflow and AuthZ; domain code owns invariants and transition guards.
- **Starter-profile pack:** #17 owns the versioned workbook/data dictionary and
  imports the five departments as drafts. No starter profile is active merely
  because it was imported.
- **Pilot evidence:** product audit and workflow history provide the decision
  trail. Dashboard projections and exported reconciliation views derive from
  the same authoritative records.

## Design Decisions

### Start clean rather than simulate a legacy migration

The rejected alternative was to preserve the former inventory, provenance,
rollback, dual-writer freeze, and archive gates even though there are no legacy
records or writer. That creates ceremony with no protected data. The chosen
direction starts clean and reopens migration work only if a real production
source later appears.

The SBTG workbook is still a governed starter-data source under #17. Import
provenance, dry run, quarantine, atomic apply, and idempotency apply to that
workbook because rows are actually written; they do not imply a portal cutover.

### Configure accountability in Workflow rather than copy role checklists

External HR/HOD signature packs would duplicate the live authorization and
decision state. Workflow status PICs make queues visible, transition
capabilities enforce who may act, notifications prompt the responsible people,
SLAs drive #18 reminders and escalation, and append-only history records the
decision. Audit evidence is exportable, but an export is not a second approval
system.

### Keep independent clocks in independent aggregates

A single mega-status would make approval, attendance, evaluation,
effectiveness, and reassessment contradict one another. Each aggregate keeps its
own lifecycle. Durable events or explicit references connect them; completion
of one lifecycle never silently advances another.

## Public Contract

### Stable cohort configuration

The initial cohort uses stable department selectors for Production,
Engineering, QAC/R&D, Planning, and IT. Display names may change; imports and
workflow routing resolve stable provider-neutral organization identifiers.
Position and tier selectors follow the same rule.

Starter profiles are created in `draft`. Publishing requires all referenced
skills and selectors to resolve within the same tenant/company, active weights
to total 100%, every mandatory requirement to name its evidence expectation,
and the acting user to hold the publish capability. A published version is
immutable; revision creates a new draft version.

### Workflow definitions

Names below are stable flow contracts. Exact storage keys should use the owning
module's established prefix when implemented; labels are presentation copy and
may be localized.

| Flow | Status graph | Transition responsibility |
| --- | --- | --- |
| Requirement profile | `draft -> pending_hod_review -> pending_hr_review -> published -> retired`; either review status may return to `draft` | Author edits drafts; HOD confirms technical requirements and evidence; HR confirms governance; only an authorized publisher activates a version. Connector #108 owns the retrofit from the existing `draft/published/retired` lifecycle. |
| Skill assessment | `draft -> submitted -> pending_hod_verification -> finalized`; verification may return to `draft` | Assessor records evidence; HOD verifies or returns; finalization appends history and updates the current-score projection without overwriting an earlier result. Connector #109 owns reconciliation with the existing status and `hod_verification` fields. |
| Development action | `planned -> assigned -> in_progress -> pending_reassessment -> completed`; non-terminal work may move to `cancelled` with a reason | HOD owns gap containment and outcome; trainer/coach owns intervention; qualified assessor owns reassessment; completion requires the referenced verified result. |
| Training request | `draft -> pending_hod -> pending_hr -> pending_approval -> approved`; review steps may return to `draft`; decision may end in `rejected`, and pre-delivery work may end in `cancelled` | Requestor states need; HOD confirms relevance; HR governs completeness; approval authority decides budget; approval alone does not create attendance or competence. |
| Training participant | `nominated -> confirmed -> attended`; pre-attendance work may end in `absent` or `cancelled`, while `attended` may advance to `evaluation_due -> evaluation_recorded` | Event owner records attendance/results/evidence; participant records evaluation; attendance never changes the competency score. |
| Effectiveness review | `scheduled -> due -> recorded -> pending_reassessment -> effective`; review may end in `partially_effective` or `not_effective`, which must reference follow-up work | HOD/reviewer records workplace transfer; qualified assessor owns reassessment; closure requires evidence and an explicit outcome. |

There are no implicit cross-flow transitions. Approving a training request may
authorize creation of an event link, but cannot mark a participant attended;
recording attendance may schedule an evaluation, but cannot finalize an
assessment.

### Capability, PIC, notification, and SLA rules

Implementations register narrow AuthZ capabilities for each mutating transition
rather than matching role names in domain code. At minimum, the contract must
distinguish requestor/self-service, HOD technical review, HR governance,
approval authority, event record owner, participant, qualified assessor, and
workflow administrator. A person may hold more than one capability, but the
audit history records the capability and actor used for each decision.

PIC configuration points to provider-neutral people or scoped organizational
responsibilities; strings such as `HOD` and `HR` are not executable identities.
A missing or ambiguous PIC fails closed and appears in the administrative
health queue.

Notifications derive from committed transitions and link to the exact
authorized record. Delivery is deduplicated and retryable. Workflow queues, not
email, remain the source of truth.

Initial service-level defaults are configuration, not hard-coded timers:

| Responsibility | Default | Escalation owner |
| --- | ---: | --- |
| HOD verifies a submitted assessment | 5 working days | HR governance queue |
| HOD assigns an action for a verified critical/major gap | 5 working days | Management and HR |
| HOD assigns other verified-gap work | 10 working days | HR governance queue |
| Participant records evaluation after attendance | 3 calendar days | Event owner, then HR |
| Effectiveness review | Configured 30/60/90-day checkpoint | HOD and HR |
| Critical coverage recovery plan | 30 calendar days | Management and HR |

#18 owns calendars, reminder lead times, retries, escalation delivery, and
health visibility. A tenant may tighten defaults without changing the lifecycle
or weakening mandatory escalation.

### Pilot acceptance

Each of the five departments supplies one complete, authorized product trace:

1. a draft starter profile is reviewed and published;
2. an employee resolves to that profile through stable organization context;
3. an evidence-backed assessment is finalized and exposes a gap;
4. the gap creates an owned development action;
5. a request is reviewed and approved, then linked to a training event;
6. the participant's attendance, result, certificate/evidence, and evaluation
   are recorded independently;
7. an effectiveness review and verified reassessment produce an explicit
   outcome and updated current-score projection; and
8. dashboard, passport, coverage, overdue, and export views reconcile to the
   same retained records.

Pilot acceptance is a recorded Workflow/Audit decision by configured HR and HOD
actors. Tests may prove mechanics with fixtures, but fixtures are not evidence
that a live department completed the pilot.

## Dependencies

This rollout cannot be honestly executed before its product records exist. The
dependency spine is #13, #14, #15, #16, #17, #18, and #33–#37. Connector #108
owns requirement-profile HOD/HR review, and connector #109 owns verified
assessment finalization; those changes are not implied by any delivery issue in
the spine. Scoped pilot actors depend on People #76 and its connector carrier
#99. Provider-neutral production activation additionally depends on the
relevant adapter and workforce-projection work under #20 and #31. Already
completed #10–#12 supply the catalog, profiles, and assessment foundation but
do not satisfy #108 or #109.

Missing functionality must remain in its owning issue. This plan does not seed
orphan `base_workflow` rows, introduce People-private training records, or
replace absent aggregates with JSON configuration.

## Phases

### Phase 1 — Correct the rollout contract

- [x] Record that the legacy portal is empty and remove migration, rollback,
  dual-writer cutover, archive, and signature assumptions. {desktop-sol-migration/gpt-5}
- [x] Define the Workflow ownership model, independent lifecycle graphs,
  accountability controls, service-level defaults, and pilot evidence contract.
  {desktop-sol-migration/gpt-5}
- [x] Preserve workbook import safeguards only for the real starter-data import
  owned by #17. {desktop-sol-migration/gpt-5}

Evidence: this plan and the structured #38 scope-reconciliation record.

### Phase 2 — Build connector-owned workflow consumers

- [ ] Implement the development-action, training, evaluation, effectiveness,
  reassessment, passport, dashboard, and automation issues in dependency order.
- [ ] Complete connector #108 so requirement-profile publication records both
  configured HOD technical review and HR governance review.
- [ ] Complete connector #109 so HOD verification precedes assessment
  finalization and authoritative score projection.
- [ ] Land People #76 / connector #99 and exercise its scoped HR, HOD, assessor,
  and employee authorization through the real pilot consumers.
- [ ] Register the stable flow definitions and narrow transition capabilities in
  their owning connector modules; validate every graph through BLB Workflow.
- [ ] Resolve configured PICs from tenant/company-scoped provider-neutral
  organization context and fail closed on missing or ambiguous assignments.
- [ ] Prove committed, deduplicated notifications and configurable SLA
  escalation without wall-clock sleeps.

Validation: focused connector tests, composed SQLite/PostgreSQL suites, Workflow
graph validation, AuthZ negative tests, and tenant/company isolation tests.

### Phase 3 — Import drafts and execute the five-department pilot

- [ ] Import the versioned workbook starter pack for the five departments as
  drafts using #17's dry-run, atomic, idempotent, and quarantined path.
- [ ] Configure real HOD, HR, assessor, approval, participant, and event-owner
  assignments for each department through supported product surfaces.
- [ ] Execute and retain one complete pilot trace per department.
- [ ] Reconcile dashboards, passports, coverage, overdue queues, audit history,
  and exports to the same product records.
- [ ] Record the configured HR and HOD pilot-acceptance decisions and open
  follow-up work for every partial or failed outcome.

Validation: five retained end-to-end traces, authorization evidence, export
reconciliation, and operational health showing no unresolved failed delivery.

## Revisit Conditions

Reopen migration-specific design only if a production legacy source appears.
At that point its inventory, provenance, legal retention, dry-run, rollback, and
cutover requirements need a separate scoped issue based on observed source data;
they must not be silently restored as assumptions in this rollout.
