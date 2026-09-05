# Job description and KPI record contract

**Document type:** People business-record and visibility contract
**Status:** Proposed for business confirmation; implementation remains halted pending explicit owner approval.
**Issue:** BelimbingApp/blb-people#133
**Governing plans:** [0009 Job Descriptions and Performance](../plans/0009-people-job-descriptions-and-performance.md), with organisation identity, history, and authorization from [0001](../plans/0001-people-architecture-and-provider-boundaries.md)
**Last updated:** 2026-09-05

This contract defines the records needed to answer which job description and performance
expectations applied to an assignment and period. It does not authorize implementation or
settle the publication, appraisal, appeals, retention, or deletion policies that plan 0009
leaves for HR, HOD, and owner confirmation.

## Ownership and boundaries

The selected People Job Description and Performance installation owns structured job-description
content and versions, KPI definitions and versions, target assignments, observations and evidence,
period reviews, and performance decision history. Native People is the recommended owner where it
is selected. When an external system owns a capability, declare that single authority for the
capability and scope and verify its supported interface before importing or enabling local writes.
Do not assume HR2000 supplies these functions.

This contract consumes, but does not duplicate:

- position identity and immutable position versions, employment assignments, organisation scope,
  and effective-dated history from plan 0001;
- published competency profiles and requirements from People Skills under plan 0002;
- operational measurements from their owning modules; and
- progression policy from plan 0004 when that policy explicitly consumes finalized performance
  evidence.

A job description's statement of responsibility or authority is not an application permission or
financial approval grant. Skills retains assessment authority. Progression retains eligibility and
appointment decisions, compensation remains separate, and payroll execution remains in the selected
payroll backend. Imported observations are attributable performance records, not authority to edit
their source transactions.

## Job-description records

Keep position, job description, competency profile, and employment assignment independent but
linked. Reusing a template must not create one mutable published record shared across companies or
silently change every incumbent's obligations.

### Job description identity and version

A job description has a stable identity and immutable published versions. Each version records:

- purpose;
- responsibilities, meaning accountable outcomes;
- duties, meaning activities;
- authority and limits;
- required qualifications, kept distinct from evidence that an employee holds them;
- required competencies linked to the exact published People Skills profile or requirement version,
  never copied into a second catalogue in prose or JSON;
- owner, scope, version, language where relevant, effective interval, change reason, and source
  provenance; and
- preparation, review, and publication attribution.

Every readable snapshot retains the canonical job-description reference and version.

### Position association and applicability

An explicit effective-dated association links a job-description version to the exact applicable
position version or versions. A position revision must deliberately retain a compatible association
or publish a replacement; title equality and “latest description” are not associations. Effective
intervals for the same position and scope must be unambiguous, and a future-dated version does not
replace today's expectations early.

Resolve an employee's applicable description from the effective-dated employment/position assignment,
not the employee's current title. Transfers, acting appointments, and concurrent positions retain
their separate descriptions. Vacant positions and retired positions or descriptions retain their
authorized historical records. An employee-specific deviation is an explicit reviewed assignment
addendum rather than an edit to the shared position definition.

The proposed lifecycle is `draft -> review -> approved/published -> superseded/retired`. HOD technical
authorship, HR governance, and final publisher or delegation remain subject to business confirmation.
A published version is superseded, not edited in place. If receipt acknowledgement is required, it
records the exact version and date and remains distinct from agreement, competence verification, or
legally sufficient consent; policy must define its meaning.

## KPI and performance records

KPI definition, performance period, target assignment, observation/evidence, and performance review
are separate, linked records whose versions can change independently.

| Record | Required meaning and content |
|---|---|
| KPI definition/version | Stable identity; name and purpose; owner/steward; unit; measure and source; calculation or rubric version; direction of improvement; precision; interpretation. |
| Performance period | Stable identity; start and end; reporting cadence; timezone and cutoff; lifecycle. The observation window is distinct from the review deadline. |
| KPI assignment/target version | Exact KPI version; individual employment assignment or explicit team/department subject; accountable owner; reviewer; period; target or range; applicable dates; approval history. |
| Observation/evidence | Value or rubric evidence; measurement window; source identifier and version; recorded time; contributor; evidence references; verification and correction status. |
| Performance review | Assigned expectations; included observation versions; outcome or rating with rationale; reviewer and approval; employee response; status; any superseding review. |

Definitions are reusable, but a target belongs to an assignment and period rather than to one mutable
global KPI. Keep the metric steward, accountable subject, evidence contributor, and reviewer distinct.
A position KPI template may propose assignments but cannot publish personal targets silently. A team
result is not an employee's individual contribution unless approved policy and attribution explicitly
say so.

Where a measure needs them, record its numerator, denominator, inclusion and exclusion rules,
aggregation, source cadence, and unit. Preserve zero, missing, not applicable, not yet due, unverified,
and final as distinct states. A zero denominator or missing source must not become a plausible score,
and percentages with incompatible populations must not be averaged together. Lower-is-better,
upper/lower bands, and qualitative rubrics require explicit interpretation; “actual divided by target”
is not a universal rule.

Calculations use controlled calculation or rubric versions, not arbitrary executable user formulas.
Rounding, caps, weighting, exclusions, composite ratings, weight totals, and missing-component treatment
exist only where approved performance policy defines them. A composite, when approved, remains
explainable and retains its component results and calculation version; it is not a universal employee
score combining competence, attendance, KPI, and salary.

