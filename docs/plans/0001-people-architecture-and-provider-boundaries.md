# 0001-people-architecture-and-provider-boundaries.md

**Status:** Proposed; implementation remains halted pending explicit owner approval.
**Last Updated:** 2026-09-05 (Asia/Kuala_Lumpur).
**Agents:** codex/astra-medium.
**Sources:** [Overall epic roadmap](0000-people-epic-roadmap.md); prior architecture discussion; existing data-boundary/provider/service-principal references in the evidence plan.

Read the epic's business context, shared decisions and halt instruction before this workstream. This numbered plan is a proposed assignment boundary, not authorization to start implementation. No agent is assigned by this document. Plan numbers are not the existing GitHub issue sequence.

## Problem Essence

Co-location, remote HR and external providers must not produce competing authorities or expose HR through a broadly privileged employee portal.

## Desired Outcome

One declared writer per capability/scope, stable identity, explicit package dependencies and equivalent authorization across supported transports.

## Ownership and Dependencies

This workstream owns shared identity/authority/capability contracts, provider adapters, deployment packaging and interface compatibility. Other agents may consume agreed contracts, but must not change them independently. Coordinate all schema and identity changes with 0006. Keep Skills/Training/Progression business behavior outside the connector.

## Design Decisions

Use the epic's modular People recommendation. Within that boundary, prefer small typed application contracts over direct model access or a speculative generic workflow/proxy framework. In-process transport is appropriate for co-location; remote transport adds authentication, failure and reconciliation semantics without weaker business authorization. Final packaging requires discovery proof, not a module-level toggle assumption.

## Authority, Identity and Data Boundaries
| Record/capability | Authoritative writer | General-instance treatment |
|---|---|---|
| Employment identity, status, organisation, manager and position | Selected native HR or HR2000 capability | Allowlisted, timestamped workforce projection and stable identity mapping |
| Leave policy, balance and final application decision | Selected leave backend | Authorized query/command and request receipt; no competing local balance ledger |
| Attendance fact/correction and approved timesheet | Explicit selected attendance authority | Employee display/submission; distinguish captured event, pending correction and accepted fact |
| Shift assignment and change approval | Declared roster authority, which may differ from payroll | Scoped employee schedule and authorized change requests; do not silently take over operations scheduling |
| Skills, requirements and assessments | Selected People Skills installation | Authoritative locally only when assigned; otherwise restricted projections/query access |
| Training and participation | Selected People Training installation | Own participation/evaluation actions and permitted history |
| Progression policy and eligibility | Selected People Progression installation | Published applicable rules and employee-specific explanation, not all confidential deliberations |
| Approved compensation decision | Authorized HR/compensation workflow | Minimum employee-specific result; controlled transfer to payroll |
| Payroll calculation, bank/tax details and payroll ledger | Restricted selected payroll backend | No broad replication. Any payslip/self-service access needs separate verified authorization and storage design |
| Provider credentials, mapping and sync receipts | Connector operational boundary | Narrow operator access; no credential exposure or raw sensitive payload logs |

Maintain distinctions already identified by the existing contracts: platform tenant, platform company, stable workforce company/entity, provider connection, external reference and login actor. Never join across installations by coincident numeric IDs, email address or display name. External reference includes provider/source identity, resource type and identifier. Provider replacement should retain stable workforce identity and linked history under reviewed mapping.

Every tenant-owned record carries tenant scope according to platform conventions. Missing tenant or company attribution fails closed. Cross-company transfers, manager delegation, historical organisation changes, multiple employment relationships and termination require explicit effective dates. Current managers must not automatically gain all historical or unrelated-company records. Deactivation does not erase historical attribution.

### Security boundary

Application role controls cannot hide locally stored data from the machine/database administrator. A separate HR server is meaningful only with separate administrative authority, databases, backups, keys, service credentials, logs and recovery access where that is the intended threat model.

