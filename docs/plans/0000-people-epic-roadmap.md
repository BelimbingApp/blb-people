# 0000-people-epic-roadmap.md

**Status:** Proposed; owner-directed implementation halt remains in effect.
**Last Updated:** 2026-09-05 (Asia/Kuala_Lumpur).
**Agents:** codex/astra-medium.
**Sources:** Owner discussion and CEO email below; numbered workstreams indexed below; revised workbook provenance in 0006; historical issues/PRs in 0008; [People halt](https://github.com/BelimbingApp/blb-people/issues/108) and [connector halt](https://github.com/BelimbingApp/blb-people-connector/issues/119).

## Problem Essence
The business needs trusted HR administration and an accessible employee experience, including a complete skill-to-training-to-progression journey. The existing roadmap conflates integration ownership, business ownership, and physical deployment, risking a connector that becomes a second HR application and a portal that either exposes sensitive HR data or cannot deliver the intended employee experience.
## Desired Outcome
Employees use general Belimbing to understand their employment-related tasks, request leave, see attendance and shifts, manage training participation, and see their current competency and advancement requirements. HR governs authoritative HR operations in an appropriately restricted backend. HODs own departmental competency standards, assessments and improvement actions. Published progression rules explain how skills and positions relate to incentives and salary, without treating an assessment score as an unauthorized payroll instruction.

People remains a coherent business domain. Its employee-facing and administrative surfaces can operate together initially or across a real security boundary. Native People and HR2000 are integration targets, with capabilities enabled only when proven. Existing useful implementation and historical data are preserved.

This document is a portable design and implementation handoff, not approval to resume coding. It contains business context intentionally so another machine does not need this conversation to reconstruct intent.

### Immediate operating instruction

The owner's halt instruction was: “Make it comprehensive, I need to halt further development based on issues. Because the plan will be carried in another machine with more tokens budget, the plan needs good context.” This supersedes the earlier mission to join the AI Team as faith-sol and drain the People repositories.

- Do not claim, implement, or merge work merely because an existing issue is ready, assigned, or under review. This includes carriers of the 0000/1000 roadmaps.
- Preserve branches, commits, tests, review findings and uncommitted work. A pause is not permission to discard or close work as completed.
- Read-only discovery and refinement of this plan may continue. Stop recurring issue-draining activity for these two repositories.
- Only an explicit owner instruction lifts the halt. Reviewing this document, resolving a question, or finishing a draft does not implicitly lift it.
- Halt notices were published, not enforced through branch protection. They do not prove every other agent, machine or automation has acknowledged or stopped. Obtain acknowledgements before considering coordination complete.
- This halt does not apply to unrelated platform or other-domain work. An urgent exception affecting People requires owner direction rather than an agent-created exception.
## Business Context and How the Direction Changed
### Original operational mandate

The CEO wrote to the HR manager:

> Hi Khairul,
> Please find attached the proposed SBTG Skill and Training Management System, which we intend to implement as the replacement for our existing training portal.
> The new system will manage skill requirements, staff assessments, training requests and records, post-training evaluations, effectiveness reviews and updated competency scores.
> Please review it and propose the implementation and data-migration plan, starting with Production, Engineering, QAC/R&D, Planning and IT. HR will govern the system, while each HOD remains accountable for departmental skill requirements, assessments and improvement actions.
> Thank you.

This establishes a replacement programme, including migration and governance, rather than merely a skill table or course catalogue. The five departments are the initial rollout cohort, not hard-coded platform departments.

### Evolution of the architecture discussion

1. The original 0000 sequence described workbook-derived skills and training in People.
2. The owner raised the need to keep sensitive HR administration separate from a general company installation. A server/client split was considered, then a provider-neutral connector.
3. The 1000 sequence required native People and HR2000 adapters. SBG uses HR2000. Skills/training were moved conceptually into the connector because HR2000 did not supply the desired system. Existing issues and code reflect that decision.
4. The owner is now reconsidering that placement: employee self-service belongs in general Belimbing, restricted HR administration can run separately, and the business capability should be built coherently in People.
5. The owner additionally clarified the purpose of the matrix: transparent employee progression tied to salary. Employees should know where they stand, what they can aim for, and what is required. HODs map skills to positions and incentives.

The salary/progression requirement is a later owner clarification. It is not a quotation from the CEO email or a claim that the workbook already defines a complete compensation policy.

### Confirmed requirements versus proposals

Confirmed: employee self-service; optional separate HR backend; native/third-party integration; HR governance; HOD accountability; full workbook feature coverage; progression transparency with salary linkage; first-class position-linked job descriptions and KPI/performance management; a development halt while rethinking the approach.

Recommended here, not yet ratified: People owns skills/training/progression; connector owns integration; one writer per capability and scope; HOD proposes incentive mappings while HR and an authorized compensation approver publish them; initial awards are reviewed rather than automatically changing pay; separate deployment uses the same business contracts as co-location.

Unknown: SBG's exact HR2000 edition, interface rights and supported operations; the authorized compensation decision-maker; whether particular incentives are contractual entitlements; data residency/retention requirements; acceptable portal-operator trust; actual production deployment/data footprint; whether employees may see salary bands or exact incentive amounts. Do not fill these gaps with implementation defaults that create business commitments.

### Additional confirmed training requirement

The owner subsequently clarified that training is based on ISO and requires an organizational/departmental training plan, approval of that plan, execution through in-house or external programmes, and optional financial budgeting. Requests, course catalogues and event records alone are insufficient. [0003](0003-people-training-planning-and-delivery.md) owns the full lifecycle and standards/control/evidence mapping.

Use ISO 10015:2019 as primary development guidance, ISO 9001:2015 clause 7.2 as an applicable QMS competence baseline, and ISO 30422:2022 as complementary learning guidance, subject to licensed-text review and confirmation of SBG's applicable standards/editions. Exact plan periods, approval hierarchy, budget controls and review intervals are company controls, not blanket ISO mandates. The official sources and limits are in 0003. No ISO certification claim is made.

### Additional confirmed job-description and performance requirement

The owner also explicitly requested first-class, position-linked job descriptions and KPI/Performance Management. [0009](0009-people-job-descriptions-and-performance.md) owns structured job-description versions, KPI definitions and assignments, measures/targets/periods/owners/evidence, effective-dated history, scoped visibility and organisation-explorer drill-through. It keeps job descriptions, skills, performance evidence, progression eligibility and compensation as linked but separate records and workflows. Publication, appraisal, appeals and any performance-based progression policy remain recommendations pending business confirmation; this plan does not authorize implementation.

## Numbered Plan Index and Reading Order

This revision splits the former single handoff into an epic and nine workstreams, adds the owner's ISO-aligned training-plan and JD/performance requirements, and defines parallel-agent boundaries. It preserves the workbook map, historical snapshot, open-work inventory and prior design reasoning rather than restarting the programme.

Read this epic first, then 0008 for historical implementation context, then the assigned workstream and its dependencies. Transfer the whole numbered plan set, not a single child document. Numbers identify plans; they do not rename or correspond one-for-one to the old [0000]/[1000] GitHub issue prefixes.

| Plan | Canonical responsibility | Inputs/dependencies |
|---|---|---|
| [0001 Architecture and providers](0001-people-architecture-and-provider-boundaries.md) | Authority, identity, packaging, native/HR2000 adapters, transport/security contracts | Approved epic direction and provider/trust evidence |
| [0002 Skills and assessment](0002-people-skills-and-assessment.md) | Requirements, assessments, verification, gaps and coverage | 0001 identity/scope; interfaces to 0003/0004 |
| [0003 Training planning and delivery](0003-people-training-planning-and-delivery.md) | ISO-aligned needs, approved plans, optional budget, internal/external execution and evaluation | 0001 workforce/scope; 0002 evidence links |
| [0004 Progression and compensation](0004-people-progression-and-compensation.md) | Published paths, eligibility, awards, appeals and payroll acknowledgement | 0001 and 0002; approved compensation policy |
| [0005 Employee and management experience](0005-people-employee-and-management-experience.md) | Employee self-service and shared role-based presentation | Agreed business/query/command contracts from 0001–0004 |
| [0006 Data migration and workbook parity](0006-people-data-migration-and-workbook-parity.md) | All 18 sheets, source evidence, ownership migration and cutover reconciliation | Target contracts from 0001–0004; HR/HOD source approval |
| [0007 Verification, security and rollout](0007-people-verification-security-and-rollout.md) | Independent integrated acceptance and release evidence | Feature evidence plus 0006 recovery/cutover proof |
| [0008 Existing work and backlog](0008-people-existing-work-and-backlog-reconciliation.md) | Historical snapshots, preserved PRs, issue reconciliation and release coordination | All workstreams report evidence and changes |
| [0009 Job descriptions and performance](0009-people-job-descriptions-and-performance.md) | Position-linked job descriptions, KPI definitions/assignments, performance evidence and governed reviews | 0001 position/identity/scope; 0002 competency references; 0004 progression policy; 0006 migration and 0007 verification interfaces |

The detailed checklist within each child is canonical for its work. This epic tracks cross-workstream outcomes, decisions and integration milestones. Do not duplicate a child's task status into another issue or document without linking its canonical evidence.

## Top-Level Components
These are responsibilities, not promises about current class names or final package names.

| Component | Owns | Must not become |
|---|---|---|
| People employment/HR administration | Native authoritative employment and supported HR workflows, including sensitive payroll where selected | A mandatory duplicate of HR2000 data and rules |
| People Skills | Catalogue, requirement versions, evidence-backed assessments, competency projections, gaps and coverage | A side effect of provider synchronization |
| People Training | Needs, approved plan versions, optional budget, catalogue/events, participation, evaluation and effectiveness | A second editable copy of assessment history |
| People Progression | Published paths and incentive rules, eligibility explanations, reviews/appeals and approved decision history | A hidden formula that directly changes payroll |
| People Job Descriptions and Performance | Position-linked JD versions, KPI definitions/assignments, period results, evidence and governed review history | A combined employee score, executable authority from job text or an automatic pay decision |
| Employee-facing People surface | Own tasks, leave/attendance/shift access, learning, passport and progression explanations | A general-administrator view of restricted HR records |
| HOD and HR surfaces | Department-scoped operational decisions and HR-wide governance according to grants | Broad access granted merely by job title or navigation visibility |
| Connector | Provider bindings, adapters, capability discovery, identity mapping, allowed projections, transport and reconciliation | The owner of skills/training because another provider lacks them |
| Platform Base/Core | Existing tenancy, authorization, identity primitives, scheduler, module discovery and shared shell | HR-specific workflow logic or a speculative universal integration framework |

Employees, assessors, trainers, HODs, HR coordinators, HR administrators, compensation approvers and integration operators have different actions and data needs. One person may hold several scoped roles, but assignment must be explicit and auditable. User accounts and employees are not synonymous: employment may predate access; a person may have concurrent employments or be rehired.
## Design Decisions
### Options considered

**A. Keep skills/training in a broad connector application.** Least immediate movement of merged code and viable without native HR. However, the connector becomes a business product, policy ownership is obscured, and “connector” no longer describes its responsibilities. This preserves the architectural confusion now being questioned.

**B. Modular People business capabilities with integration-only connector and explicit employee/admin surfaces. Recommended.** Aligns ownership with business change, reuses existing modules, and supports either native or external HR. Costs include a careful migration of existing connector-owned business code and resolving installability without circular dependencies. This is the best fit for low entropy, deep modules and the owner's intended workflow.

**C. Immediately split People into separately deployed services or a new server/client repository pair.** Makes deployment separation prominent but forces distributed coordination, release and migration costs before trust, provider interfaces and capability ownership are settled. Reserve deployment separation for a real security/operating need; do not require a service per module.

### Ownership and deployment are distinct decisions

Under B, skills/training can live on the general instance if the owner accepts its administrators as custodians of those records, or on the restricted People backend with an employee-facing projection. In either case, there is exactly one authoritative home for each capability and scope. Two enabled installations must not independently maintain the same assessment or training record.

Supported target arrangements:

1. **Co-located native:** native HR, Skills, Training, Progression and employee surface share an installation. Public application contracts still enforce actor and scope checks; in-process calls do not bypass them.
2. **Separated native:** restricted People owns designated authoritative data and workflows; general Belimbing hosts employee access. Remote contracts carry narrow authorized queries/commands, not unrestricted database access.
3. **Third-party HR:** HR2000 owns verified employment/payroll capabilities; native People owns selected skills/training/progression. Employee requests use only proven provider interfaces; unsupported actions are honestly unavailable or follow a specifically approved, reconciled manual process.

Different authoritative systems for different capabilities are acceptable. Two writers for the same capability/scope are not. Record authority by capability, installation and tenant/company scope, not a single global “HR source” switch. Do not build an arbitrary per-field orchestration engine; begin with explicit supported configurations and migrations between them.

### Packaging must be proven, not assumed

The platform treats a Domain as the installable/enableable unit and Modules as owned components. Putting a Portal module under People does not prove a portal-only installation can omit payroll migrations, routes, providers or dependencies. Audit discovery, configuration, migrations, route registration and dependency loading before choosing packaging.

Prefer a minimal supported way to deploy the employee surface without activating local authoritative HR storage. If the platform cannot support that honestly, propose a separately installable presentation package/domain with a truthful name. Keep business ownership in People and avoid premature `blb-people-server`/`client` renames. Final repository names and install manifests remain a design follow-up, not an implicit approval in this plan.

Connector SDK/contracts currently live with connector work. Ensure People business logic depends on stable application-owned ports rather than adapter implementations. Native outbound read contracts and adapter translation must not create a People-to-connector-to-People cycle. Extract a small shared contract package only if dependency proof justifies it; do not move HR business concepts into Core merely to break imports.
## Shared Contract and Boundary Rules

### Organisation explorer and purpose-specific authorization

Owner clarification: the CEO needs a company-wide organisation chart drillable to departments, positions and employees, showing skill standing and training details, supporting HOD planning and access to audit records. The owner accepted a reusable organisation explorer with purpose-specific views and authorization for different needs. This revision extends 0001's structure/history/authz contracts, 0005's explorer brief and 0007's audit/denial proof; 0002/0003 supply indicators and canonical records, while 0006 preserves organisational history. It does not authorize implementation or a new generic dashboard engine.

Build one explorer over authoritative organisational structure, with skills/training as the first complete view. Other operational views can supply explicitly authorized indicators later. Keep company/department/team structure, positions, employee assignments and reporting relationships distinct, including vacancies and effective dates. A reusable display does not itself justify placing HR data in Core or creating another editable structure store.

Visibility of structure, indicators, employee details, evidence, planning actions, approvals and exports are separate permissions. Reporting hierarchy is context for scoped policy, not an automatic grant. A CEO/HOD/auditor title alone conveys no unrestricted access. Reuse platform authorization at backend boundaries; a selected view or declared purpose cannot elevate privilege. Audit views navigate retained canonical records and approved versions rather than treating today's chart or a screenshot as evidence of historical competence.

The detailed explorer brief is in [0005](0005-people-employee-and-management-experience.md), with shared authorization and historical query rules in [0001](0001-people-architecture-and-provider-boundaries.md). These remain within the existing workstreams; no new parallel owner may independently implement organisation identity or policy.

The detailed authority/security/transport contract is in 0001. Shared invariants apply to every workstream: one authoritative writer per capability/scope; stable workforce identity independent of provider IDs; explicit tenant/company attribution; fail-closed authorization; no broad HR credentials or sensitive replication that defeats the chosen separation; and no silent fallback to local authority.

Skills owns finalized competency evidence. Training owns approved plans and learning delivery, not assessment scores. Progression owns approved reward policy and eligibility/decision history, not payroll calculation. Plan approval is distinct from spend approval; course completion is distinct from competence; eligibility is distinct from entitlement and payment.

The canonical business journey is needs/requirements → approved training plan and authorized participation → internal/external delivery → evaluation/effectiveness → verified reassessment → explainable progression → authorized reward decision → payroll acknowledgement where supported. Individual gap assessment can initiate that journey; non-gap training is also valid.

## Parallel-Agent Delivery Model

Multiple agents can accelerate bounded work after direction and contracts are agreed. More simultaneous implementation before those agreements would amplify incompatible assumptions. The owner's request to organize for agents does not lift the existing implementation halt or assign agents now.

### Single ownership and coordination

Appoint one epic coordinator responsible for shared decisions, interface changes, dependency readiness, issue reconciliation and integrated release order. Each active workstream has one accountable owner and a separate reviewer where feasible. Agent identities, worktree/branch, base revision, affected files, dependencies and current evidence must be recorded in its assignment/handoff.

Assign bounded deliverables within plans, not an unqualified instruction to drain a repository. Before editing shared identity/schema/authentication contracts, coordinate with 0001 and 0006. Before changing shared routes/components, coordinate with 0005. Do not let multiple agents independently define the same aggregate or migration.

A cross-boundary change needs a written contract update and acknowledgement from affected owners before dependent work proceeds. Record inputs, outputs, scope/permission checks, status/error semantics, version/effective-date behavior and proof. The coordinator can broker decisions but cannot decide unsettled HR policy or lift the owner's halt.

### Safe parallel waves

| Wave | Work that can run together | What must wait |
|---|---|---|
| Planning while halted | Current-code/provider discovery, workbook/source inventory, HR/QMS policy validation and test-scenario design | Feature implementation, old issue claims and merges |
| Foundation after approval | 0001 shared contracts/packaging; 0006 migration inventory/design; 0007 acceptance design; 0008 preservation/rebaseline | Dependent schemas and provider-backed UI must not guess unresolved interfaces |
| Business slices after contract agreement | 0002 Skills and 0003 Training, with explicit assessment/event boundaries; 0004 policy model; 0005 composition against agreed interfaces | Final eligibility needs verified assessment semantics; real command delivery needs proven provider support |
| Integrated delivery | 0004 eligibility/awards, 0005 live employee journeys, 0006 dry-run migrations, 0007 cross-boundary verification | Cutover and release acceptance require combined evidence, not isolated green checks |
| Rollout | HR/HOD training, operator readiness and reconciled cohort rollout | Retirement of old writes until cutover/recovery acceptance |

Provider discovery, policy clarification and acceptance design need not wait for all architecture implementation. Likewise, a delayed HR2000 write capability need not prevent native training development against a proven workforce source, but the product must state which deployment has actually been validated.

Use isolated worktrees where appropriate and obey each repository's current branch/commit authority. Do not manufacture permission to push/merge from this plan. Land mutually dependent cross-repository changes in a documented compatible order. Preserve unmerged work during the pause.

### Definition of an agent handoff

Report the bounded outcome, commits/files, consumed/changed contract versions, tests and environment, authorization/migration effects, unfinished work and required reviewer/consumer action. A mocked integration is labelled as such. The receiving agent verifies dependency evidence rather than assuming a “done” message proves compatibility.

## Phases

### Phase 0 — Context and halt

- [x] Publish halt records and notices to the known open implementation PRs/masters. codex/astra-medium.
- [x] Preserve business context, workbook mapping and historical implementation inventory in a portable plan set. codex/astra-medium.
- [x] Split the epic into numbered workstreams and add approved training planning, ISO reference boundaries, optional budget semantics and the confirmed JD/KPI/performance workstream. codex/astra-medium.
- [ ] Confirm active-agent/automation halt acknowledgements; published notices alone are not proof.
- [ ] Transfer all numbered plans and the authorized workbook, or obtain explicit permission to commit/push the plan set.

### Phase 1 — Validate direction before resuming

- [ ] Resolve applicable HR/QMS/compensation policy and provider/trust/package decisions through 0001–0004, 0008 and 0009.
- [ ] Audit reusable implementation and estimate controlled ownership migration without discarding existing work.
- [ ] Obtain explicit owner approval of the direction and instruction to resume implementation.

### Phase 2 — Rebaseline and establish contracts

- [ ] Reconcile historical issues/PRs and assign bounded owners under 0008.
- [ ] Agree identity, authority, plan/event/assessment and progression interfaces with migration implications.
- [ ] Establish cross-repository compatibility/release sequence and independent acceptance criteria.

### Phase 3 — Prove the core business journey

- [ ] Integrate real workforce identity, governed requirements and verified assessment.
- [ ] Execute an approved training-plan item through delivery, evaluation, effectiveness and reassessment with optional budgeting.
- [ ] Demonstrate employee standing, explainable performance/progression and authorized reward handling without implicit payroll changes.
- [ ] Prove failures, scope denials, history/version preservation and provider limitations.

### Phase 4 — Complete product coverage

- [ ] Deliver the authorised organisation explorer from company to employee, with skills/training drill-through, HOD planning and reproducible historical audit views under 0001/0005/0007.
- [ ] Validate all 18 workbook families and three forms plus first-class training planning and JD/KPI/performance coverage.
- [ ] Deliver prioritized employee leave/attendance/shift capabilities against verified backend support.
- [ ] Complete HR/HOD governance and standard-component employee experience with integrated review.

### Phase 5 — Migration and rollout

- [ ] Prove ownership/source migrations, one-writer cutover and post-write recovery under 0006/0007.
- [ ] Obtain HR/HOD acceptance and operational/security readiness for the five-department rollout.
- [ ] Retire old training-portal writes only under approved cutover and retention arrangements.

## Decisions to Resolve and Principal Risks
| Decision | Recommended starting position | Who/evidence is needed |
|---|---|---|
| Business ownership | People owns Skills/Training/Progression; integration-only connector | Owner architecture approval and current dependency/data audit |
| Skills/training hosting | Choose one authority per scope; host according to accepted sensitivity and operator trust | Owner + HR, actual hosting/access model |
| Portal packaging | Minimum independently deployable employee surface without duplicate HR authority | Platform topology/dependency proof |
| HR2000 functions | Enable only supported verified operations | HR + vendor/maintainer documentation and test access |
| Compensation publication | HOD technical proposals, HR review, authorized compensation approval | Owner names decision-maker and delegation limits |
| Employee transparency | Publish applicable criteria and approved reward linkage; protect others' pay and confidential notes | HR/owner policy on amounts, bands and deliberations |
| Eligibility versus entitlement | Reviewed award first; automation only after explicit policy approval | Approved compensation policy and relevant professional review |
| Assessment independence/appeals | Verified evidence and documented review, with conflict controls | HOD + HR operating procedure |
| Training closure exceptions | Strict skill-gap closure, explicit non-assessable training route | HR/HOD confirmation of training categories |
| Existing-data transition | Preserve IDs/history, rehearse migration, one writer after cutover | Installation inventory, source owners and reconciliation proof |
| Retention and sensitive self-service | Minimize copies; separately authorize payslip/document access | HR/security and applicable policy/legal requirements |

Main risks are business logic remaining trapped in connector packaging, accidental dual authority, broad portal credentials undermining physical separation, treating unverified HR2000 functionality as available, implied salary promises, destructive schema moves, workbook formula defects being imported as facts, and continued issue-driven development against obsolete assumptions. Address these with the decisions and phase evidence above rather than a large speculative framework.
## Destination-Machine Handoff

Read this epic and both halt issues first, then 0008 for historical state and the assigned workstream's dependencies. The immediate mission remains design validation, not draining the old backlog. A larger token budget does not authorize new policy decisions or implementation.

Transfer this entire numbered plan set and the authorized workbook identified in 0006. No employee records or credentials belong in these public-facing plans. Local paths and commit hashes in 0008 are historical orientation, not instructions to reset checkouts. The earlier unnumbered plan was redundant and has been removed; the numbered plan set is the sole canonical design surface.

Each child owns its detailed checklist; this epic owns shared decisions and integration milestones. Update dates, evidence and affected contracts when reality changes. Historical snapshots must be refreshed before implementation. The plan split did not refresh remote repository state, implement features, edit the workbook, change GitHub issues or lift the halt. These documents do not authorize implementation or policy decisions.
