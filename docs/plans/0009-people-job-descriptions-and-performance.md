# 0009-people-job-descriptions-and-performance.md

**Status:** Proposed; implementation remains halted pending explicit owner approval.
**Last Updated:** 2026-09-05 (Asia/Kuala_Lumpur).
**Agents:** faith-sol / Codex.
**Sources:** Owner's explicit Job Descriptions and KPI/Performance Management request; [0000 epic](0000-people-epic-roadmap.md); organisation/history and authorization contracts in [0001](0001-people-architecture-and-provider-boundaries.md); [0002 Skills](0002-people-skills-and-assessment.md); [0004 Progression](0004-people-progression-and-compensation.md).

This is a proposed workstream, not authorization to implement, claim issues or merge work. Document number 0009 does not correspond to the old GitHub [0009] issue. The owner requested first-class position-linked job descriptions, KPI definitions and assignments, measures/targets/periods/owners/evidence, effective-dated history, scoped visibility and organisation-explorer drill-through. Those requirements are confirmed; detailed publication, appraisal and appeals policies below are recommendations awaiting business confirmation.

## Problem Essence

Organisational positions and skill requirements do not fully describe a job or establish how performance is measured. Treating job descriptions as attachments, KPI results as skill scores, or current targets as historical truth would undermine employee clarity, planning and audit evidence.

## Desired Outcome

An employee and authorized HOD can identify the job description and performance expectations applicable to a particular assignment and period, inspect supporting evidence, review outcomes and trace changes. HR governs the process, and authorized auditors can reproduce what was expected and recorded without receiving unrestricted personnel access. Performance, competence, promotion eligibility and salary decisions remain separate records and workflows.

## Ownership and Dependencies

This workstream owns structured job-description content/versions, KPI definitions/versions, target assignments, observations/evidence, period reviews and performance decision history. It consumes position identity and immutable position versions, employment assignments and scope from 0001; competency references from 0002; and supplies authorized read contracts to 0005 and approved performance evidence to 0004 where an explicit progression policy requires it.

0001 owns organisational identity/version semantics and capability/source selection; 0009 must not create a competing position or employee directory. Job authority stated in a description is a business responsibility, not an executable application permission or financial approval grant. 0002 retains assessment authority. 0004 retains progression and compensation decisions; payroll remains in the selected HR backend. 0006 owns migration sequencing and 0007 independent verification. Operational modules retain their source measurements; an imported KPI observation is an attributable performance record, not permission to alter production/quality source transactions.

Recommend native People as the owner of these business capabilities where selected, not the connector. If an external system already owns JD/performance records, declare one authority per capability/scope and verify supported interfaces before importing or enabling local writes. Do not assume HR2000 exposes these functions. JD/performance hosting must respect its sensitivity, including comments and supporting evidence, rather than assume it is safe merely because structure is visible.

## Design Decisions

Options are to attach free-form documents and spreadsheet scores to employees; add versioned JD/performance business records linked to existing positions and Skills; or build a universal performance/workflow engine. Recommend the second. Attachments remain evidence but are insufficient for effective-date resolution and controlled references. A generic engine adds configuration and ownership ambiguity beyond the known need. Use explicit records and lifecycle contracts; agree physical module/package placement through 0001 before coding.

Keep position, job description, competency profile, KPI definition, employee target assignment, observation and review independent but linked. Their versions change for different reasons. Reuse a JD template or KPI definition without sharing mutable published records across companies or unexpectedly changing every incumbent's obligations.

## Public Contract: Positions and Job Descriptions

A position has a stable identity, versioned attributes and effective-dated employee assignments, including vacant positions and acting/concurrent appointments. A job description has its own stable identity and immutable published versions linked to the exact applicable position version(s) through explicit effective-dated associations. A position revision must deliberately retain a compatible JD association or publish a replacement; never silently follow the latest description by position title.

Each JD version includes purpose, responsibilities, duties, authority/limits, qualifications and required competencies. Distinguish responsibilities (accountable outcomes) from duties (activities) and required qualifications from evidence that a particular employee holds them. Include owner, language where relevant, scope, version, effective interval, preparation/review/publication attribution, change reason and source provenance. Required competencies reference published Skills profiles/requirements; do not fork a second skill catalogue inside prose or JSON. Any readable snapshot retains canonical reference and version.

Recommended lifecycle: draft → review → approved/published → superseded/retired. Confirm HOD technical authorship, HR governance and final publisher/delegation with the owner; a published document cannot be edited in place. Effective intervals for the same position/scope must be unambiguous. Future-dated versions must not replace today's expectations early. Retired positions/JDs remain available under historical access and retention policy.