Decide whether the general portal operator is trusted to assert employee identity. If trusted, use authenticated, audience-bound, short-lived delegated authority with backend enforcement. If not trusted, require an independently trusted identity assertion and/or direct HR-hosted flow for sensitive operations. A portal holding a broad HR token can impersonate employees regardless of a polished local login screen.

The backend rechecks employee binding, tenant/company, operation and record access. Authentication/SSO alone is not authorization. Co-located and remote transports must pass equivalent denial tests. Background sync principals are not employee-delegation credentials and should not gain leave approval or payroll access.

Minimize projected fields and retained attachments. Sensitive documents need authorization on each access, safe expiry, malware/file validation and audited downloads. Exports, notifications, caches, browser storage, search indexes, tracing and backups are part of the boundary. Do not leak salary, assessment evidence or other employees' information through aggregates, filenames or error messages. Retention and deletion rules require approved policy and jurisdiction-specific review before implementation.
## Public Contract and Failure Semantics

### Organisation structure, history and authorization contract

Revision context: the owner accepted a reusable organisation explorer with distinct permissions for workforce overview, skill/training inspection, HOD planning and auditing. This workstream owns its structure/identity/history and policy contracts; 0005 owns presentation, not a second authorization engine.

Resolve the authoritative source for organisational units, positions and assignments explicitly. Prefer existing platform/company and selected workforce sources where they own those facts; do not duplicate them in a chart store or assume HR2000 supplies every relationship. Treat legal-company scope, structural membership and managerial reporting as different relationships. Preserve stable positions independently of occupants so vacancies remain visible. Support effective-dated acting/multiple assignments and explicit primary versus secondary reporting without duplicate headcount or graph cycles in hierarchical traversal. Missing/ambiguous source relationships are incomplete data, not invented reporting lines.

Use the platform's authorization policies/actors with explicit tenant/company, resource, action and scope. A hierarchy may resolve an authorized departmental scope; being above somebody in the chart does not itself grant access. View selection and purpose labels are context, never authority. Separate structure browsing, aggregate indicator access, individual detail, evidence/document access, proposing plans, approval, structural maintenance and export. Planning from a node calls the owning business workflow and revalidates scope; chart navigation never grants write access to HR master data.

The owner explicitly confirmed separate permissions for organisation visibility, skill/training access, planning/approval actions and audit exports, with no automatic grants inherited from chart position. The specific audience/scopes below remain proposed policies for business confirmation:

| Audience | Intended scoped access | Must remain separately granted |
|---|---|---|
| CEO/executive | Approved company-wide structure and selected aggregate indicators; employee drill-down where granted | Personal evidence, compensation details, structural edits and approvals |
| HOD/delegate | Assigned departments, permitted employee skill/training detail and proposal actions | Other departments, financial/HR approvals, confidential notes and historical access outside scope |
| Employee | Permitted directory structure plus own published standing/training records | Colleagues' assessments, private notes, payroll and departmental planning |
| HR | Explicitly assigned HR governance capabilities and scopes | Payroll/compensation administration and unrelated-company access |
| Auditor | Read-only approved engagement scope, period and evidence classes, with expiring access | Operational edits, approvals, unrestricted personnel exports and unrestricted payroll |

Enforce policies before returning nodes, search results, aggregates, evidence links or exports, and again on authoritative commands. Permit aggregate-only reporting only through an explicit aggregate permission; do not assume record-level access is necessary or that an unrestricted aggregate is harmless. Define permitted populations, denominators and small-cohort/disclosure controls with HR/security. Unauthorized results must not leak through totals, layout placeholders, caches, facets, IDs or downloadable files. Distinguish legitimately visible incomplete data from zero without announcing hidden records' existence.

Historical queries carry an effective/as-of date plus the relevant data/version cutoff where late corrections matter. Preserve effective dates separately from recorded/change timestamps, approved requirement/plan versions and evidence provenance. Current authorization governs the request: choosing an old date does not resurrect an expired HOD/auditor grant. Grant access to historical subjects/periods explicitly. Snapshot/report manifests retain scope, filters, versions/cutoff, generation time and provenance; retrieval/export still checks current permission. Preserve prior report evidence under approved retention while making later corrections distinguishable, not silently rewriting a past result.

