# Skills reminder rules

**Status:** delivered; `ReminderRules::due()` lists, `ReminderDeliveries::send()` notifies once per ISO week and records every attempt.
**Delivery:** [People #211](https://github.com/BelimbingApp/blb-people/issues/211) (contract and scheduler skeleton), [People #349](https://github.com/BelimbingApp/blb-people/issues/349) (overdue development actions), [People #362](https://github.com/BelimbingApp/blb-people/issues/362) (delivery log, `people:reminders-send`).
**Sources:** [0009 workbook reconciliation](../plans/0008-people-existing-work-and-backlog-reconciliation.md), the Open Actions metric definition in People #16.

`Skills/Services/ReminderRules::due()` lists per company, never per tenant: a
reminder naming somebody else's employee is a disclosure, not a nuisance, and
the cheapest way to never do that is to never load the rows. One query per
rule rather than one with an OR, because an OR can admit a row the company pin
excludes. `people:reminders-due` prints one count per rule and sends nothing;
`people:reminders-send` is the sending half and is described below.

## Rules

| Rule | Source rows | Predicate |
|---|---|---|
| `overdue_reassessment` | `EmployeeSkillScore` with `next_assessment_due` set | `next_assessment_due <= asOf` (date) |
| `expiring_certificate` | `EmployeeSkillScore` with `valid_until` set | `valid_until <= asOf + window` (date, default 30 days); lapsed certificates stay listed |
| `overdue_development_action` | `DevelopmentAction` in the Open Actions set | `closure_status` in `open`, `pending_reassessment`, `further_action_required`, `status` not in `proposed`, `on_hold`, and `due_date` strictly before today (`whereDate`, so a due timestamp late yesterday counts and midnight today does not) |

Open Actions follows People #16: Open, Pending Reassessment, and Further
Action Required; overdue means Days Overdue > 0. A proposal nobody approved
and held work nobody is pursuing are waiting, not overdue, so the status
exclusion applies even when their closure is still open.

## Records

Each `Skills/Data/DueReminder` names the company, the subject employee, the
skill, the rule, and the due date. Score reminders carry the requirement
reference and version the score was measured against. Action reminders carry
the `development_action_id` and the accountable `owner_employee_entity_id`;
their requirement reference and version come from the `source_assessment_id`
chain where present, otherwise the action's own `action_key` with version 1
marking the unversioned baseline. Nothing in the record is a message, an
address, or a channel: this contract decides what is due, and who is told and
how is the delivery ledger's decision, below.

## Delivery ledger

`Skills/Services/ReminderDeliveries` turns `due()` into notifications and
writes one row per reminder, recipient and period to
`people_connector_skill_reminder_deliveries`.

**Period key.** The ISO year-week of the run, `YYYY-Www` (e.g. `2026-W37`),
the same shape as Performance's `week_key`. A reminder is delivered at most
once per period to one recipient: the unique key
`(tenant, company, rule, employee, skill, development_action_id, period_key,
recipient_user_id)` enforces it, with `development_action_id = 0` for a score
reminder so the key holds on every driver. Next week it is due again.

**Recipient per rule.**

| Rule | Recipient | Skipped when |
|---|---|---|
| `overdue_reassessment`, `expiring_certificate` | the employee's head of department (`Skills/Services/DepartmentHeads::headUserOf()`: employee → department → head → that head's user in the same company) | no department, no head, or the head has no user |
| `overdue_development_action` | the action's `owner_employee_entity_id`, mapped to a user through the workforce directory (`WorkforceSubjects::resolve()` → `userReference`) | the owner has no active user link |

A skipped reminder writes no row: a row is a delivery, and nobody was
addressed. The run result counts skips by reason so the absence is reported
rather than silent.

**Attempt order.** The row is inserted first, in its own transaction, in
`failed` state with the failure `not attempted`; then `notify()` runs; then the
row moves to `sent` with `sent_at`. A unique violation on the insert means the
recipient already holds this period's row and counts as skipped — nothing is
re-sent. An exception from `notify()` leaves the row `failed` carrying the
exception message, and the run counts it. The notification
(`Skills/Notifications/SkillReminderNotification`, database channel) carries
the reminder fields and the exact page: the development action list pinned to
the action, or the assessment matrix pinned to the employee.

**Retry.** `ReminderDeliveries::retry()` re-attempts rows in `failed` state for
the current period only; success moves the row to `sent`, another failure
overwrites the message and `attempted_at`. A `sent` row is never retried, and
a failed row of a past period is left as history: the reminder is due again
this period and `send()` will address it afresh.

**Command.** `people:reminders-send --tenant=<id> --company=<id> [--days=n]
[--retry] [--dry-run]` extends `TenantScopedCommand` and prints `sent: n`,
`skipped: n`, `failed: n`, one line per skip reason and one per failed row.
`--dry-run` resolves recipients and reports the counts a send would produce,
writing and sending nothing. Failed rows of the acting company are also
listed, read-only, on the HR governance page.
