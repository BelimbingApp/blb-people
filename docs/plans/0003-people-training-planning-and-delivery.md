# 0003-people-training-planning-and-delivery.md

**Status:** Proposed; implementation remains halted pending explicit owner approval.
**Last Updated:** 2026-09-06 (Asia/Kuala_Lumpur).
**Agents:** codex/astra-medium.
**Sources:** [Overall epic roadmap](0000-people-epic-roadmap.md); owner's ISO/training-plan clarification; revised workbook mapping in [0006](0006-people-data-migration-and-workbook-parity.md); official ISO references below.

Read the epic's business context, shared decisions and halt instruction first. This is a proposed assignment boundary, not an implementation claim. Document number 0003 is not historical issue prefix [0003].

## Problem Essence

Individual training requests and event records do not establish an approved organizational training programme. The system needs traceability from competence/business needs through a reviewed plan and execution to demonstrated results, without making financial budgeting mandatory.

## Desired Outcome

HR and HODs can consolidate needs into a training plan, obtain approval, execute internal or external programmes, manage changes, and evaluate outcomes. Optional budget controls support financial oversight without blocking valid non-budgeted training. Employees can see applicable approved opportunities, participation and follow-up.

## Confirmed Requirements and Recommendations

The owner explicitly requires a training plan, approval of that plan, execution through in-house or external programmes, and optional training budget. These are product requirements. Annual planning, the exact approval hierarchy, financial thresholds and exception routes below are recommendations for confirmation, not statements that ISO mandates those details.

Training planning remains People business functionality. HR2000 need not supply it. This workstream does not move training back into the connector or create a second assessment authority.

## Standards Basis and Limits

Use ISO 10015 as the principal competence/development design guide, ISO 9001 as the applicable QMS requirements baseline, and ISO 30422 as complementary learning/development guidance. Applicability to SBG must be confirmed with its QMS owner: its certified/target standards and editions have not been supplied.

