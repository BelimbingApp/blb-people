# 0007-people-verification-security-and-rollout.md

**Status:** Proposed; implementation remains halted pending explicit owner approval.
**Last Updated:** 2026-09-06 (Asia/Kuala_Lumpur).
**Agents:** codex/astra-medium.
**Sources:** [Overall epic roadmap](0000-people-epic-roadmap.md); epic invariants; all workstream public contracts.

Read the epic's business context, shared decisions and halt instruction before this workstream. This numbered plan is a proposed assignment boundary, not authorization to start implementation. No agent is assigned by this document. Plan numbers are not the existing GitHub issue sequence.

## Problem Essence

Individually passing features can still fail the employee-to-HR workflow or expose sensitive data across deployment boundaries.

## Desired Outcome

Independent, risk-based verification proves integrated workflows, scope isolation, recovery and business readiness before rollout.

## Ownership and Dependencies

Own the integrated acceptance suite and release evidence, not a second implementation of business policy. Draft adversarial scenarios early; execute against real implementations after workstream integration. Feature agents retain responsibility for their own tests. Coordinate migration/recovery with 0006 and platform/provider tests with 0001.

## Design Decisions

Prefer a small set of real end-to-end vertical slices plus boundary-specific tests over broad mocked success demos. Independent review supplements rather than replaces feature-owner validation. Compliance evidence supports organizational assessment; software tests do not certify an organization to ISO.

## Acceptance Scenarios and Proof
Use contract tests, authorization tests, database/migration tests, provider conformance tests and human workflow verification. A passing UI demo does not prove backend isolation. Tests should exercise actual boundaries rather than mock away every provider or tenant check.

| Scenario | Required observable result |
|---|---|
| Employee changes a requested employee/company identifier | Backend denies unauthorized scope in both local and remote modes; no existence leak. |
| General administrator queries restricted payroll | Access fails under the chosen trust model; no replicated payroll data or broad credential offers a side route. |
| Employee submits twice after a timeout | One authoritative request; portal resolves unknown outcome without duplicate application or award. |
| HR backend is unavailable | Honest unavailable/stale status; no silent local authority takeover or false success. |
| Provider omits a record during partial sync | Employee is not spuriously terminated; explicit deletion/deactivation semantics govern. |
| Provider is replaced or employee is rehired | Reviewed identity mapping preserves history and distinguishes employment periods. |
| Critical certification expires | Coverage/eligibility reflects validity; historical assessment remains; salary is not silently reduced. |
| High weighted score but mandatory skill missing | Explanation identifies the unmet gate; aggregate score cannot approve the path. |
| Course attended and test passed | Training facts update, but competency changes only through authorized verified assessment. |
| Requirement policy changes during training | Current and historical evaluations use their declared versions; transition policy explains treatment. |
| Assessment is appealed and superseded | Original, correction, reviewer and dependent eligibility decisions remain traceable. |
| HOD proposes an incentive | Publication/award follows confirmed authority; employees see only approved applicable policy. |
| Same reward decision is delivered twice | Payroll handoff is idempotent and acknowledgement is traceable; no duplicate payment instruction. |
| Employee opens another person's passport/export | Denied; totals, files and notifications also preserve scope. |
| Workbook imported twice | Stable source IDs prevent duplicates; quarantine and reconciliation results are reproducible. |
| Paper form is entered after digital capture | Matching/provenance prevents duplicate requests/reviews; signatures remain evidence, not implicit authentication. |
| Cutover fails after target writes | Recovery retains new writes and audit history rather than restoring an obsolete snapshot blindly. |

## Training Planning Acceptance Additions

- Approved plan revision remains readable after amendment; execution records cite the applicable approved item/revision.
- A plan can execute valid in-house or external programmes without financial-budget tracking enabled.
- Unknown/not-budgeted amounts remain distinct from zero; enablement never invents historical amounts or approval.
- Plan approval does not silently authorize purchasing or an overrun; configured company authority governs each decision.
- Cancelled/rescheduled/partially delivered activities retain reasons and reconcile planned versus actual delivery.
- Unplanned urgent training follows explicit exception approval/reconciliation and cannot manufacture a retrospective approval timestamp.
- Programme delivery, employee evaluation, workplace effectiveness and skill verification have separate evidence/status.
- An auditor can trace an applicable competence need through the approved plan, execution and effectiveness evidence without gaining unrestricted staff access.


## Organisation Explorer and Authorization Acceptance

The owner-approved explorer direction adds the following cross-workstream proof; 0001 owns policy/history contracts and 0005 owns the record-navigation experience.

