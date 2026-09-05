# 0006-c JD and KPI source map

**Status:** Source inventory mapped; business-source discovery remains open.
**Last Updated:** 2026-09-06 (Asia/Kuala_Lumpur).
**Agents:** desktop-sol/GPT-5.
**Sources:** [0006 People data migration and workbook parity](0006-people-data-migration-and-workbook-parity.md); [0009 Job Descriptions and Performance](0009-people-job-descriptions-and-performance.md); [0006-a workbook sheet map](0006-a-workbook-sheet-map.md); [job-description and KPI contract](../contracts/job-description-and-kpi.md); People issue #199; `SBTG_Skill_Management_System.xlsx` at SHA-256 `f6e4830398f4e52053d3df657bbdd6563acfb789467d469ec0b20907861e7e7a`.

## Problem Essence

No supplied source contains a complete, versioned history of job descriptions and KPI performance. Treating workbook phrases, filenames or employee names as canonical identities would fabricate definitions, assignments or approvals and make a repeat import unsafe.

## Desired Outcome

Migration discovery can identify every JD and KPI record by source installation, record and version, preserve known history, quarantine unknown meaning, and replay the same input without duplicates or downstream skill, promotion, compensation or payroll effects.

## Evidence Boundary

This map records only sources that can be inspected in the repository and marks every other business source unknown. It does not assert that a production installation contains rows, that HR2000 or another provider exposes JD/KPI history, or that a document was approved because it exists.

The inspected workbook contains 18 sheets but no dedicated JD document, JD revision, position-version, KPI-definition, target-assignment, performance-period or performance-review register. The only KPI-like fields are:

- `10 Training Requests`: `Request ID`, staff/group and position labels, `Expected Job / KPI Result`, HOD recommendation, HR review, approval status/approver/date and linked training ID.
- `13 Effectiveness Review`: `Review ID`, training/staff/skill IDs, stage/date/reviewer, pre/target/post levels, three workplace-impact ratings, evidence/result, outcome, HOD verification and closure fields.
- `01 Staff Master`: `Staff ID` and a position name, without a position-version identity.
- `16 Training Form Pack`: paper-capture presentation that directs official records into sheets 10, 12 and 13; a blank or completed form is not a second canonical record.

Those fields remain Training facts or candidate evidence. They do not establish a reusable KPI definition, approved personal target, KPI observation, performance review, JD publication or position history.

## Source Inventory

| Source | Evidence available now | Source-installation identity | Record/version identity | Authority and disposition |
|---|---|---|---|---|
| Authorized SBTG workbook | Repository workbook with verified hash and the structural fields listed above | Use the immutable input-manifest identity `xlsx` plus the full file SHA-256; a path or filename is not identity | Use sheet name plus stable business ID where present (`Request ID`, `Review ID`, `Staff ID`) and source row locator. The file hash is the only supplied version/cutoff; no JD/KPI version IDs exist | Authoritative only for source facts confirmed by HR/HOD. KPI-like text and ratings remain Training evidence candidates, not JD/KPI records |
| Native People Performance tables | `people_job_descriptions` and `people_kpi_records` schemas are present on current main | Deployment/tenant/company identity must come from an authorized installation inventory; none was supplied | Native IDs include JD `reference` + `version` and KPI record `id`/`kpi_key`; current schemas do not store external installation, record and version provenance | Target records, not proof of a legacy source. Existing deployed rows, schema versions and provenance are unknown until inventoried |
| Selected workforce provider | Provider seam carries provider-qualified workforce references; native position resolution currently uses company-scoped People reference entries | Required provider/deployment ID is unknown | Position, assignment and employee record/version IDs and source cutoffs are unknown | Candidate authority for workforce position/assignment context only after capability and history verification |
| HR2000 or other HR/performance system | No export, API response, schema inventory or capability proof was supplied | Unknown | Unknown | Quarantine all claimed JD/KPI history until the source owner supplies an authorized inventory and confirms authority |
| Old training portal or other legacy application | Migration plans mention prior installations, but no database/export inventory for JD/KPI records was supplied | Unknown | Unknown | Do not infer JD/KPI records from feature names or screenshots; inventory installation, schema, rows, evidence storage and history first |
| JD files, shared drives, paper forms and signed attachments | No authorized JD corpus, revision register or completed KPI form set was supplied | Unknown repository/cabinet/form-set identity | Unknown document/form ID, revision, effective date and approval identity | Preserve as evidence candidates. Quarantine publication/approval claims until matched to an authoritative register |

