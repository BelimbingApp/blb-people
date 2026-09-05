# Employee standing and training passport

**Status:** proposed read contract for business confirmation; documentation only.
**Delivery:** [People #150](https://github.com/BelimbingApp/blb-people/issues/150).
**Sources:** [0001 authority and audience policy](../plans/0001-people-architecture-and-provider-boundaries.md), [0005 employee experience](../plans/0005-people-employee-and-management-experience.md), and [0008 register/passport reconciliation, issue 0014](../plans/0008-people-existing-work-and-backlog-reconciliation.md).

This contract makes the employee's own published standing and history readable
over canonical People records, linked to progression. It proposes neither a new
ledger nor blanket employee permissions. The audience policy remains subject to
business confirmation; the permission boundaries confirmed in plan 0001 apply.
It does not certify a delivered portal, historical reconstruction or provider
capability.

## Employee read scope

An employee may receive the following only with explicit self-record permission
and a verified employee binding within the requested tenant/company:

| Record | Permitted self-view | Boundary |
|---|---|---|
| Published skill standing | Own published standing, applicable requirement version, assessment validity and the meaning of any gap | Training completion is not competence; unpublished material and colleagues' assessments are excluded |
| Assessment outcomes | Own permitted assessment outcomes and supporting evidence links | Do not include others' private notes or confidential deliberations; evidence/document access is checked separately |
| Learning and participation | Own planned training, nominations, participation, completed training, certificates and follow-up | Link to permitted participation, evaluations and effectiveness evidence; a plan approval, event completion and competence verification are different facts |
| Progression | Published applicable policy and employee-specific explanation | Do not expose the entire editable matrix or confidential deliberations; an explanation is not a compensation promise |
| Directory context | Permitted directory structure to orient the employee | Directory access does not grant individual detail, aggregates, departmental planning or structural edits |

Payroll, bank/tax data, colleagues' assessments, private notes and departmental
planning are outside this self-view grant. Any separately authorized payslip or
compensation result belongs to its verified workflow and storage design; it is
not added to a training passport by implication. Approval, export and changes to
source records also require separate permissions. Chart position and choosing a
different view never widen authority.

## Logical read envelope

These are required meanings, not new route names, PHP interfaces or capability
identifiers. Implementations must reuse the owning workforce and module contracts.

| Element | Required meaning |
|---|---|
| Actor and subject | Authenticated actor plus authoritative employee binding; explicit tenant/company and stable workforce subject identity. Provider/source, resource type and external identifier remain distinct from local numeric IDs, email and display name |
| Time selection | Effective/as-of date and relevant data/version cutoff; distinguish effective dates from recorded change timestamps so late corrections are intelligible |
| Authorized scope | Requested and permitted self-record/evidence scope. Resolve each relationship and historical period through current authorization before returning data |
| Standing and learning records | Permitted canonical records, approved requirement/plan versions, assessment validity, participation/certificate history and employee-specific progression explanation when supported |
| Freshness and completeness | Source freshness and cutoff where consequential, plus truthful incomplete, stale, unavailable or empty state. Do not convert unavailable evidence to a zero or a competence result |
| Provenance and navigation | Source identity, retained versions and evidence provenance with authorized canonical drill-through targets. Links never substitute for document authorization |
| Report context | Scope, filters, retained versions/cutoff and generation time for a permitted snapshot or printable passport, preserving the provenance needed to distinguish later corrections |

People Skills owns skill meanings, requirements and assessments; People Training
owns training and participation; People Progression owns applicable policy and
eligibility explanations. The selected authoritative installation supplies each
fact. Presentation composes those meanings and must not calculate a competing
standing or silently use a different authority during an outage.

The current Progression starter supplies a scoped published policy identity and
version only. That result alone is not an eligibility explanation, approval or
compensation decision; dependent explanation work remains separately governed.

## Refusal and incomplete results

Fail closed for missing or ambiguous employee binding or tenant/company
attribution, mismatched subject scope, missing permission, or a historical
subject/period outside current authorization. Authentication or SSO alone is
insufficient. Deactivation retains historical attribution; it does not establish
a continued login or read grant.

An undeclared capability is **unsupported** at execution, not merely absent
from navigation. Distinguish unsupported, unavailable, unauthorized, stale and
empty results. Do not label a provider outage as no records, infer no applicable
policy from an unavailable source, or invent relationships or historical versions
that the authority cannot supply.

An authorized empty published-policy result is not an eligibility decision.
A stale read may be displayed read-only when permitted and labelled; freshness
rules for consequential decisions require the owning policy. Restricted-state
guidance must not reveal a resource's existence where that disclosure is denied.
Return no unauthorized records, counts, IDs, attachment names or private fields,
including in cached responses and error messages.

## History, evidence and revocation

Current authorization governs historical reads: an old as-of date cannot revive
an expired grant. Transfers and multiple employment relationships do not expose
unrelated-company history. Preserve effective/recorded time and retained approved
versions; show missing historical sources honestly rather than reconstructing
them from today's state.

The canonical digital history remains singular. A printed passport is a
discussion/report artifact with scope, generation time and cutoff; it does not
become a second editable assessment ledger. Preserve earlier report evidence
under approved retention and distinguish later corrections.

Evidence retrieval and export recheck permission. Revocation invalidates or
bounds cached, queued and downloaded-link access; asynchronous export checks
occur at generation and retrieval. Audit sensitive access with actor, scope and
operation without copying contents to logs. Retention and deletion policy and
the separate-host administrator trust boundary remain governed by plan 0001.

## Employee experience and confirmation

Compose My learning and My skills and progression over these records; these are
proposed surface names, not verified routes. Keep trained, competent, unassessed,
unverified, expired and below requirement distinct, together with unavailable,
stale and incomplete states. Labels explain meaning; color is supplementary.
Provide a readable progression explanation rather than an editable matrix.

Use standard Belimbing components and current DESIGN.md when implementing.
Keep common search/filters compact, with a labelled More filters control/count,
removable active filters and compact saved views where those controls apply.
Preserve keyboard/focus access and equivalent narrow-screen navigation through
canonical records. Printed forms and passports remain usable for discussion and
paper capture. Historical mode stays visibly read-only; new operational actions
must explicitly return to current context and the owning authorized workflow.

Employee, HOD and HR scenarios must validate the final presentation and failure
recovery after approval. Business confirmation still determines audience grants,
historical availability, freshness rules and permitted evidence. This document
implements no UI, command, policy publication or new authorization engine.
