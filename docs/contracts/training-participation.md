# Training participant record contract ([0011-a])

**Document type:** People Training participation contract  
**Status:** Contract defined; implementation remains tracked by
[plan 0003](../plans/0003-people-training-planning-and-delivery.md).  
**Issue:** BelimbingApp/blb-people#157  
**Sources:** [0001 authority and evidence boundaries](../plans/0001-people-architecture-and-provider-boundaries.md),
[0003 training planning and delivery](../plans/0003-people-training-planning-and-delivery.md),
and [0008 participant-record reconciliation](../plans/0008-people-existing-work-and-backlog-reconciliation.md).  
**Last updated:** 2026-09-05

This contract defines the canonical per-employee facts produced by training
delivery. The event register schedules delivery and consumes aggregate counts.
The first participation write slice is recorded below; the full lifecycle and
read/export contract remain broader than that implementation.

## Ownership and identity

People Training owns participation, evaluation, and effectiveness records. A
participant record belongs to one tenant and workforce company and identifies:

- one stable workforce employee subject;
- the delivery event and each event session to which the facts apply;
- the applicable request or nomination when one exists; and
- the approved plan item and revision that authorized the delivery when one
  exists.

Those are opaque stable references. The record does not copy an employee,
request, plan, course, event, or session into a competing source of truth, and
never joins them by coincident numeric IDs, email, or display name. An event
that serves several approved plan items retains explicit scoped allocation;
participant counts are not duplicated across those items.

A request approval, nomination, event completion, participant attendance,
test result, certificate, evaluation, effectiveness review, reassessment, and
finalized competence are separate facts. None silently creates or advances
another.

## Canonical participation facts

The participant record preserves planned values separately from actual facts.
Actual delivery is recorded per session so partial attendance, rescheduling,
no-shows, and cancellation remain intelligible.

| Fact | Required meaning and boundary |
|---|---|
| Participation identity | Tenant, workforce company, stable employee subject, delivery event, and applicable session references. Optional request/nomination and approved plan-item/revision references remain traceable rather than copied. |
| Participation state | `nominated` and `confirmed` precede delivery. Pre-attendance work may end as `absent` or `cancelled`. Actual attendance may advance to `evaluation_due` and then `evaluation_recorded`. These states do not encode event completion, effectiveness, reassessment, or competence. |
| Session attendance | One actual attendance fact for each applicable session, including absence or no-show where applicable, with the accountable recorder and recorded time. Event-level attendance is derived from these facts rather than entered as a second total. |
| Actual hours | Actual attended hours for the participant, attributable to the applicable session facts. Planned event duration is not substituted for attendance, and event totals reconcile to participant facts. |
| Learning test result | Pre-training and post-training test results where used, including the applicable pass mark and pass/result meaning. Missing and not applicable remain distinct from zero or failed. A learning test is not a Skills assessment and cannot write a competence score. |
| Certificate | The participant's certificate fact and its validity or expiry where applicable, linked to the issuing delivery context and permitted evidence. Certificate expiry does not erase historical participation or assessment evidence and does not by itself determine current competence. |
| Evidence | Opaque references to attendance, result, certificate, or completion evidence. Attachments remain governed documents; filenames, links, and contents are not embedded into broadly readable summaries or logs. |
| Provenance and confirmation | Source, accountable actor and capability, recorded time, confirmation state, confirmer and confirmation time. Imported or paper-captured facts also retain source provenance and duplicate-prevention identity. |

Workbook helper values, dashboard totals, passport rows, and printable forms are
projections over these canonical facts. They are not editable participant
records. Imports preserve source identity and evidence and quarantine ambiguous
employees, dates, or attachments rather than guessing.

## Writers, confirmation, and correction

Every write carries an authenticated actor, the capability used, tenant,
company, employee subject, event/session scope, and source provenance. Role
labels are not authorization. The authoritative side rechecks the binding and
scope for every mutation.

| Writer | Permitted contribution | Confirmation boundary |
|---|---|---|
| Authorized trainer or event record owner | Records actual session attendance and hours, learning test results, and delivery evidence for the sessions they own. | They may confirm those facts only when the applicable procedure and a narrow confirmation capability authorize it. They cannot finalize a Skills assessment or confirm unrelated events, sessions, companies, or employees. |
| Authorized HR training administrator | Records governed participation facts and evidence, reconciles imports or paper capture, and confirms facts within explicitly assigned company scope. | Confirmation attests the participation fact and provenance only. It is not plan approval, expenditure approval, an effectiveness outcome, or competence verification. |
| Employee participant | Self-reports their own attendance, hours, result, certificate, or supporting evidence when the company procedure permits it. | A self-report is visibly pending confirmation and never becomes an authoritative participation fact merely because the subject supplied it. The employee cannot confirm their own report unless separately authorized under an approved procedure. |

Once confirmed, the factual content and its actor, source, and timestamps are
immutable. A correction appends a traceable replacement or correction record
with reason, actor, time, and a reference to the superseded fact; it does not
edit or delete the confirmed history. Later event revisions, plan revisions,
employee transfers, certificate expiry, or reassessment outcomes do not rewrite
the participation facts that applied at delivery.

Fact confirmation is distinct from the participant lifecycle's `confirmed`
state: the latter accepts a nomination before delivery, while the former locks
recorded delivery facts under the applicable company procedure.