Resolve an employee's applicable JD through the employment/position assignment and effective date, not their current title alone. Transfers, acting appointments and concurrent positions retain separate applicable descriptions. Employee-specific deviations need an explicit reviewed assignment addendum, not a hidden edit to the shared position definition. Acknowledgement of receipt, if required, records the exact version/date and is distinct from agreement, competence verification or legally sufficient consent; policy must define its intended meaning.

## Public Contract: KPI and Performance Records

| Record | Required meaning and content |
|---|---|
| KPI definition/version | Stable identity, name/purpose, owner/steward, unit, measure/source, calculation or rubric version, direction of improvement, precision and interpretation |
| Performance period | Stable identity, start/end, reporting cadence, timezone/cutoff and lifecycle; distinguish observation window from review deadline |
| KPI assignment/target version | Exact KPI version, individual employment assignment or explicit team/department subject, accountable owner, reviewer, period, target/range, applicable dates and approval history |
| Observation/evidence | Value or rubric evidence, measurement window, source ID/version, recorded time, contributor, evidence references and verification/correction status |
| Performance review | Assigned expectations, included observation versions, outcome/rating with rationale, reviewer/approval, employee response, status and any superseding review |

Definitions are reusable; a target belongs to an assignment and period rather than one mutable global KPI. Keep the metric steward, accountable subject, evidence contributor and reviewer distinct. Position-linked KPI templates may propose assignments but cannot silently publish personal targets. A team result must not be copied into each employee's review as an individual contribution without explicit policy and attribution.

Specify numerator/denominator, inclusion/exclusion, aggregation, source cadence and unit wherever the measure requires them. Percentage results cannot be combined by averaging percentages with incompatible populations. Preserve zero, missing, not applicable, not yet due, unverified and final values distinctly. A zero denominator or absent source must not produce a plausible achievement score. Lower-is-better, upper/lower bands and qualitative rubrics require their own explicit interpretation; do not assume every KPI is actual divided by target.

Use controlled calculation/rubric versions, not arbitrary executable formulas supplied by users. Define rounding, caps, weighting and exclusion rules only where the approved performance policy uses them. A composite rating is optional, explainable and never a universal employee score combining competence, attendance, KPI and salary. If used, validate weight totals and treatment of missing components, and retain component results and calculation version.

Recommended period workflow: propose targets → review/approve and communicate → capture/check evidence during execution → employee input and reviewer assessment → finalized review → response/dispute and controlled correction. HR must confirm required approval stages, self-review, conflict-of-interest handling, dispute deadlines and permitted reviewer delegation. Publication or receipt acknowledgement is not automatically a favorable review.

Amending a target during a period requires a reason, authority and an explicit effective transition; preserve the original target, observations and approval. Do not retrospectively move the goalposts by editing the old target. For transfer, leave, joining/leaving mid-period or acting roles, policy determines whether to split/reassign periods, prorate or mark not applicable. Do not invent a prorating rule. Late evidence or source corrections trigger a versioned correction/review process, not silent mutation of a closed outcome. Preserve disputes and employee responses, including outcomes that do not change the review.

## Separation from Skills, Training, Progression and Pay

KPI performance records outcomes for a defined period. Skill competence records verified ability under an applicable requirement and validity rule. A high KPI does not certify competence; a low KPI does not revoke a skill or prove lack of training. KPI evidence may be referenced by an assessor but becomes competence evidence only through the authorized Skills workflow.

A performance review may recommend a development action or training need, with human rationale. It does not automatically close/open a skill gap or prove that training caused a business improvement. Training effectiveness can reference approved KPI observations with baseline/window context and permission checks; participant feedback and a KPI result remain different evidence.

Promotion eligibility may use finalized, authorized performance evidence only when a published progression policy explicitly includes it. Preserve KPI period, target/review versions and applicability alongside skill evidence. Missing or disputed performance follows the stated policy, not an assumed zero or automatic rejection. Eligibility is not appointment; appointment is not a salary decision; compensation approval is not payroll execution. Corrections flag affected downstream decisions for governed reevaluation rather than silently rewriting awards or pay.

## Authorization and Employee Transparency

Use 0001's resource/action/scope checks at every record/query/export boundary. Organisational position or JD authority text confers no application grant. Separate published-JD visibility, KPI definition visibility, personal assignments/results, evidence, confidential reviewer notes, target approval, review finalization, publication and export permissions. Exact audience and field policies need HR approval; these defaults support the confirmed separate-permission direction.