- A CEO can drill through the expressly granted company scope; executive title alone does not bypass denied evidence/payroll or other tenants.
- A HOD can propose training for their authorized department but cannot approve it without the separate required grant, edit HR structure through the chart or reach another department via a forged node/search/filter ID.
- Employee, HR and auditor views use the same backend policy as direct record URLs/APIs, exports and evidence downloads; hiding controls is not the authorization test.
- An aggregate-only permission returns the approved summary without individual detail; restricted totals, small cohorts and chart/search layout do not reveal unauthorized facts. Denominators and partial authorized coverage remain truthful.
- Historical queries reconstruct the intended structure, employee assignment and applicable record versions; late corrections are distinguishable. Old dates do not restore revoked HOD/auditor access.
- Delegation/engagement expiry or revocation denies queued export generation and subsequent retrieval as policy requires; caches and signed links respect the defined revocation boundary.
- Vacant positions, acting assignments, transfers and multiple relationships preserve identity and avoid cycle/unbounded traversal and duplicate headcount.
- Chart, accessible tree/table and scoped exports agree on the same authorized population and indicators. Training completion never implies verified competence.
- An authorized auditor can trace a gap through approved plan and execution to evidence as of the defined cutoff; absent historical data is explicitly incomplete, not reconstructed from today's state and presented as fact.
- HOD action from a historical chart requires explicit current-context validation and cannot silently create a request for an obsolete assignment.

## Job Description and Performance Acceptance Scenarios

Canonical business requirements are in [0009](0009-people-job-descriptions-and-performance.md). The following identifiers trace to draft issue gaps in 0008; they are scenario IDs, not test results or GitHub numbers.

| ID | Scenario | Required observable result |
|---|---|---|
| JP-A01 | Position/JD is revised or future-dated | Existing assignment/history resolves its exact applicable version; future content does not replace today's JD; all required structured JD fields and competency links survive. |
| JP-A02 | Transfer, vacant position, acting or concurrent assignment | Stable position identities and effective intervals yield unambiguous applicable descriptions; no shared JD is silently edited for one employee. |
| JP-A03 | JD authority says “approve expenditure” | No application, financial or payroll permission is granted by that text or by chart position. |
| JP-A04 | KPI target changes during a period | Original target and approval remain; approved change has explicit effective treatment and reason; historic performance is not recalculated against a silently replaced target. |
| JP-A05 | Missing/zero/zero-denominator and lower-is-better/range measures | Distinct states and declared calculation/rubric semantics; no fabricated score, incompatible percentage average or arbitrary executable formula. |
| JP-A06 | Team KPI reused or employee moves mid-period | Personal attribution and any prorating/splitting follow approved policy; no duplicate headcount or automatic team-to-person score copy. |
| JP-A07 | Evidence corrected after final review or employee disputes result | Original evidence/review and released rationale remain traceable; a versioned correction/response preserves who changed what and why. |
| JP-A08 | High KPI, low competence; or low KPI, valid certification | Records remain independent; no automatic skill certification/revocation, promotion, salary adjustment or payroll write. |
| JP-A09 | Approved progression policy includes performance | Only policy-eligible versioned review evidence is consumed; missing/disputed periods use the published rule; corrections flag governed reevaluation without rewriting awards. |
| JP-A10 | Employee/HOD/HR/auditor access via chart, API, link or export | Own/released and scoped records match the approved audience matrix; confidential notes/evidence and other employees stay denied; aggregate access does not confer personal access. |
| JP-A11 | Historical report after transfer/revocation or late correction | Current authorization governs historical scope; report identifies effective date, cutoff and JD/target/review versions, with old and corrected views distinguishable. |
| JP-A12 | Legacy JD/KPI data imported twice with missing approvals | Idempotent provenance and reconciled relationships; missing history remains explicit; no fabricated approvals, zero values or downstream pay/skill changes. |
| JP-A13 | KPI linked to training effectiveness | Baseline/window/source and permissions are preserved; a business outcome is not automatically proof that training caused improvement or that competence changed. |

## Phases

### Validate and agree the boundary

- [ ] Refresh relevant existing implementation and record reusable behavior, gaps and affected contracts.
- [ ] Resolve this workstream's open policy/interface decisions with the epic coordinator before dependent implementation.

### Implement after explicit owner resumption

- [ ] Execute JP-A01–JP-A13 with 0009 and affected owners, covering version/history, measurement semantics, visibility, evidence, migration and separation from competence/progression/pay.
- [ ] Agree measurable integrated acceptance scenarios with HR/HOD and standards/control traceability with the QMS owner.
- [ ] Verify organisation drill-down, purpose-specific resource/action/scope permissions, historical reconstruction, delegation revocation and export/aggregate non-disclosure across local and remote boundaries.
- [ ] Verify a real workforce-to-training-to-reassessment-to-progression slice, including failures and authorization denials.
- [ ] Verify approved-plan amendments, optional budgeting, external/internal execution and all workbook/form outputs.
- [ ] Prove infrastructure separation, restore, cutover recovery and rollout acceptance with authorized operators.

### Integrate and hand off

- [ ] Attach tests, migration implications, validation results and remaining limitations; obtain independent review.
- [ ] Update this workstream checklist and report the achieved milestone to the epic coordinator. Do not mark an epic milestone complete from isolated unit tests alone.

## Acceptance and Handoff

Attach reproducible evidence, environment/revision details, failures and limitations. Every release decision identifies unmet requirements and approved exclusions; closed issues or green isolated tests do not replace this evidence.