Revocation must invalidate or bound cached, queued and downloaded-link access appropriately. Recheck authorization when generating and retrieving asynchronous exports. Record sensitive access/export activity with actor, scope and operation without copying sensitive contents into logs. Existing separate-host/administrator trust limits still apply.

Use provider-neutral business queries and commands with explicit capabilities. Unsupported is different from unavailable, unauthorized, stale or empty. An undeclared capability must be rejected at the actual execution boundary, not merely hidden in navigation.

Queries return permitted data with source and freshness where consequential. Commands validate the requesting actor and target employment on the authoritative side, use stable idempotency keys and concurrency/version checks, and expose a traceable request identifier. The portal must distinguish locally received, submitted, accepted, pending approval, approved/rejected, cancelled and delivery-unknown states as applicable.

A timeout after delivery is an unknown outcome, not proof of failure. Reconciliation or safe retry must discover the original result without duplicate leave, attendance, nominations or compensation decisions. Provider outages must not silently switch authority to a local fallback. Read-only stale views may remain useful when labelled; consequential decisions require freshness rules. Offline queuing is a separate approved capability, not an automatic convenience.

Sync needs bounded bootstrap/delta contracts, stable cursors, replay, tombstones or explicit deactivation, ordering/conflict rules, retries, dead-letter handling and operator reconciliation. Missing records in a partial response must not deactivate employees. Mapping changes and identity merges require scoped authority and audit. Raw payload retention must not evade the projection allowlist.

HR2000 discovery must establish edition/version, licensed modules, hosting, vendor-supported API or file formats, allowed read/write operations, identifiers, authentication, scopes, pagination/deltas, errors, rate limits, sandbox/test data, support and data-use rights. A fail-closed adapter profile is useful scaffolding, not proof of a working vendor integration. Do not assume leave submission, roster changes, payslip retrieval or payroll writeback exist.

Where only files are supported, document an approved import/export protocol with provenance, approvals and reconciliation. Do not automate direct writes to a vendor database or portray an exported request as accepted by HR.

## Phases

### Validate and agree the boundary

- [ ] Refresh relevant existing implementation and record reusable behavior, gaps and affected contracts.
- [ ] Resolve this workstream's open policy/interface decisions with the epic coordinator before dependent implementation.

### Implement after explicit owner resumption

- [ ] Agree authoritative organisation/position/assignment sources and effective/recorded-time history; prove vacancy, acting assignment, transfer and multiple-reporting semantics.
- [ ] Publish explorer query/indicator/action contracts and a business-approved resource/action/scope authorization matrix, including aggregate-only, historical and audit access, delegation expiry and exports.
- [ ] Prove installability and dependency direction for co-located, native remote and third-party configurations without activating duplicate HR authority.
- [ ] Publish minimum workforce and employee-command contracts with scope, versioning, idempotency, errors and freshness; prove native integration against them.
- [ ] Document verified HR2000 capability evidence and deliver only supported operations; leave unsupported capabilities explicitly disabled.
- [ ] Prove delegated employee authority, narrow scheduler/service authority, denial parity and safe retry/reconciliation under outages.

### Integrate and hand off

- [ ] Attach tests, migration implications, validation results and remaining limitations; obtain independent review.
- [ ] Update this workstream checklist and report the achieved milestone to the epic coordinator. Do not mark an epic milestone complete from isolated unit tests alone.

## Acceptance and Handoff

Supply a contract version, capability matrix with evidence, authorization matrix, dependency/installability proof and cross-repository release order. Consumer fixtures are useful development aids but not vendor conformance proof. Full denial/outage scenarios belong to [0007](0007-people-verification-security-and-rollout.md).