The proposed period workflow is target proposal, review/approval and communication, evidence capture
and checking, employee input and reviewer assessment, finalized review, then response/dispute and
controlled correction. HR must confirm approval stages, self-review, conflicts of interest, dispute
deadlines, and reviewer delegation. Publication or receipt is not a favorable review.

A mid-period target amendment records its reason, authority, and effective transition while preserving
the original target, observations, and approval. Policy—not this contract—decides whether transfers,
leave, joining or leaving mid-period, and acting roles split or reassign periods, prorate, or become not
applicable. Late evidence and source corrections create a versioned correction/review rather than
mutating a closed outcome. Preserve disputes, employee responses, and outcomes even when the review
does not change.

## Separation from competence, training, progression, and pay

Performance is an outcome for a period; competence is verified ability under an applicable requirement
and validity rule. KPI results neither certify nor revoke a skill. KPI evidence becomes competence
evidence only through the authorized Skills workflow.

A review may recommend a reasoned development action or training need, but does not automatically
open or close a skill gap or prove that training caused an improvement. Training effectiveness may
reference an approved observation with baseline, window, and permission context; participant feedback
and KPI results remain distinct.

Progression may consume finalized performance evidence only when a published policy explicitly does
so, preserving the KPI period and target/review versions alongside skill evidence. Missing or disputed
performance follows that policy, never an assumed zero or automatic rejection. Eligibility is not
appointment; appointment is not salary; compensation approval is not payroll execution. Corrections
flag affected downstream decisions for governed reevaluation rather than rewriting awards or pay.

## Authorization, confidentiality, and employee publication

Every query, record, evidence link, command, aggregate, cache, and export uses plan 0001's explicit
tenant/company, resource, action, and scope checks. Chart position and description text confer no
permission. Published-JD visibility, KPI-definition visibility, personal assignments/results,
evidence, confidential reviewer notes, target approval, review finalization, publication, and export
are separate permissions.

The following policy is proposed for business confirmation:

| Audience | Published or permitted within explicit scope | Confidential or separately granted |
|---|---|---|
| CEO/executive | Approved company-wide published JD structure and explicitly permitted aggregate indicators. | Employee drill-down, personal evidence or results, compensation detail, structural edits, and approvals. |
| Employee | Own applicable published JD; communicated targets; own submissions; released results and rationale; response/dispute route. | Other employees' reviews or evidence; draft confidential deliberations; target or review approval. |
| HOD/delegate | Departmental JD proposals; assigned performance planning/review; permitted supporting evidence. | Other departments; compensation decisions; unrestricted historical reviews; self-approval. |
| HR | Assigned JD publication/process governance; review oversight; approved personnel scope. | Blanket payroll access; unrelated-company or unrestricted all-tenant data. |
| Auditor | Read-only, engagement-scoped position/JD versions, approved expectations, review history, and permitted evidence for the authorized period. | Operational changes; approval actions; unrelated employees; unrestricted exports. |

Employees receive enough released rationale and evidence to understand and challenge an outcome;
marking material as a private note must not remove that explanation. Raw operational evidence may be
redacted or represented by a permitted derived extract instead of exposing another module broadly.
Unauthorized evidence, aggregates, exports, and cached results remain denied after transfer or
delegation expiry. Historical access applies current authorization to an explicitly authorized past
scope; it never reconstructs an old grant from the chart.

## History, audit, and explorer reads

Business-effective dates remain separate from recorded and approved timestamps. A reproducible report
identifies its as-of date, data cutoff, position/assignment/JD/KPI/target/review versions, population,
generation time, and calculation version. Retain source references and evidence-integrity metadata
under approved retention. “Known at the time” and later-corrected views remain distinguishable; neither
silently replaces the other. Published and final records are superseded with reasons rather than
overwritten or hard-deleted during normal editing.

Audit proof can answer who occupied a position, which description and competencies applied, which KPI
target and period were communicated, what was measured, which evidence supported the review, who
approved or corrected it, and whether a separate downstream decision consumed it. Missing legacy
versions are explicitly unavailable. Retention, restricted access, and permitted deletion require
approved policy; reproducibility does not mean retaining all personal data indefinitely. Access,
review, and export logs identify actor, scope, and operation without copying sensitive content.

The organisation explorer reads these canonical records; it owns no JD, target, or review copy.
Position views may show the published JD/version, competency links, and approved position KPI templates
without an occupant's private results. Employee views resolve the applicable assignment/JD and
period-specific targets, results, status, and permitted evidence. They show period/version and keep
pending, disputed, and final performance distinct from skill status, with no combined
readiness/performance/pay indicator.

Department and company aggregates require explicit aggregate permission, meaningful denominators,
and disclosure controls. Drill-through rechecks personal and evidence access. Planning and review
actions use their own permissions; chart visibility grants neither edit nor approval authority.
Historical explorer views and authorized exports use the same versions and cutoff as direct records.

## Explicit limits

This contract does not define a generic BI or workflow engine, automated ranking or discipline, a
succession or 360-degree survey suite, or a payroll formula engine. It does not invent prorating,
retention, deletion, publication, appraisal, appeals, or vendor-capability policy. Those decisions
require explicit approval before implementation.
