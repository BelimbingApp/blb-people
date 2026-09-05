# Training effectiveness review contract

**Status:** Documentation for [issue #164](https://github.com/BelimbingApp/blb-people/issues/164); a logical record contract, not a delivered workflow.
**Owner:** People Training; People Skills owns verified assessments and competence.
**Sources:** [0003 training planning and delivery](../plans/0003-people-training-planning-and-delivery.md), [0008 backlog reconciliation](../plans/0008-people-existing-work-and-backlog-reconciliation.md), [0006 workbook parity](../plans/0006-people-data-migration-and-workbook-parity.md), and the retained [0013 scope](https://github.com/BelimbingApp/blb-people/issues/36).

The review establishes whether learning transferred to workplace results. Delivery completion, participant satisfaction, workplace effectiveness and verified competence remain separate facts. Neither attendance nor a favourable course evaluation closes a skill gap or authorizes a salary change.

## Review record and stages

Retain the workbook's 30-Day, 60-Day and 90-Day stages, plus the Final stage from the retained 0013 scope. Each occurrence identifies its stage, due date, actual review date and reviewer. Repeating a stage creates another attributable review occurrence; it must not overwrite the earlier review.

These intervals are company/workbook defaults, not universal standards requirements or hardcoded calendar policy. Governed policy must settle the trigger date, calendar, permitted changes and final-review timing. Do not silently choose course start, completion or return-to-work as the due-date anchor. An overdue review remains distinguishable from a completed review with an unsuccessful outcome.

The following names describe logical facts and references, not new columns, enums or transport APIs.

| Record element | Meaning and source |
| --- | --- |
| Review identity and scope | Stable review ID, tenant/company attribution, reviewed employee subject and relevant organisational context; use the workforce contracts from 0001 |
| Training context | Event/training ID and participant-record link; retain the request, development action and approved plan/item/revision context when applicable |
| Skill and requirement target | Skill reference and the particular requirement version that established the target for skill-linked training; no fabricated skill reference for independent awareness training |
| Stage and timing | 30-Day, 60-Day, 90-Day or Final; due/review dates, reviewer, next-review due and the governed policy/context used |
| Baseline and target | Pre-training level and target level with their source assessment/requirement references; preserve what the review is comparing |
| Post-training competence | Link to the official Skills reassessment; derive post level and improvement from that evidence, never accept a competing manually typed score |
| Workplace observations | Three distinct 1–5 ratings: application at work, behaviour/output improvement, and KPI/quality/safety impact |
| Objective evidence | Attributable workplace result/evidence supporting ratings and the outcome, with authorized attachment or canonical-record references |
| Outcome and further action | Effective, Partially Effective, Not Yet Effective or Not Applicable, the supporting evidence and any further action |
| Verification and closure | HOD verification, its actor/date/evidence, controlled closure status and outstanding follow-up; a recorded outcome is not by itself permission to close |

Preserve the rating scale without inventing anchors or averaging it into a competence score. Baseline, target and post level must remain comparable; unknown or unverified evidence is not zero. The exact effectiveness threshold, rating anchors and any cross-version comparison rule are policy to confirm.

## Reviewers and authority

The accountable manager/reviewer records workplace observations and objective evidence. The HOD verifies the review within explicitly assigned scope and the approved assessor/HOD separation. HR governs follow-up and coordinates the approved procedure. A role title or reporting relationship alone grants no write, verification, closure or export authority.

A reviewer must not self-verify outside the approved separation. Exact delegation and independence rules remain HR/HOD policy to confirm; this document does not nominate an additional approver or invent permission identifiers. Employee or trainer evidence can inform a review but does not become HOD verification or an official Skills reassessment merely because it was submitted.

Skills performs and verifies reassessment through its own governed lifecycle. Training requests or links it and reads its result. HR coordination, an event status change or a high workplace rating cannot directly modify the skill score.

Preserve review evidence, verification attribution and closure decisions as historical facts. Corrections must retain the original record and the accountable reason/provenance; the exact correction workflow remains an implementation decision. Later transfer, changed requirements, certificate expiry or a new plan revision does not rewrite the review's original context.

## Requirement-version and reassessment link

Follow the [requirement-versioning contract](requirement-versioning.md). Keep the requirement version that established the development target, plus the reference/version used by the linked official reassessment. A generic “current requirements” link is insufficient.

A superseded requirement remains readable as historical evidence. Reading it does not authorize a new assessment against a draft or retired version. If a requirement changes before reassessment, preserve both references and make their relationship explicit; do not silently compare a new target with an old baseline as if the policy were unchanged. The applicable-version selection and comparison/closure policy must be settled with Skills and HR before that path is implemented.

Verified post level and improvement are derived from the linked assessment. Participant test results, course certificates, attendance and satisfaction remain supporting training facts, not alternative sources of the post-training skill score. A reassessment correction or supersession must remain traceable to the result consumed by the effectiveness decision.

## Outcomes and controlled closure

| Outcome | Interpretation | Closure consequence |
| --- | --- | --- |
| Effective | Evidence supports the applicable effectiveness/target rule | Skill-linked closure still requires every gate below |
| Partially Effective | Some intended workplace result is supported, with remaining need | Keep explicit further action and subsequent review/development follow-up |
| Not Yet Effective | Intended workplace result has not yet been established | Remain open, schedule another review or create/revise a development action |
| Not Applicable | Applicability has been assessed and documented | Evidence is still required; this label is not automatic exemption from skill-gap closure |

The retained 0013 closure rule requires the participant evaluation, reviewed workplace evidence, a new evidence-backed verified assessment, and satisfaction of the applicable target/effectiveness rule before skill-linked training closes as effective. Missing or unverified evidence must remain explicit. Cancellation, passage of 90 days, a Final-stage label or delivery completion does not satisfy those gates.

If the gates are not met, retain the unmet need and its next action. Plan-level review reports overdue follow-up, outcomes and unresolved needs, with traceable carry-forward into a successor plan rather than declaring success from attendance counts.

### Separate non-assessable closure route — policy to confirm

For awareness, statutory or other training without an assessable skill target, use a separately identifiable non-assessable closure state. This is a proposed semantic distinction, not a shipped enum value or an approved exemption.

HR/QMS must confirm which training qualifies, the appropriate outcome/evidence rule, who may approve the decision, and how it appears in outcome metrics. Retain the source need, approved exception/rule, responsible decision-maker and evidence. Do not manufacture a fake assessment, zero skill level or “Effective” competence result to make the normal gates pass.

Until that policy is confirmed and implemented, the document provides no automatic non-assessable close action. A Not Applicable outcome by itself cannot activate this route or erase a skill-linked need.

## Access, reporting and forms

The [participation evidence-access contract](training-participation.md#evidence-access-and-disclosure) and [0001](../plans/0001-people-architecture-and-provider-boundaries.md) govern current authorization, historical scope and minimized evidence disclosure. Event visibility, aggregate indicators, individual reviews, attachments, verification, closure and export remain separately authorized. An employee sees only explicitly permitted own-record information; HOD, HR and auditor access remains granted and scoped. Do not expose private assessor notes through the passport, notifications or a review summary.

Dashboard and plan-review projections expose overdue reviews, effective percentage, skill improvement and open follow-up with a stated denominator/as-of context and authorized drill-through. Delivery, participant evaluation, effectiveness and competence closure have separate indicators; non-assessable cases must not silently inflate verified-competence counts.

Workbook sheet 13 and Form 3 in sheet 16 project the same canonical review and verification records. Reconcile completed paper evidence with the digital record and preserve provenance; a printed form is not another editable source of competence. The employee passport consumes authorized review, follow-up and evidence references.

## Runtime boundary and acceptance

At the R3 People head, Training provides catalogue and event services. SummarizesTrainingParticipation is still bound to UnavailableTrainingParticipationSummary, returning no authoritative participant totals. No effectiveness-review model, stage workflow, verified-reassessment integration or closure API is claimed delivered by this document.

Implementation acceptance must prove each closure gate, repeated stages, unsuccessful and Not Applicable outcomes, requirement changes, reassessment verification/supersession, conflict-of-interest refusal, company/tenant denial, evidence access and non-assessable policy handling. Also prove that ratings, attendance and participant test results cannot write an official score.

The plan's source-to-contract coverage is explicit: staged ratings/evidence/HOD verification appear in the review record; suitable methods and timing remain governed policy; separate outcome indicators and Skills authority govern closure; the HR/QMS-approved non-assessable route remains unconfirmed; plan outcome review retains unmet needs and traceable follow-up. This documentation adds no storage, policy thresholds, permissions, standards-conformity claim or runtime behavior.
