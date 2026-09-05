# 0005-people-employee-and-management-experience.md

**Status:** Proposed; implementation remains halted pending explicit owner approval.
**Last Updated:** 2026-09-05 (Asia/Kuala_Lumpur).
**Agents:** codex/astra-medium.
**Sources:** [Overall epic roadmap](0000-people-epic-roadmap.md); owner's employee portal/workbench feedback; interfaces from 0001–0004.

Read the epic's business context, shared decisions and halt instruction before this workstream. This numbered plan is a proposed assignment boundary, not authorization to start implementation. No agent is assigned by this document. Plan numbers are not the existing GitHub issue sequence.

## Problem Essence

An HR administration screen or integration console does not meet employees' daily needs, and sprawling filters obscure operational work.

## Desired Outcome

Employees, HODs and HR have focused, accessible workflows with honest backend states and appropriate visibility.

## Ownership and Dependencies

Own shared navigation/presentation composition and employee self-service pages. Business rules stay with their owning workstream. Coordinate shared route/table/form changes through this owner to prevent multiple agents producing incompatible shells. Employee leave, attendance and shift commands depend on 0001's proven capabilities; learning/standing views depend on 0002–0004.

## Design Decisions

Prefer workflow-oriented role surfaces using standard components over a single all-purpose workbench or separate duplicated UI implementations for each transport. Design contracts/role journeys can proceed early; do not certify mocked provider success as a delivered employee feature.

## Employee and Administrative Experience
Organize navigation by work: My leave, My attendance, My shifts, My learning, My skills and progression; departmental assessment/actions/coverage for HODs; governed HR administration. These are proposed surface names, not verified routes. Avoid exposing an integration console as the employee product.

Reuse the standard Belimbing table and form components. Earlier feedback on the employee workbench showed a large filter form pushing the table below the fold. Preserve the requested improvement: compact search and common filters on one line where space allows, a labelled More filters control with count, removable active-filter chips, and saved views in a compact selector/dialog. A bare ellipsis is ambiguous for filters. Respect responsive layout and keyboard/focus behavior; do not force a desktop row on small screens.

Employee progression needs an explanation rather than a giant editable matrix. HOD batch assessment still needs efficient matrix entry and visible validation. HR dashboards need actionable drill-down, denominators and permission-safe exports. Printed forms and passports must remain usable for discussion and paper capture, while the canonical digital history remains singular. Detailed UI design must follow current DESIGN.md and be validated with real employee, HOD and HR scenarios after approval.

## Organisation Explorer Brief

Revision context: the CEO wants to drill from the whole company to departments and individual employees, inspect skill and training status, support HOD planning and retrieve records for audits. The owner accepted a general explorer with purpose-specific authorization. This is an Operate/Read surface within the existing Belimbing visual system, not a standalone training-only chart or a replacement design language.

Recommend a common organisation explorer with focused views rather than either a training-specific duplicate structure or a configurable all-purpose dashboard builder. The primary journey is company → department/team → position → employee → canonical supporting record. Organizational membership and reporting lines need not form the same tree; label the selected relationship clearly. Keep vacant positions visible to authorized users; do not use employees as substitutes for positions or count concurrent assignments as distinct people without explanation.

| Level | Skills/training view | Authorized next action |
|---|---|---|
| Company | Defined assessment/competency coverage, critical gaps, plan delivery and overdue follow-up | Drill to permitted department and source records |
| Department/team | Required versus available capability, backup coverage, approved plans and open needs | Open the canonical development action or draft training-plan workflow |
| Position | Required skills/certifications, occupancy and unmet coverage | Inspect applicable requirement version and staffing/capability gap |
| Employee | Verified standing, assessment validity, planned/completed training, certificates and follow-up | Open permitted assessment, participation, evaluation/effectiveness or passport evidence |

Keep trained, competent, unassessed, unverified, expired, below requirement and unavailable/stale data distinct. Indicators show their meaning, as-of/freshness and permitted denominator. Color supplements labels; it never carries status alone. Summary access does not imply permission to open employee details. Purpose/view selection changes presentation only, never permission. Do not render unauthorized payloads and merely hide their fields.

Use progressive drill-down with a navigable path, search and an equivalent keyboard-accessible tree/table view; avoid loading or shrinking the entire company into an unreadable canvas. Preserve context when moving between chart/list, employee detail and records. Large hierarchies need bounded loading; screen readers and narrow screens must support the same task without pan/zoom. Empty, incomplete, stale, loading and unavailable states must be truthful and distinct. Show restricted-state guidance only where policy permits acknowledging the resource.

The explorer is a record navigator, not an editable assessment ledger. HOD proposals and approval actions open existing workflows with explicit permission checks. Historical mode is visibly read-only; initiating a new plan requires an explicit return to current operational context so an old assignment or requirement is not silently used. Company structure maintenance, if offered later, is a separately authorized authoritative workflow, not drag-and-drop changes implicit in navigation.

An audit view selects a permitted period/as-of date and links to retained approved versions, verified evidence and change history. Show report scope, generation time and data cutoff/version context so corrections and incomplete historical sources are apparent. Reproducibility uses the 0001 history contract and retained manifest/records, not a screenshot of today's chart. Audit exports have their own permissions and traceable generation/download, including permission rechecks after revocation.

A small module contribution contract supplies authorized indicators and drill-through targets. Skills/Training remain responsible for meanings and source records; future operations views can reuse navigation without exposing HR fields. Package ownership is resolved by 0001, not by visual reuse alone. No speculative workflow designer, cross-module permission editor or new chart-specific policy engine is included.

Open implementation decisions: actual company scale/depth, graph relationship types from the authoritative source, HR-approved audience/aggregate disclosure policy and historical data availability. Validate these before selecting chart technology; do not invent size limits or promise historical reconstruction absent source evidence. The design skill informed the progressive, accessible record-first brief; no visual implementation or new design-system artifact is authorized in this revision.

## Phases

### Validate and agree the boundary

- [ ] Refresh relevant existing implementation and record reusable behavior, gaps and affected contracts.
- [ ] Resolve this workstream's open policy/interface decisions with the epic coordinator before dependent implementation.

### Implement after explicit owner resumption

- [ ] Validate role journeys and information visibility with employee, HOD and HR examples, including progression and approved training plans.
- [ ] Deliver company-to-employee organisation drill-down and equivalent accessible list/tree navigation with Skills/Training as the first complete purpose-specific view.
- [ ] Connect authorized HOD planning actions and historical read-only audit/evidence views; prove permission-sensitive indicators, exports and truthful unavailable/incomplete states.
- [ ] Deliver leave/attendance/shift queries and commands with capability-aware unavailable, stale, pending and unknown-outcome states.
- [ ] Compose skills/passport, planned training, nominations, evaluations and progression explanations over canonical contracts.
- [ ] Verify standard table/filter behavior, responsive layout, keyboard access, exports and three printable training forms.

### Integrate and hand off

- [ ] Attach tests, migration implications, validation results and remaining limitations; obtain independent review.
- [ ] Update this workstream checklist and report the achieved milestone to the epic coordinator. Do not mark an epic milestone complete from isolated unit tests alone.

## Acceptance and Handoff

Provide role-specific workflow evidence and accessible failure/recovery behavior. Detailed UI work must follow current DESIGN.md and relevant frontend instructions/skills when undertaken. Plan approval, event completion and competence verification must never share a misleading generic 'completed' label.