The lifecycle keeps independent clocks. Recording attendance may make a
participant evaluation due, but it cannot record that evaluation. Event
completion does not manufacture attendance. A cancelled event does not close
its training need, and course attendance does not close a skill gap.

## Evidence access and disclosure

Evidence is minimized and authorized separately from the participant summary.
Authentication or visibility of an event is not permission to see its
participants or documents. Current authorization is checked when evidence is
uploaded, linked, read, downloaded, or exported, including asynchronous
generation and retrieval.

The audience policy from plan 0001 applies:

- an employee may see their own permitted participation, certificate, and
  follow-up only with an explicit self-record grant and verified employee
  binding;
- a HOD or delegate may see permitted detail only for explicitly assigned
  departmental scope and periods; hierarchy position alone grants nothing;
- HR sees only the company, record classes, and operations assigned through
  its governance capabilities; and
- an auditor receives read-only, expiring engagement scope for approved
  populations, periods, and evidence classes, not unrestricted personnel
  exports.

Structure browsing, aggregate indicators, individual detail, evidence access,
confirmation, correction, administration, and export are separate permissions.
Current authorization governs historical access: selecting an old date does
not revive an expired grant, and a transfer does not expose unrelated-company
history. Unauthorized responses, counts, attachment names, links, caches,
search indexes, notifications, logs, and exports must not reveal the existence
or content of restricted records.

Sensitive attachments use authorized retrieval, bounded links where links are
used, file and malware validation, approved retention/deletion, and audited
downloads without copying their contents into logs. Revocation invalidates or
bounds cached, queued, and downloaded-link access. Separate-host administrator,
backup, key, and recovery trust limits remain those of plan 0001.

## Downstream evaluation, effectiveness, and passport

Participation supplies references and confirmed facts; downstream records keep
their own authority and lifecycle.

| Consumer | What participation supplies | What the consumer still owns |
|---|---|---|
| Participant evaluation ([0012]) | The participant, event/session, confirmed attendance context, and permitted delivery/result references. Attendance can make evaluation due. | The employee's eight ratings and feedback, useful learning, application commitment, support, recommendation, issues, and controlled HR follow-up. Missing, zero, and not applicable remain distinct. |
| Workplace effectiveness ([0013]) | The participant and delivery references, confirmed attendance/results, certificate/evidence links, and evaluation status needed to schedule follow-up. | The configured 30/60/90-day stage, reviewer, workplace-impact evidence and ratings, outcome/action, HOD verification, and any link to a verified reassessment. Participant satisfaction is not effectiveness. |
| Employee register and training passport ([0014]) | Authorized longitudinal participation, actual attendance/hours, learning results, certificate validity, and links to evaluation/effectiveness evidence. | A permission-safe read/print projection over canonical records, with subject, scope, cutoff, retained versions, provenance, and generation time. It is not a second editable ledger. |

Skills remains authoritative for finalized competence and verified reassessment.
Training may request or link that reassessment but may not write its score.
Progression and payroll may consume governed outcomes through their own
contracts; attendance, a pass, or a certificate is never by itself a salary or
progression decision.

## Read outcomes and implementation limits

Reads distinguish authorized empty results from unsupported, unavailable,
stale, incomplete, and unauthorized outcomes. They expose source and freshness
where consequential. A provider outage must not appear as no participation,
and missing evidence must not become a zero, failed result, or competence fact.

This contract does not prescribe table names, PHP interfaces, route names,
attachment storage, role names, confirmation hierarchy, test scales, pass
marks, certificate formats, retention periods, or effectiveness cadence. Those
choices belong to approved company/QMS policy and implementation work and must
preserve the identities, immutability, authority, evidence, and independent
lifecycle boundaries above.


## First participation write slice (issue #184 / PR #188)

Training now owns three scoped records: TrainingParticipant identifies the
event and the provider-qualified employee subject; TrainingSession preserves a
stable session reference and its scheduled window; TrainingParticipationFact
records one participant's actual session minutes, attendance, declared-scale
pre/post learning results, certificate validity, opaque evidence references and
source/actor/capability/time provenance. A participant identity is shared across
sessions. Composite foreign keys bind facts to the same tenant, company and
event as both parents. Session and participant identities are immutable.

TrainingParticipationStore defines sessions, records attendance, revises
unconfirmed facts and confirms facts. The selected workforce directory must
resolve the explicit employee subject. The current company and actor are
rechecked; the writer needs the participation manage capability and either
explicit HR audience or a live user binding to the event's trainer/organizer.
HR confirmation additionally requires participation verify. Evidence assignment
requires its own capability, including removal or confirmation of existing
references. Authorization grants use the platform's request-scoped snapshot;
directory bindings and stored actor company are rechecked at the write boundary.

Confirmation stores its own actor, capability and timestamp; it does not accept
a nomination or change event, evaluation or competence state. Both database
drivers prevent updates/deletes of confirmed facts. Duplicate source identities
and a second fact for the same participant/session are refused. Unknown learning
results remain null, not-applicable is explicit, and zero is a real score against
the supplied maximum/pass mark. Actual minutes never default to scheduled hours.

This slice does not implement nomination lifecycle, confirmed-fact corrections,
request/plan allocations, self-report, evaluation, participant read/export or
passport integration. A confirmed correction is refused until an append-only
correction operation is provided. Evidence fields hold opaque references only;
document resolution, upload, download and export remain separate governed
operations, not functionality supplied by this store. The existing aggregate
and self-standing readers are not replaced or marked complete by this write
slice.
