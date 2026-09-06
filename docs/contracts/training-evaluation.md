# Participant training evaluation contract

**Status:** Record and visibility contract from [issue #163](https://github.com/BelimbingApp/blb-people/issues/163); employee self-service criteria version `0012-a.v1` delivered by issue #273.
**Owner:** People Training.
**Sources:** [0003 training planning and delivery](../plans/0003-people-training-planning-and-delivery.md), [0008 retained 0012 scope](../plans/0008-people-existing-work-and-backlog-reconciliation.md), [0006 workbook parity](../plans/0006-people-data-migration-and-workbook-parity.md), and [issue #35](https://github.com/BelimbingApp/blb-people/issues/35).

A participant evaluation records the employee's learning feedback and intended workplace application after attendance. It is distinct from the later manager/HOD effectiveness review. Satisfaction, attendance and a completed form do not establish verified competence or close training effectiveness.

## Record and versioned criteria

Keep a stable evaluation identity, tenant/company and employee-subject context, and a link to the particular participant record and its training event. Attendance and other confirmed delivery facts remain in the [participation record](training-participation.md); they are not edited through the evaluation.

The evaluation retains the particular criteria-set version used for its answers, including question meaning, scale, mandatory-question rules and calculation policy. This versioning requirement comes from issue #163. A current-form pointer cannot reproduce an older completed evaluation. Changes to a later form must not reinterpret retained answers or completion decisions. Draft migration between versions and correction/reopening procedures remain implementation and governance decisions; this document does not create a schema or choose them.

Preserve all eight workbook/0012 criteria, with 1–5 ratings:

| Criterion | Participant rates |
| --- | --- |
| Relevance | Relevance of the training |
| Objectives met | Whether the training objectives were met |
| Content quality | Quality of the content |
| Trainer effectiveness | Effectiveness of the trainer |
| Materials/exercises | Training materials and exercises |
| Pace/duration | Pace and duration |
| Practical usefulness | Usefulness in practice |
| Overall satisfaction | Overall satisfaction with the training |

The first employee self-service form pins criteria version `0012-a.v1`: relevance, trainer effectiveness, materials/exercises, pace/duration and practical usefulness are its five mandatory 1–5 responses, and `issues_or_improvements` is its one optional comment. The other retained workbook criteria and free-text columns remain explicitly unanswered for this version rather than being filled with invented defaults. A later criteria version may expose more retained questions without reinterpreting these submissions.

Do not invent verbal rating anchors, weights or a pass threshold from these labels. Retain unanswered questions explicitly; zero is outside the 1–5 response scale and is not a replacement for missing input. A not-applicable response, if permitted by the approved form, is distinct from an unanswered question and requires defined calculation treatment.

Calculate the average without hiding unanswered criteria. Reports retain the criteria version, calculation basis, answered count and applicable/required-question context so that partial response cannot masquerade as a fully completed evaluation. Whether optional or not-applicable answers affect the denominator, and any rounding or weighting, must be explicit in the approved calculation policy rather than inferred at display time.

Keep the participant's most useful learning, job-application commitment, support needed, recommendation, issues/improvement suggestions and notes. These free-text fields are attributable participant responses. Keep HR follow-up, HOD visibility and completion state distinct from those answers; HR case handling must not rewrite the employee's feedback.

## Due date, completion and provenance

An attended participant record can make an evaluation due. Retained issue #35 describes completion normally within three days. This is a source/company default, not a universal standard requirement. The governed trigger, calendar, exceptions and mandatory questions must be confirmed; do not silently assign a deadline from event scheduling alone when attendance has not occurred.

For self-service criteria version `0012-a.v1`, issue #273 confirms an evaluation window ending 14 days after the event ends. An attended participant may create or revise the same evaluation through that instant; the form refuses later writes visibly. The participant/event uniqueness constraint and update-in-place submission preserve one canonical response rather than an edit history made of duplicate rows.

Draft and completed are distinct states. Completion requires the configured mandatory questions for the pinned criteria version. An average, a due date passing, HR reading a form, or an event becoming complete does not mark the evaluation completed. Keep due/overdue, completed-at and submission attribution visible where authorized; overdue is not a negative training outcome.

Only the participant or an explicitly authorized assisted-entry role may submit. Bind the participant to the authenticated employee subject and recheck company/tenant, operation and record scope at submission. Paper or assisted entry retains the employee subject, actual entering actor, source and provenance; it must not impersonate an employee's self-submission or backdate invented provenance.

HR may follow up on provider/course concerns and support requests through separately attributable follow-up facts. The original participant answers remain distinguishable from HR notes and subsequent corrections. Exact correction, reopening and retention procedures require approved policy; no silent replacement of completed answers is authorized by this contract.

## Who may see and act

The [0001 audience and authorization contract](../plans/0001-people-architecture-and-provider-boundaries.md) distinguishes confirmed permission separation from proposed audience scopes that still require business confirmation. Apply current authorization at reads, writes, attachment access, follow-up and export. A role title, chart relationship or selected dashboard view grants nothing by itself.

| Audience | Intended evaluation access | Boundary |
| --- | --- | --- |
| Employee/participant | Their own permitted evaluation, answers, completion and follow-up, with verified subject binding and explicit self-record access | No colleague responses, private HR/assessor notes or departmental planning authority |
| Explicit assisted-entry actor | Submit for a named participant only within a granted assisted-entry operation and scope, retaining actual-actor provenance | Assistance is not broad employee impersonation or authority to change already completed answers |
| Trainer/provider | No automatic evaluation audience is defined for this role in plan 0001's table | Named responses, free text, support requests and aggregate feedback require an approved disclosure policy and explicit grant; teaching an event is insufficient |
| HOD/delegate | Permitted evaluation detail and actions within assigned departmental scope and periods, subject to approved privacy boundaries | No unrestricted free text, unrelated employees/companies, private evidence, HR follow-up or export solely by hierarchy |
| HR | Explicitly assigned training-governance access to evaluations and separate follow-up for course/provider concerns and support requests | No rewriting participant answers, unrelated-company access or automatic payroll/compensation authority |

Trainer feedback policy is unresolved: this document does not promise anonymous responses, invent a small-cohort threshold or assume aggregates are safe. Plan 0001 requires explicit aggregate permissions and HR/security decisions about populations, denominators and disclosure controls. Authorized aggregate-only access is separate from named-record access.

Evidence and attachments follow the [participation evidence-access rules](training-participation.md#evidence-access-and-disclosure): minimized fields, separately authorized retrieval, current permissions for historical records, bounded links where used, file validation and approved retention. Responses, counts, filenames, caches, notifications, logs and exports must not reveal restricted records. Employee transfers or expired HOD/auditor grants do not revive access by selecting an old date. Auditor and executive access, where granted, remains governed by plan 0001 rather than a new evaluation-specific shortcut.

## Participation, effectiveness and reporting

| Relationship | Data handed over | Authority retained |
| --- | --- | --- |
| Participation → evaluation | Participant/event identity, confirmed attendance context and permitted delivery/result references | Participation owns attendance/hours, learning tests, certificates and their confirmation |
| Evaluation → effectiveness review | Evaluation reference, completion and permitted feedback/application/support context | The [effectiveness review](training-effectiveness.md) owns staged workplace observations, objective evidence, HOD verification and controlled follow-up |
| Evaluation → HR follow-up | Attributable concerns and support requests within granted scope | HR records follow-up without altering the participant's answers |
| Evaluation → dashboard/passport | Authorized completion, averages and canonical drill-through references | Read models disclose only permitted records and retain denominator, criteria-version and as-of context |

Evaluation completion can satisfy the evaluation prerequisite for skill-linked effectiveness closure, but it cannot satisfy the other gates. Workplace evidence and the linked official verified Skills reassessment remain required under the effectiveness contract. A participant learning test or satisfaction rating is not a competing post-training competence score.

Dashboard completion and average metrics must drill down to contributing evaluations where record-level access is granted; aggregate-only viewers must not acquire a detail grant through drill-down. Missing, unavailable or restricted evidence is not silently turned into zero. Compare criteria versions only under an explicit compatible calculation policy rather than combining unlike questions without explanation.

Workbook sheet 12 and Form 2 in sheet 16 use the same canonical evaluation record. Completed paper forms are reconciled with digital/assisted entry and source provenance, not maintained as a second response ledger. Evaluation and follow-up also supply authorized plan-outcome review and employee-passport evidence without declaring course effectiveness from satisfaction alone.

## Implementation boundary and acceptance

Training provides the participant-evaluation model and visibility reader. Criteria version `0012-a.v1` also provides an authenticated employee Livewire form for five fixed ratings and one comment on the employee's own attended event, with update-in-place behavior until the 14-day window closes. Assisted entry, HR follow-up, aggregate reporting, broader criteria versions and correction/reopening after closure remain undelivered.

Implementation must prove all eight criteria, rating bounds, missing/optional responses and mandatory completion; criteria-version retention; due/overdue behavior; self and assisted submission provenance; HR follow-up without answer replacement; employee/HOD/HR/trainer disclosure limits; tenant/company denials; and authorized aggregate/drill-through behavior. Prove that evaluation completion cannot close effectiveness or change competence.

This covers plan0003's eight criteria and separate evaluation/effectiveness indicators, 0008's retained employee completion/controlled visibility, 0006's sheet12/Form2 ownership, and issue163's criteria-version and audience requirements. Timing, calculation details, trainer disclosure and correction policy remain explicit decisions to confirm, not invented runtime defaults.