| Audience | Proposed access within explicit scope | Exclusions without separate grants |
|---|---|---|
| Employee | Own applicable published JD, communicated KPI targets, own submissions, released results/rationale and response/dispute route | Other employees' personal reviews/evidence, draft confidential deliberations, target/review approval |
| HOD/delegate | Departmental JD proposals, assigned performance planning/review and permitted supporting evidence | Other departments, compensation decisions, unrestricted historical reviews or self-approval |
| HR | Assigned JD publication/process governance, review oversight and approved personnel scope | Blanket payroll access or unrestricted all-tenant data |
| Auditor | Read-only engagement-scoped position/JD versions, approved expectations, review history and permitted evidence for the authorized period | Operational changes, approval actions, unrelated employees and unrestricted exports |

Employees need adequate released reasons/evidence to understand and challenge their outcome; private-note classification must not remove that explanation. Deny unauthorized evidence links, exports, aggregates and cached results, including after transfer/delegation expiry. Historical mode uses current authorization over the authorized historical scope, never old grants reconstructed from the chart. Raw operational evidence may need redaction or a permitted derived extract rather than broad access to another module. Review/access/export audit logs must not duplicate sensitive contents.

## Historical and Audit Contract

Preserve business-effective dates separately from recorded/approved timestamps. A report identifies its as-of date, data cutoff, position/assignment/JD/KPI/target/review versions, population, generation time and calculation version. Retain source references and evidence integrity metadata under approved retention. Distinguish “what was known at the time” from a later corrected view; neither must silently replace the other. Published/final records are superseded with reasons, not overwritten or hard-deleted during normal editing.

Audit proof answers who occupied which position, which description/competencies applied, which KPI target and period were communicated, what was measured, which evidence supported the review, who approved/corrected it, and whether a separate downstream decision consumed it. Missing legacy versions remain explicitly unavailable. Retention, access restrictions and permitted deletion require approved policy; historical reproducibility does not imply indefinite retention of all personal data.

## Organisation Explorer Contract

0005 adds separate authorized JD and Performance sections to position/employee drill-downs. Position views show the published JD/version, competency links and approved position KPI templates without revealing an occupant's private results. Employee views resolve the relevant assignment/JD and period-specific KPI targets, results, status and permitted evidence. Display period/version and distinguish pending/disputed/final results from skill status; do not invent a combined readiness/performance/pay indicator.

Department/company aggregates follow explicit aggregate permissions, meaningful denominators and disclosure controls. Drill-through rechecks personal/evidence access. Existing planning/review actions are invoked with their own permissions; chart visibility is not edit or approval authority. Historical chart and authorized exports use the same versions/cutoff as direct records. The chart owns no JD, target or review copies.

## Phases

### Validate boundaries and policy while implementation remains halted

- [ ] Audit current source capabilities and existing position/JD/performance implementations; record reuse and missing facts rather than assume nothing exists.
- [ ] Agree position-version/JD association, competency-reference and performance-record contracts with 0001/0002; identify migration dependencies with 0006.
- [ ] Confirm publishers, reviewers, target changes, employee visibility, disputes, mid-period changes and retention with HR/HOD/owner.
- [ ] Reconcile the draft issue gaps in 0008; obtain explicit owner resumption before implementation.

### Build only after explicit resumption

- [ ] Deliver structured JD versions, position links, controlled publication/effective dates and optional version-specific receipt records.
- [ ] Deliver KPI definitions, measures/rubrics, periods and approved target assignments with explicit owners and source attribution.
- [ ] Deliver observations/evidence, reviews, employee responses and superseding corrections without changing skill or pay records.
- [ ] Integrate authorized position/employee explorer reads and canonical actions through 0005.
- [ ] Integrate explicit performance-based progression policy references with 0004 without automatic appointment/pay changes.

### Verify, migrate and hand off

- [ ] Reconcile source versions/assignments/units/periods/evidence and dry-run idempotent import with 0006; do not manufacture historic approvals or targets.
- [ ] Prove the JD/KPI acceptance scenarios in 0007, including denominator errors, target changes, transfer, revocation and downstream separation.
- [ ] Supply contract versions, evidence, approved policy decisions and limitations to the epic coordinator; update milestone status only from integrated proof.

## Scope Limits and Delivery Coordination

This workstream does not build a generic BI engine, automated ranking/disciplinary system, full succession/360-degree survey suite or payroll formula engine. Additional business needs require an explicit scoped amendment. Define only supported measurement types and integrations justified by actual needs, while preserving period/version/source semantics from the start.

One accountable owner coordinates JD/performance contract changes. After 0001 position/identity and 0002 competency-reference agreement, JD and KPI tasks can be assigned separately with reviewed interfaces; they must not invent competing position schemas. Policy discovery, source inventory and test design can run in parallel while halted. Live performance-dependent progression waits for approved final-review contracts; unrelated skills/training delivery need not wait for every KPI integration. No agents, implementation issues or code changes are authorized by writing this plan.