## Record-Family Map

| Required family | Actual source fields or current fact | Identity/version required for import | Target relationship | Missing-history disposition |
|---|---|---|---|---|
| JD document and structured content | No authorized JD source supplied | Source installation + document ID + revision/version; file hash alone identifies bytes, not business approval | Company-owned JD stable reference and immutable version | Quarantine the document; do not parse title or filename into a published JD |
| JD publication/revision history | No publication register supplied | Revision ID, lifecycle status, prepared/reviewed/published actors and recorded timestamps | JD version lifecycle and attribution | Keep status and approval unknown; attachment presence is not publication proof |
| Position versions | Workbook has mutable position names only; provider may expose qualified position references but history is unverified | Provider/deployment + stable position ID + source version/cutoff | Exact position-version association | Quarantine name-only matches; earlier structure is unavailable unless the provider proves it |
| Employee-position assignments | Workbook has Staff ID and current position label; effective-dated assignment history was not supplied | Provider/deployment + employee and assignment IDs + assignment version/effective interval | Applicable assignment/position context for personal targets and JD reads | Do not reconstruct transfers, acting roles or past incumbency from current labels |
| KPI definition/version | Workbook has free-text expected result and impact rating, not a catalogue | Source installation + KPI definition ID + definition version | Reusable definition with measure/source, unit, numerator/denominator where applicable, direction, rubric/calculation version, precision and interpretation | Keep definition unknown; do not deduplicate by title or turn Training text into a definition |
| Performance period | Training request/effectiveness dates and stages are not KPI periods | Period ID/version, timezone, cutoff, observation window, review deadline and lifecycle | Explicit period record or versioned assignment-period reference | Quarantine ambiguous dates and overlaps; do not invent a calendar or 30/60/90 KPI period |
| KPI target assignment/version | Workbook target levels are Skills/Training levels, not approved KPI targets | Assignment ID/version, exact KPI version, workforce subject/assignment, accountable owner, reviewer, period, target/range, approval and effective interval | Company-owned personal/team target assignment | Keep target and approval unknown; never treat a skill target level or expected-result phrase as a KPI target |
| Owners and reviewers | Workbook requestor, HOD, HR, approver and effectiveness reviewer fields belong to their Training workflows | Provider-qualified workforce/actor IDs plus role-at-action provenance | Metric steward, accountable subject, evidence contributor and reviewer remain distinct | Quarantine unresolved names and expired/ambiguous role mappings; names are not identity keys |
| Observation/result | Workbook impact ratings and evidence/result text are Training effectiveness facts | Observation ID/version, KPI assignment/version, measurement window, source ID/version, contributor, recorded time, typed value state and verification status | KPI observation linked to the approved target version | Do not auto-score text, average incompatible percentages, map missing to zero or copy a team result to people |
| Evidence reference | Workbook has evidence/result text and links may exist in external storage, but no authorized evidence inventory was supplied | Evidence-system identity, immutable object/version/checksum, permission classification and source record link | Permission-checked evidence reference; no copied sensitive payload | Missing/inaccessible evidence remains explicit; a link is not verification or approval |
| Approval/review outcome | Training approvals and HOD verification apply to Training records only | Approval/review ID/version, actor, authority, status, rationale and recorded/effective times | KPI target approval or performance review only when the source explicitly identifies that workflow | Never reuse Training approval as KPI approval; missing approval stays unknown/quarantined |
| Employee response/dispute | No source supplied | Response/dispute ID/version, related review version, actor, time, status and outcome | Separate response/dispute history | Do not infer acceptance from silence, publication or receipt |
| Correction/supersession | No JD/KPI correction ledger supplied | Correction ID/version, prior record/version, reason, actor, recorded/effective time and source cutoff | Append/supersede without rewriting the earlier version | Preserve both known versions; conflicting or unlinked changes remain quarantined |

