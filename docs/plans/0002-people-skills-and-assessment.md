# 0002-people-skills-and-assessment.md

**Status:** Proposed; implementation remains halted pending explicit owner approval.
**Last Updated:** 2026-09-06 (Asia/Kuala_Lumpur).
**Agents:** codex/astra-medium.
**Sources:** [Overall epic roadmap](0000-people-epic-roadmap.md); workbook sheets 00–05, 08–09 and 14; historical 0000 issues mapped in 0008.

Read the epic's business context, shared decisions and halt instruction before this workstream. This numbered plan is a proposed assignment boundary, not authorization to start implementation. No agent is assigned by this document. Plan numbers are not the existing GitHub issue sequence.

## Problem Essence

Course records and editable scores cannot establish defensible current competence, critical coverage or progression eligibility.

## Desired Outcome

Governed requirements and evidence-backed assessments produce reproducible employee standing, gaps and coverage without losing history.

## Ownership and Dependencies

Own catalogue, profile versions, assessments, verification, reassessment, development actions and coverage calculations. Consume workforce identity from 0001. Supply competency evidence to 0003/0004 and read models to 0005. Training owns participation and effectiveness; Skills alone owns finalized skill assessments.

## Design Decisions

Prefer an immutable finalized assessment history with explicit supersession over overwriting a current score. Derive registers and matrices from that history rather than maintaining multiple editable copies. Use one assessment lifecycle for individual and batch entry. Preserve tested existing implementation before designing replacements.

## Skills and Assessment Contract
### Canonical lifecycle

Published position requirements → verified assessment → gap and development action → training need/request and approval → event and participation → participant evaluation → workplace effectiveness → verified reassessment → current competency → progression eligibility → authorized compensation decision → payroll acknowledgement.

Not every training need originates from a gap: regulatory requirements, new equipment/products and general development need first-class sources. Not every eligible employee immediately receives a promotion. Each transition must preserve its own evidence and decision-maker.

### Competency rules

- Preserve the defined 0–5 proficiency scale and evidence standards. Unknown/unassessed is not level zero; expired, unverified and current are separate states.
- Version requirements, effective dates, weights and mandatory certifications. Weighted achievement is explanatory; it cannot average away a missing safety-critical qualification.
- Preserve finalized assessment facts and verification. Corrections supersede with reason and attribution; they do not overwrite the original score.
- Define deterministic as-of selection, ties, validity, reassessment due dates and late-entered evidence. Historical reports must not silently apply today's requirements to yesterday's assessment.
- Participation, test pass, certificate and competency are related but different facts. A certificate may expire while an assessment remains historical evidence.
- Coverage uses valid verified competence and defined organisation/shift scope. Named backups and high aggregate scores alone do not satisfy coverage.


## Job Description and KPI Boundary Amendment

[0009](0009-people-job-descriptions-and-performance.md) references published competency profiles and requirements from this workstream in job descriptions. Preserve exact versions and effective applicability; do not create a parallel competency catalogue in JD prose or change historic requirements when a description changes. Qualifications required for a position are distinct from verified employee qualification evidence.

Performance measures outcomes during a defined period; competence measures verified ability. A KPI result may supply permission-safe supporting evidence to an assessment but cannot set a proficiency level, satisfy a critical gate or revoke competence automatically. Likewise, poor performance may prompt an authorized development/training recommendation without proving a skill gap. Corrections to linked KPI evidence retain provenance and prompt governed reassessment when appropriate, never an in-place rewrite of finalized scores.

## Phases

### Validate and agree the boundary

- [ ] Refresh relevant existing implementation and record reusable behavior, gaps and affected contracts.
- [ ] Resolve this workstream's open policy/interface decisions with the epic coordinator before dependent implementation.

### Implement after explicit owner resumption

- [ ] Publish versioned competency references for JDs and prove that KPI observations/reviews cannot bypass assessment verification or silently change historical skill requirements.
- [ ] Publish governed catalogue/profile versions with evidence expectations, critical gates and valid weights.
- [ ] Implement/audit individual and batch assessment verification, supersession, validity and deterministic as-of selection.
- [ ] Deliver linked gap actions, independent reassessment and critical coverage with named backups verified against actual competence.
- [ ] Publish employee/register and progression evidence contracts without exposing private assessor notes.

### Integrate and hand off

- [ ] Attach tests, migration implications, validation results and remaining limitations; obtain independent review.
- [ ] Update this workstream checklist and report the achieved milestone to the epic coordinator. Do not mark an epic milestone complete from isolated unit tests alone.

## Acceptance and Handoff

The organisation explorer in 0005 consumes this workstream's authorized skill indicators, requirement/assessment references and as-of standing. Keep coverage denominators and trained-versus-competent distinctions explicit; 0001 governs access and historical query context. No chart-owned score or assessment history is introduced.

Prove unknown versus zero, unverified versus current, expiry, tied/late assessments, changed requirements, rehire/transfer and cross-scope denial. Provide stable evidence references and policy-version semantics for 0004. Workbook parity ownership and source defects are recorded in [0006](0006-people-data-migration-and-workbook-parity.md).