| Reference | Intended use | Limits |
|---|---|---|
| [ISO 9001:2015](https://www.iso.org/standard/62085.html), particularly 7.2 | Necessary competence, actions to address needs, evaluation of effectiveness and retained evidence of competence | Supports organizational QMS requirements; does not prescribe our annual-plan template, named approval hierarchy or compulsory financial budget |
| [ISO 10015:2019](https://www.iso.org/standard/69459.html) | Main guidance for competence needs, development planning, programme structure, actions, responsibilities and impact evaluation | Guidance; does not add or modify ISO 9000-family requirements. ISO's catalogue reports confirmation in 2025 |
| [ISO 30422:2022](https://www.iso.org/standard/76365.html) | Workplace learning for organizational and individual development | Administrative operations are outside its scope; do not cite it as authority for our approval/budget machinery |
| [ISO/IAF competence auditing guidance](https://committee.iso.org/files/live/sites/tc176/files/PDF%20APG%20New%20Disclaimer%2012-2023/ISO-TC%20176-TF_APG-Competence.pdf) | Useful audit-oriented explanation of competence and evidence under ISO 9001 | Supporting guidance, not a substitute for the normative standard or certification assessment |

References were checked in the preceding research on 2026-09-05. Full licensed standards have not been reviewed for a clause-by-clause implementation mapping. Before making conformity claims, obtain authorized copies, confirm current published editions/amendments and SBG's applicable certification basis, and have the QMS owner validate interpretation. Do not build against draft revisions as though already adopted, copy copyrighted standard text into code/docs, or call the software itself “ISO certified.”

### Traceability to establish

Each control needs a source classification: applicable normative requirement, guidance-informed design, or company policy. Record standard/edition/clause where verified, business control, software behavior, evidence produced, accountable owner, validation and approved exception. Keep company policies identifiable so an auditor can distinguish them from ISO requirements.

| Control | Initial basis | Software evidence |
|---|---|---|
| Define required competence and identify gaps | ISO 9001 clause 7.2, supported by 10015 guidance | Requirement version, assessment and need provenance |
| Plan development and evaluate results | ISO 10015 guidance; exact detailed mapping pending licensed review | Plan objectives, actions, responsibilities and outcome review |
| Approve annual/periodic plan and amendments | Owner requirement/company procedure | Immutable approved revision, approver, scope, decision and date |
| Optional budgeting and spend approvals | Owner requirement/company financial policy | Explicit budget mode, allocations and authorized financial decisions when enabled |
| Retain evidence of competence | ISO 9001 clause 7.2 | Verified assessment/evidence links, with appropriate retention/access |
| Participant feedback and staged workplace reviews | Workbook/company approach informed by learning guidance | Evaluation and effectiveness records; cadence is not claimed as universally ISO-mandated |

Other sector/safety/environment standards may add applicable competence controls. Do not add speculative certification scope before SBG confirms it. Salary-linked progression is company policy handled in 0004, not an ISO-mandated reward system.

## Ownership and Dependencies

Own needs consolidation, training plans/items/revisions, plan approvals, requests/nominations, programme catalogue, delivery events, attendance/results/certificates, evaluation, effectiveness and optional training financial controls.

Consume stable employment/scope contracts from 0001 and requirement/gap/assessment references from 0002. Supply training read models/actions to 0005 and provenance to 0006. Skills owns finalized competence: training can request/link a reassessment, not write a score. Progression consumes competence and governed policy, not course attendance as a pay trigger.

One agent owns the plan/request/event aggregate boundaries. If later divided among agents, agree the plan-item-to-event and participant interfaces first; avoid simultaneous schema design in plans and delivery. Financial tracking here is not a purchasing or accounting ledger.

## Design Decisions

Three workable approaches are: extend isolated requests with a plan label; build a governed plan over existing request/event lifecycles; or make training depend on a large budgeting/procurement suite. Recommend the second. A label cannot preserve approved scope/version and planned-versus-actual outcomes; a compulsory financial suite conflicts with optional budgeting and adds unrelated coupling.

Reuse existing catalogue/event/request implementation after audit. Introduce the missing plan boundary without recreating working participant or assessment history. Plans may cover annual, departmental or another explicit period; use sensible defaults rather than a new generic workflow engine.

## Public Contract and Record Boundaries

A training need describes the competence or business problem and expected result. It may come from a gap, HOD submission, legal/quality obligation, new equipment/product, strategy or employee development. A need need not have a skill-gap record.

A training plan has stable identity, tenant/company scope, planning period, accountable owner, objectives, target scope, revision and approval history. Each plan item identifies the need, expected result, target cohort, proposed delivery approach, responsible owner, timing and evaluation approach. Specific provider/session details may be finalized later within an approved policy envelope.

A programme/course describes reusable learning content and objectives. A delivery event/session is a scheduled occurrence. A request/nomination selects or proposes participation and may originate before plan approval. These are related records, not synonyms.

One plan item can produce several events. If one event serves several approved items, use explicit scoped allocation and reconciliation rather than double-counting participants or costs. Cross-company sharing must obey approved ownership and authorization contracts.

An approved plan is not evidence of attendance, a blanket purchase authorization, a finalized assessment or a salary award.

## Lifecycle

Needs analysis → draft plan and items → review → approved plan revision → scheduled execution → delivery records → evaluation → effectiveness/competence follow-up → plan outcome review.

### Planning and approval

- HOD proposes departmental needs, target capability and timing; HR consolidates and coordinates. Final approver/delegation must be confirmed by the owner and QMS procedure.
- Submit a version for review. Record approval, rejection or return-for-revision, including actor, scope, date and reason.
- Preserve the approved version and its items. Amendments create a new revision or explicit controlled change, with impact review/reapproval where required.
- Executed records retain the approved context applicable at execution; later revisions do not rewrite history.
- Define who may schedule, nominate or commit expenditure under an approved item. Do not require a second redundant approval for every action if approved policy already covers it.
- Unplanned or urgent training needs an explicit exception/approval route and later reconciliation to the programme. Never backdate a manufactured approval.
- Keep draft/under-review/approved/superseded/cancelled approval state distinct from execution and effectiveness state.

### In-house and external execution

Support internal trainers, external providers and mixed delivery where needed. Record trainer/provider suitability, objectives/content, dates, location or online mode, duration, participants, capacity and responsible coordinator. Required supplier review/purchasing controls follow applicable company procedure.

Track scheduling, nomination/acceptance, cancellation, rescheduling, partial delivery, no-shows and completion with reasons. Capture actual attendance/hours, learning results, certificates and evidence independently of planned values. Reconcile what was approved, scheduled and actually delivered.

Training delivery completion is an operational milestone. Skill-gap closure remains contingent on appropriate verified reassessment. A cancelled course should not silently close its underlying need or action.

### Optional budget

Budget mode is explicit for the applicable plan/company policy: financial controls enabled or not tracked. Disabled tracking does not mean zero cost or unlimited spending authority. Non-financial resource planning—time, trainers, facilities and operational coverage—still matters.

When enabled, distinguish estimated, approved, committed and actual amounts, with currency and allocation basis. Agree cost categories such as fees, travel or materials; avoid silently mixing trainer time valuation with paid cash costs. Commitments and actuals must not be added as if they were separate spend for the same obligation.

Keep plan approval and expenditure approval distinct. Record thresholds, overrun/reallocation authority and changes without assuming a particular monetary limit. Reconcile plan-item and event allocations; refunds, cancellation charges and shared events cannot create duplicate costs. Integrate with purchasing/accounting only through explicit contracts.

If tracking is enabled later, preserve unknown historical amounts as unknown and obtain the relevant approval; do not invent zero costs or past approved budgets. Once approved financial history exists, disabling future tracking must not erase it.

### Evaluation, effectiveness and plan review

Preserve the workbook's eight participant-evaluation criteria, workplace-effectiveness stages/ratings, evidence, HOD verification and reassessment links. Choose evaluation methods appropriate to objectives; 30/60/90-day reviews are a company/workbook approach, not a universal ISO interval.

Maintain separate indicators for delivery, participant evaluation, workplace effectiveness and competence closure. For non-assessable awareness training, HR/QMS must approve an appropriate outcome/evidence rule rather than require a fake assessment.

Plan review shows planned versus delivered activities and people, unmet needs, cancelled/deferred items, overdue follow-up, outcome/effectiveness measures and financial variances when enabled. Avoid declaring success from attendance counts alone. Carry unresolved needs into a successor plan through traceable decisions.

## Phases

### Validate requirements and standards applicability

- [ ] Confirm SBG's applicable standards/editions, obtain authorized text and validate the control traceability with HR/QMS.
- [ ] Audit existing request/event/budget work and identify actual reuse versus missing plan-level behavior.
- [ ] Agree plan/revision/item, event and nomination interfaces with Skills, provider and migration owners.
- [ ] Confirm approvers, amendment/urgent-training procedure and optional-budget semantics without inventing financial policy.

### Build after explicit owner resumption

- [ ] Deliver needs consolidation and period-based plans/items with reviewed, immutable approved revisions.
- [ ] Deliver controlled amendments and plan-to-programme/event execution for in-house and external delivery.
- [ ] Deliver optional budgeting with explicit unknown/zero distinction and separate spending authority.
- [ ] Integrate participant records, evaluation, effectiveness and verified reassessment without duplicate business stores.
- [ ] Deliver plan-level outcomes and approved follow-up/carry-forward, plus three printable workbook forms linked to canonical records.

### Verify and hand off

- [ ] Prove approval/version history, rescheduling, partial delivery, cancellation and urgent exceptions.
- [ ] Prove execution with budget disabled; with tracking enabled prove allocation/overrun/refund reconciliation and no duplicate spend.
- [ ] Trace a competence need through approved plan, delivered event and appropriate effectiveness/assessment evidence.
- [ ] Validate all relevant workbook features with 0006 and independent integrated tests with 0007.
- [ ] Update this plan with evidence and limitations; do not claim ISO conformity solely from software feature coverage.

## Handoff and Out of Scope

Job descriptions and performance in [0009](0009-people-job-descriptions-and-performance.md) may inform an authorized training need or provide KPI evidence for effectiveness. Preserve the exact measure/period/source and permitted baseline when linking outcomes. A poor KPI is not automatically a competence gap; improved performance does not by itself prove training caused the change. Training keeps its own approval/evaluation lifecycle and cannot change KPI targets, finalize performance reviews or award pay. Verify this boundary through JP-A08/JP-A13 in 0007.

Supply authorized plan/delivery/follow-up indicators and canonical drill-through references to the organisation explorer in 0005, under 0001's scope/history contracts. HOD planning initiated from a department uses the same plan workflow and separate approval permissions; audit views retrieve approved versions and execution evidence rather than a chart-specific record copy.

Provide stable plan/event/evidence references, status meanings, authorization and amendment rules, financial-mode semantics, migration mappings and test evidence. Do not implement a full accounting/procurement product, automatic payroll rewards, arbitrary cross-provider writeback or a certification engine within this workstream.
