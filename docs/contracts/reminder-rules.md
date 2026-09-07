# Skills reminder rules

**Status:** delivered read contract; lists what is due, sends nothing.
**Delivery:** [People #211](https://github.com/BelimbingApp/blb-people/issues/211) (contract and scheduler skeleton), [People #349](https://github.com/BelimbingApp/blb-people/issues/349) (overdue development actions).
**Sources:** [0009 workbook reconciliation](../plans/0008-people-existing-work-and-backlog-reconciliation.md), the Open Actions metric definition in People #16.

`Skills/Services/ReminderRules::due()` lists per company, never per tenant: a
reminder naming somebody else's employee is a disclosure, not a nuisance, and
the cheapest way to never do that is to never load the rows. One query per
rule rather than one with an OR, because an OR can admit a row the company pin
excludes. `people:reminders-due` prints one count per rule and sends nothing.

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
how is a later decision its shape must not pre-empt.
