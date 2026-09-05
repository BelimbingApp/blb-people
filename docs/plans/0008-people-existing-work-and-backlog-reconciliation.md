# 0008-people-existing-work-and-backlog-reconciliation.md

**Status:** Proposed; implementation remains halted pending explicit owner approval.
**Last Updated:** 2026-09-05 (Asia/Kuala_Lumpur).
**Agents:** codex/astra-medium.
**Sources:** [Overall epic roadmap](0000-people-epic-roadmap.md); 2026-09-05 repository/API snapshot and prior local inspection.

Read the epic's business context, shared decisions and halt instruction before this workstream. This numbered plan is a proposed assignment boundary, not authorization to start implementation. No agent is assigned by this document. Plan numbers are not the existing GitHub issue sequence.

## Problem Essence

Existing issues and merged work encode an earlier ownership decision. Continuing to drain them would turn historical assumptions into new implementation.

## Desired Outcome

Useful work is preserved and reconciled into one approved roadmap with canonical owners, dependencies and evidence.

## Ownership and Dependencies

The epic coordinator owns this workstream. Other agents report discoveries and preservation state rather than independently retitling/closing cross-cutting issues or claiming shared work. This snapshot is historical, not a fresh verification of remote state during the plan split.

## Design Decisions

Prefer traceable amendment and reuse over mass reopening, mass closure or wholesale rewrite. Numbered document IDs are independent of historical issue prefixes; no new GitHub issue numbers are allocated here.

## Verified Implementation Snapshot and Preservation Inventory
Snapshot date: 2026-09-05. This is repository/API evidence and limited local document inspection, not a runtime certification or complete code review. Refresh before resuming; other machines may have advanced.

| Repository | Local state observed | GitHub main observed |
|---|---|---|
| BelimbingApp/blb-people | main, clean before this plan; HEAD `e6ea57c9f0eaa07bad2d4c2b33db95bfc4d96d9b` | `559cdabdf0508d46f10ee5bac243d7cad36ef22e` |
| BelimbingApp/blb-people-connector | Separate checkout on `codex/1004-connector-persistence`, ahead 5/behind 5 relative to tracking; HEAD `3818dcf349add3741afa96525b1ffd06054aac21` | `5ed43a90d8847af3fca82545b6a3f404633a146b` |

Do not reset the connector checkout or infer current main behavior from it. The People local checkout also trails the observed GitHub main. Refresh safely without overwriting work; read current repository instructions first.

### Existing foundation to audit and retain

People locally contains Employees, Attendance, Leave, Claim, Payroll, Settings and Provider modules. Current connector main contains Connector, Skill and Training. Consequently, the proposed business move is substantive, not just changing issue titles.