## Field and Relationship Rules

- Record both business-effective dates and source-recorded timestamps. Preserve source timezone and cutoff; do not convert an unknown timezone silently.
- Resolve company, organisation unit, position, employee and assignment through provider-qualified stable identities. A current name, title, email address or row order is never a join key.
- Record the exact KPI definition and target versions used by each observation and review. Preserve target changes as new versions with reason, authority and effective transition; never recalculate historic results against a replacement target.
- Preserve unit, numerator, denominator, inclusion/exclusion, aggregation, cadence, direction of improvement, precision and rubric/calculation version when the source provides them. Missing, zero, zero denominator, not applicable, not yet due, unverified and final remain distinct.
- Keep team/department subjects distinct from employees. A team measure does not become every member's result without explicit attribution policy and source evidence.
- Preserve source lifecycle labels verbatim alongside a reviewed target mapping. Unknown, conflicting and unmapped statuses remain quarantined rather than coerced.
- Record retention and confidentiality classification from the source owner. Until confirmed, restrict access and do not promise deletion or indefinite retention.
- Reconcile JD-to-position-version, KPI-definition-to-assignment, assignment-to-period, observation-to-evidence and review-to-included-observation links before release.

## Provenance, Deduplication and Replay

The idempotency key is the tuple of source-installation identity, record family, source record ID and source version ID. Where the source lacks a stable record or version ID, the row cannot enter a finalized canonical history. Its manifest hash and locator support quarantine and investigation but do not manufacture business identity.

Matching title, filename, employee name, position name, KPI label, target text or evidence URL never proves equality. A reviewed crosswalk may link two source identities to one canonical identity while retaining both provenance chains. Corrections and superseding versions remain separate records; rerunning an unchanged manifest must produce no new canonical or quarantine entries.

Before apply, reconcile counts by record family, identity collisions, relationships, version sequences, effective intervals, source statuses and evidence availability. A repeated dry run must return the same proposed creates, links and quarantine reasons. A repeated successful apply must create zero duplicates and must not turn a later correction into an in-place rewrite.

## Quarantine and Release

Quarantine a row or artifact when its source installation, stable record ID, version, tenant/company, workforce subject, position/assignment version, KPI definition, target/period, unit/denominator, approval, evidence permission or correction relationship is missing or ambiguous. Record a reason code, input manifest/hash, source locator and discovered facts without exposing sensitive payloads.

Release requires source-owner confirmation and an explicit mapping to canonical identities and versions. Unknown approval history remains unknown even if the content appears reasonable. Missing legacy versions remain unavailable in historical views; do not backfill dates, approvers, targets or reporting lines from current state.

## Downstream Firewall

Importing or releasing JD/KPI data creates no skill assessment, proficiency change, gap closure, training completion, promotion eligibility, appointment, compensation decision or payroll command. Performance evidence can be consumed later only through the receiving module's explicit published policy, authorization and governed reconciliation. A correction flags affected downstream decisions for review; it never silently rewrites them.

## JP-A12 Acceptance

- [x] Every JD/KPI family named by the 0006 amendment has a known source or an explicit `unknown` disposition. {desktop-sol/GPT-5}
- [x] The map requires source-installation, record and version identity and deduplicates by provenance rather than titles or names. {desktop-sol/GPT-5}
- [x] Missing approvals, targets, history, evidence permissions and ambiguous identities have explicit quarantine rules. {desktop-sol/GPT-5}
- [x] Replay preserves corrections, creates no duplicate canonical records and never fabricates missing versions. {desktop-sol/GPT-5}
- [x] The downstream firewall forbids automatic skill, training, progression, compensation and payroll effects. {desktop-sol/GPT-5}
- [ ] Obtain authorized inventories and source-owner sign-off for every source currently marked unknown.
- [ ] Execute dry-run and repeat-import controls against supplied non-production extracts; this document does not claim runtime migration proof.
