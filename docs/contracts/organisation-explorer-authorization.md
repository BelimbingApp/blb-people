# Organisation explorer authorization

**Status:** proposed for business confirmation — 2026-09-05.
**Governing source:** [plan 0001, organisation structure, history and authorization contract](../plans/0001-people-architecture-and-provider-boundaries.md#organisation-structure-history-and-authorization-contract).
**Delivery issue:** [People #127](https://github.com/BelimbingApp/blb-people/issues/127).

This document makes the plan's proposed audience scopes explicit. It does not
grant application permissions or certify enforcement. The owner has confirmed
separate permissions for organisation visibility, skill/training inspection,
planning/approval actions and audit exports. The audience allocations remain
subject to business confirmation.

## Reading the matrix

**Separately granted** means the actor needs an explicit resource/action/scope
permission before the operation is allowed; the audience name alone grants
nothing. Scope descriptions below preserve the plan's intended access, not
default role entitlements. **Denied** means the operation is outside that
audience's stated mandate. No cell is **Granted** by this proposed document.
An actor acting under another independently granted mandate is evaluated under
that mandate and its scope.

Every cell also requires the platform actor, tenant/company boundary and
record-level policy. Missing or ambiguous attribution fails closed. A permission
to see a resource does not imply permission to change or export it.

## Resource, action and scope matrix

| Resource | Action | CEO/executive | HOD/delegate | Employee | HR | Auditor |
|---|---|---|---|---|---|---|
| Structure node | Browse/search structure | Separately granted — approved company-wide structure | Separately granted — assigned departments | Separately granted — permitted directory structure | Separately granted — assigned governance scope | Separately granted — approved engagement scope and period |
| Aggregate indicator | Read indicators | Separately granted — selected company indicators | Separately granted — permitted population within assigned departments | Separately granted — no aggregate entitlement follows directory access | Separately granted — assigned governance population | Separately granted — approved engagement population and period |
| Individual detail | Inspect employee skill/training detail | Separately granted — employee drill-down where permitted | Separately granted — permitted employee detail within assigned departments | Separately granted — own published standing/training records; colleagues require separate permission | Separately granted — assigned governance capabilities and records | Separately granted — approved engagement records and period |
| Evidence/document | Retrieve evidence or documents | Separately granted — personal evidence and compensation details remain restricted | Separately granted — confidential notes and out-of-scope history remain restricted | Separately granted — private notes, payroll and colleagues' assessments are not implied by own standing | Separately granted — payroll/compensation and unrelated-company access remain separate | Separately granted — approved evidence classes with expiring access |
| Proposal | Propose a business plan/action | Separately granted — owning workflow and scope | Separately granted — permitted proposal actions within assigned departments | Separately granted — departmental planning is separate from self-view | Separately granted — assigned governance workflow and scope | Denied — read-only engagement |
| Approval | Approve a business plan/action | Separately granted — approvals are separate from executive visibility | Separately granted — financial/HR approvals are separate from proposal authority | Separately granted — no approval authority follows self-view | Separately granted — assigned approval capability; payroll/compensation remains separate | Denied — read-only engagement |
| Structural edit | Maintain organisation structure | Separately granted — structural edits are separate from visibility | Separately granted — department visibility is not structural maintenance | Separately granted — directory access is not structural maintenance | Separately granted — assigned structural governance scope | Denied — no operational edits |
| Export | Generate/retrieve an export | Separately granted — approved scope and evidence classes | Separately granted — permitted department records; no unrelated-company access | Separately granted — no export entitlement follows own-record access | Separately granted — assigned scope; payroll/compensation remains separate | Separately granted — approved engagement scope, period and evidence classes; unrestricted personnel/payroll export denied |

The plan does not assign every operation to a default audience. Cells retaining
“Separately granted” for those combinations deliberately leave the business
decision open. They must not be implemented as blanket permissions. The
auditor's read-only mandate expressly excludes proposals, approvals and edits.

## Structure is context, not authority

Chart position is never authority. Legal-company scope, structural membership
and managerial reporting are distinct relationships. Being above someone in a
chart does not grant access to their records. View selection and purpose labels
are context, never authority.

Planning from a node calls the owning business workflow and revalidates scope.
Chart navigation cannot grant write access to HR master data. Enforce policies
before returning nodes, search results, aggregates, evidence links or exports,
and again on authoritative commands. Authentication and SSO alone are not
authorization; co-located and remote paths require equivalent denial behavior.

## Aggregate-only access and disclosure

Aggregate-only reporting requires an explicit aggregate permission. Record-level
access is neither a prerequisite to assume nor permission to expose unrestricted
aggregates. HR/security must define permitted populations, denominators and
small-cohort/disclosure controls.

Unauthorized records must not leak through totals, layout placeholders, caches,
facets, IDs or downloadable files. Distinguish visible incomplete data from zero
without revealing that hidden records exist.

## Historical access

Historical queries carry an effective/as-of date and the relevant data/version
cutoff for late corrections. Preserve effective dates separately from recorded
change timestamps, approved requirement/plan versions and evidence provenance.

Current authorization governs every request. An old as-of date cannot revive an
expired HOD or auditor grant. Historical subjects and periods require explicit
access. Transfers, delegations, historical organisation changes and multiple
employment relationships do not automatically expose unrelated or historical
records to a current manager.

Snapshot/report manifests retain scope, filters, versions/cutoff, generation
time and provenance. Retrieval and export still check current permission.
Preserve prior report evidence under approved retention while distinguishing
later corrections from the original result.

## Revocation, exports and audit

Revocation must invalidate or bound cached, queued and downloaded-link access.
Recheck authorization when generating and retrieving asynchronous exports.
Sensitive access/export audit records carry actor, scope and operation without
copying sensitive contents into logs.

Separate-host administrator trust limits from plan 0001 still apply. Role
controls do not hide locally stored data from the machine/database administrator.

## Confirmation and implementation evidence

Business confirmation must resolve the intended audience grants and their
scopes, aggregate disclosure policy, historical periods and expiry bounds.
Implementation must then demonstrate the corresponding denial, revocation,
historical and export behaviors. This document supplies no new capability
identifiers, approval hierarchy or default grants, and no runtime tests were
executed for this documentation change.