Recent merged connector PR evidence includes [assessment history #80](https://github.com/BelimbingApp/blb-people-connector/pull/80), [development actions #98](https://github.com/BelimbingApp/blb-people-connector/pull/98), [scoped authorization #100](https://github.com/BelimbingApp/blb-people-connector/pull/100), [training events #102](https://github.com/BelimbingApp/blb-people-connector/pull/102), [fail-closed HR2000 profile #104](https://github.com/BelimbingApp/blb-people-connector/pull/104), [operator reconciliation #107](https://github.com/BelimbingApp/blb-people-connector/pull/107), [requirement publication #110](https://github.com/BelimbingApp/blb-people-connector/pull/110), [HOD verification #111](https://github.com/BelimbingApp/blb-people-connector/pull/111), and [training catalogue UI #113](https://github.com/BelimbingApp/blb-people-connector/pull/113).

Scheduled sync and published skill-requirement ports also have merged work ([#74](https://github.com/BelimbingApp/blb-people-connector/pull/74), [#79](https://github.com/BelimbingApp/blb-people-connector/pull/79)). Preserve company/tenant safeguards, stable identity mapping, cursor semantics, audit trails and regression tests when relocating business ownership. Merged does not mean feature-complete under this new plan.

Native adapter [connector #105](https://github.com/BelimbingApp/blb-people-connector/issues/105) remained open. An earlier adapter PR #106 closed unmerged. Do not confuse that number with People PR #106 or claim the adapter is delivered.

### Open PRs at halt publication

| Repository/PR | Work to preserve | Observed head SHA |
|---|---|---|
| [People #107](https://github.com/BelimbingApp/blb-people/pull/107) | Training request/approval/budget work for #33 | `3b5f1da6ac5fadeff655564585fc4c99e1195499` |
| [People #106](https://github.com/BelimbingApp/blb-people/pull/106) | Reassessment work for #15 | `b11878908279d6da2860eb1438e67891a3b8592f` |
| [Connector #116](https://github.com/BelimbingApp/blb-people-connector/pull/116) | Reassessment carrier #115 | `b6c16bd32e8a328830206ea0a4e999293c427d14` |
| [Connector #114](https://github.com/BelimbingApp/blb-people-connector/pull/114) | Connector-owned training-request plan | `f047ed038c1d3bf6a866c6098ac85c06c3685b1b` |

Halt comments were posted to all four, and to People masters #9/#20 and coordination issue #40. No implementation PR was merged or closed by this planning action. Ask owning agents to record branch/head, validation, unfinished work and review findings. Issue #40's title and current agent label differed; do not infer the active steward from its title alone.

### Documents to reconcile, not blindly obey

- People [HR data boundary](../contracts/hr-data-boundary.md): explicitly treats connector-owned skills as settled. This is an older approved boundary now under reconsideration, not proof this proposed change is already adopted. Retain its identity/security distinctions and re-audit claimed enforcement against current code.
- [Native provider adapter](people-provider-adapter.md): typed workforce bootstrap/change contracts and opaque cursors remain relevant; older progress checklists may predate merged connector work.
- [Service principal](people-connector-service-principal.md): co-located scheduler authority is not remote delegated employee authentication. A closed authentication issue does not certify the latter.
- Connector `docs/contracts/company-ownership.md`: preserve explicit attribution, tenant/company denial and reviewed identity remapping while deciding new physical ownership.
- Platform `docs/architecture/module-system.md`, `docs/architecture/tenancy.md`, `DESIGN.md`, relevant AGENTS.md and test instructions must be read on the destination machine before implementation.
## Roadmap Reconciliation: 1000 and 0000
0000 retains the business feature mandate, extended by progression. 1000 should become the integration/deployment boundary that supports People, not the place where missing provider business features accumulate. Numbered labels are historical sequence identifiers, not dependency or delivery proof.

The following is a proposed disposition, not an instruction to close or reopen issues now. Issue states were observed on 2026-09-05; every acceptance claim needs code/test evidence after the design is approved. All numbers in this table refer to BelimbingApp/blb-people unless stated otherwise.

| Existing issue(s) | Observed state | Proposed disposition after approval |
|---|---|---|
| #9 [0000] master | Open | Rebaseline as People-owned skills/training/progression programme; preserve workbook traceability and delivery evidence. |
| #10–#12 [0001–0003] catalogue, profiles, assessments | Closed | Audit/reuse merged work; migrate ownership safely. Closed issues do not need automatic reopening if new migration issues can carry remaining scope. |
| #13–#14 [0004–0005] actions and catalogue/events | Closed | Retain business lifecycle, code and tests; reconcile new training forms and progression dependencies. |
| #15 [0006] reassessment/history | Open | Preserve open work; essential prerequisite to trustworthy progression. Resume only under revised ownership. |
| #16 [0007] dashboards/coverage | Open | Retain; add explicit currentness, denominators and progression-safe visibility. |
| #17 [0008] workbook import | Open | Map all 18 sheets, source defects and authoritative-source distinctions. |
| #18 [0009] reminders/governance | Open | Retain; notifications follow scoped lifecycle and approved cadence, not obsolete connector assumptions. |
| #33 [0010] requests/approvals/budgets | Open | Preserve PR work; reconcile HOD/HR/financial authority and non-gap requests. |
| #34 [0011] participant records | Open | Retain attendance, hours/tests/certificates and evidence as training facts. |
| #35 [0012] evaluation | Open | Retain all criteria, employee completion and controlled visibility. |
| #36 [0013] effectiveness | Open | Retain 30/60/90 stages and verified reassessment; settle non-assessable closure policy. |
| #37 [0014] register/passport | Open | Retain; make it the employee's trustworthy standing/history surface, linked to progression. |
| #38 [0015] five-department cutover | Open | Retain; separate application relocation from business migration and prove both. |
| #19 workbench redesign | Closed | Preserve improvements and check standard-component UX; do not reopen solely for architectural relocation. |
| #20 [1000] master | Open | Narrow to integration, capabilities, deployment/security and source transition; explicitly supersede connector business ownership after approval. |
| #21 [1001] ownership boundary | Closed | Revise contract through a traced decision; preserve safety rules while changing business ownership. |
| #22 [1002] neutral SDK | Closed | Retain proven ports; resolve package dependency direction and employee command contracts. |
| #23 [1003] installable hub | Closed | Reassess portal-only installability and provider selection; avoid enabling duplicate native HR stores. |
| #24 [1004] supplemental persistence | Open | Split stable integration identity/projections from People business history and governed policy. Plan schema/data migration explicitly. |
| #25 [1005] identity/auth | Closed | Audit actual scope; add remaining remote delegation and sensitive-flow proof without treating closure as blanket completion. |
| #26 [1006] workforce projection | Closed | Retain; confirm source currentness, attribution, rehire and deactivation against current code. |
| #27 [1007] native adapter | Open | Retain minimal real vertical slice; reconcile connector #105 rather than duplicate claims. |
| #28 [1008] HR2000 | Open | Retain evidence-led discovery; scaffolding is not vendor capability proof. |
| #29 [1009] reliability | Open | Retain; include consequential employee commands and unknown outcomes, not only read sync. |
| #30 [1010] deployments | Open | Make co-located/remote auth parity and real infrastructure separation explicit. |
| #31 [1011] transition | Open | Extend to connector-business relocation and authority switches without history loss. |
| #32 [1012] operations/privacy | Open | Retain; include logs/exports/backups, restore and credential isolation. |

Newly explicit work needing scoped issues after approval: employee portal contracts and packaging; progression paths and policy publication; explainable eligibility; award/appeal history and payroll acknowledgement; business-ownership migration; full 18-sheet/form parity proof. Do not invent issue numbers now or reset the sequential history. Each implementation issue should have one canonical owner, relevant source requirements, dependencies and proof; carrier issues should link rather than duplicate requirements.

### Training-plan gap to add to the approved backlog

Existing request/approval/budget work (#33 and its carriers) must be audited against [0003](0003-people-training-planning-and-delivery.md). An approved individual request or event register does not satisfy a versioned organizational/departmental training plan. Preserve existing work while adding plan consolidation, approval/amendment, plan-to-event execution and optional financial controls. Add ISO/control/evidence traceability without claiming an ISO-mandated annual template or approval hierarchy.


## Phases

### Validate and agree the boundary

- [ ] Refresh relevant existing implementation and record reusable behavior, gaps and affected contracts.
- [ ] Resolve this workstream's open policy/interface decisions with the epic coordinator before dependent implementation.

### Implement after explicit owner resumption

- [ ] Refresh repository heads, open work and agent acknowledgements without overwriting checkouts; record actual validation and unmerged work.
- [ ] Reconcile old 0000/1000 issues with approved workstream boundaries and preserve prior implementation evidence.
- [ ] Create/amend bounded issues only after the owner approves the direction; attach canonical plan links, dependencies and review evidence.
- [ ] Record cross-repository release order and avoid partially landing incompatible contracts.

### Integrate and hand off

- [ ] Attach tests, migration implications, validation results and remaining limitations; obtain independent review.
- [ ] Update this workstream checklist and report the achieved milestone to the epic coordinator. Do not mark an epic milestone complete from isolated unit tests alone.

## Acceptance and Handoff

After approval, include the organisation explorer, effective-dated structural history and purpose-specific authorization gaps in canonical work under 0001/0005/0007, with indicators from 0002/0003 and migration inputs from 0006. Do not allocate a second chart-specific employee/assessment store or treat org position as an access grant. This plan amendment does not itself create or modify GitHub issues.

The coordinator can explain where every original numbered issue and open PR went, which behavior was reused, which assumption was superseded and what remains. No issue is closed as implemented solely because a plan was written or moved.
