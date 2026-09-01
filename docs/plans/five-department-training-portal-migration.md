# Five-department training-portal migration and cutover

**Status:** Proposed — discovery and approvals required before import  
**Owner:** HR system governor  
**Scope:** Production, Engineering, QAC/R&D, Planning, and IT  
**Related:** [#38](https://github.com/BelimbingApp/blb-people/issues/38), provider/migration mechanics [#20](https://github.com/BelimbingApp/blb-people/issues/20) and [#31](https://github.com/BelimbingApp/blb-people/issues/31)

## Decision and non-negotiables

This migration replaces the existing training portal as the system of record for
the five named departments. It does not start with an import. HR must first
approve a signed inventory, a mapping workbook, and a dry-run reconciliation.
Until the cutover decision, the legacy portal remains authoritative for its
existing workflows. After the agreed freeze, exactly one system may accept
writes for each workflow.

No person, training history, evidence, certificate, assessment, or action is
silently dropped. A row that cannot be mapped is quarantined with its source
provenance, reason, and named remediation owner. The importer may be rerun with
the same source package without creating duplicate target records.

## Delivery gates

| Gate | Entry criteria | Evidence and decision owner |
| --- | --- | --- |
| 0 — discover | HR appoints data owners and approves a read-only extraction window. | Signed source inventory and classification register; HR governor. |
| 1 — map | Inventory is complete; target features are available or explicitly deferred. | Versioned field/code mapping, source-of-truth register, retention decision; HR plus data owners. |
| 2 — dry run | Mapping and approved encrypted transfer path exist. | Import manifest, validation/reconciliation report, quarantine register, rollback rehearsal; HR and People technical owner. |
| 3 — pilot | Dry run has no unresolved critical reconciliation exceptions. | Departmental end-to-end evidence and named HOD sign-off for all five departments. |
| 4 — cut over | Pilot is accepted and support/training communications are ready. | Freeze record, cutover checklist, go/no-go record; HR governor and every named HOD. |
| 5 — close | Reconciliation and monitoring pass during the agreed hypercare period. | Final reconciliation, legacy disposition record, acceptance metrics; HR and records/privacy owner. |

An unmet gate stops progression; it is not waived by importing a subset.

## Source inventory and custody

HR owns a single inventory register. It records every source system, workbook,
departmental spreadsheet, shared drive, and paper/archive batch that might hold
in-scope records. Each row must contain:

- source name and immutable export identifier (file hash, extract run ID, or
  database snapshot ID), owner/custodian, department, format, record count and
  date range;
- data classification, legal/retention rule, access group, and whether special
  categories or evidence files are present;
- data-quality findings (missing identity, duplicate identity, invalid dates,
  unsupported code, unreadable evidence), remediation owner, and due date; and
- approved extraction method, encrypted transfer location, checksum, importer
  run ID, and deletion/retention treatment of the staged copy.

Only read-only exports produced during the approved extraction window enter the
import manifest. HR and the relevant HOD sign the inventory for their data;
the records/privacy owner signs the classification and retention treatment.

## Target mapping and authoritative ownership

The versioned mapping workbook has one row per source field/code. It specifies
source value, transformation, target module/entity/field, validation rule,
defaulting rule, source provenance field, rejection reason, and approver.

| Source record family | Target business record | Authoritative rule at and after cutover |
| --- | --- | --- |
| Person and employment identity | People employee identity and organization history | The approved People identity mapping is authoritative; unresolved or ambiguous matches quarantine. No fuzzy merge is accepted automatically. |
| Department, position tier, skill requirement | Department/profile requirement and effective-dated requirement version | HR governs the catalog; each HOD approves its department profiles, assessors, gaps, and actions. |
| Course, event, request, approval, nomination, attendance, test | Training catalog/event and the corresponding workflow history | The target workflow is authoritative after its specific freeze; legacy history remains immutable provenance. |
| Certificate and supporting evidence | Evidence/certificate record linked to the imported history | Preserve source identifier, checksum, original filename/type, capture date, retention class, and access policy. Counts alone never reconcile this family. |
| Evaluation, effectiveness review, assessment, score, action | Evaluation/effectiveness, assessment, score history, and owned improvement action | Preserve effective date, assessor/reviewer, scale/version, outcome, and source link. Never replace a historical score with a current score. |

The mapping workbook explicitly resolves overlap before import. Where the portal,
workbook, and departmental files disagree, the approved source-of-truth register
names one record or field winner and marks every non-winning value as retained
source evidence, a correction candidate, or a duplicate. Natural-language names
and mutable job titles never act as deduplication keys; identity resolution uses
the approved employee-identity crosswalk and an auditable decision log.

## Privacy, security, and transfer controls

HR may authorize extraction only after the privacy/records owner approves the
purpose, minimum data set, retention, access groups, and transfer route.

- Exports are read-only, encrypted in transit and at rest, checksum-verified,
  access-logged, and limited to the appointed migration team.
- Evidence and certificates retain their original integrity hash; malware scan
  and content-type validation occur before a file is available to users.
- Staging data is separated from production tenant data, is not copied into
  tickets or chat, and is destroyed or retained only under the approved record.
- The import audit trail records source manifest ID, mapping version, operator,
  timestamps, target IDs, and result for every record. It does not log the
  source payload unnecessarily.

## Dry run, reconciliation, and restart protocol

Every run receives an immutable manifest ID and mapping version. Target records
store the manifest ID plus the source system and stable source record ID. The
idempotency key is `(source system, stable source record ID, record family)`;
re-running a completed package resolves the same target record instead of
creating another one. The run ledger has `prepared`, `validated`, `importing`,
`reconciled`, `approved`, `rolled_back`, and `quarantined` states.

Validation occurs before writes for required identifiers, tenant/department
membership, codes, dates, referential integrity, duplicate identity, evidence
integrity, and workflow chronology. Rejects are written to a quarantine register
with no partial target workflow state. A remediation owner corrects the source
or approved mapping, then submits a new manifest; imported historical source
data is never edited in place to conceal a discrepancy.

The reconciliation report compares, by department and record family:

- input, accepted, updated, duplicate-resolved, rejected, and quarantined
  counts, with every count linkable to source IDs;
- identity crosswalk completeness and duplicate decisions;
- event/attendance/test chronology, certificate/evidence checksums and links,
  historical assessment/score coverage, and open improvement-action ownership;
- target records missing provenance, source records missing a terminal result,
  and records that differ from their approved source-of-truth value.

The dry run passes only when all critical exceptions are resolved or explicitly
accepted by HR, the relevant HOD, and the privacy/records owner where relevant.
The approval records the accepted exception, business impact, owner, expiry,
and follow-up; it never converts a rejected record into a silent omission.

## Rollback boundary

Before the irreversible boundary, every imported record is attributable to the
manifest and can be removed or restored without touching post-import user work.
The rollback rehearsal proves this using a production-like tenant and records
the resulting reconciliation report. The irreversible boundary is the approved
moment when pilot/cutover users may create new authoritative target workflow
records. After that point, rollback means a controlled recovery plan that
preserves target writes and reconciles them; it is not a destructive re-import.

The go/no-go record identifies the technical executor, recovery owner, decision
authority, maximum rollback window, communications channel, and the last safe
backup/manifest. No cutover begins without a tested restore path.

## Five-department pilot and cutover

Configure the workbook's Production, Engineering, QAC/R&D, Planning, and IT
profiles as drafts first. HR and each HOD validate the name, requirements,
assessors, mandatory training, gaps, action ownership, and profile version
before publication. Each department completes one end-to-end pilot path:

1. approve a department requirement and assign it to an identified employee;
2. request/approve or nominate training, record attendance, test result, and
   certificate/evidence;
3. collect an evaluation and effectiveness review;
4. update the assessment/score and create or close the accountable action; and
5. reconcile the complete path against its legacy source IDs and evidence.

Cutover uses a published runbook with a freeze notice, final delta-export time,
user and support communications, service-owner coverage, final import and
reconciliation, HR/HOD go-no-go signatures, and a clearly communicated new
system-of-record time. Each workflow's legacy writer is disabled at its freeze;
the legacy portal becomes read-only only after final reconciliation is signed.

During hypercare, HR reviews daily exception volume, overdue remediation,
support incidents, failed imports, provenance completeness, pilot workflow
completion, and user-access errors. The legacy portal is retained read-only or
retired only under the approved legal/records policy. It cannot remain an
authoritative writer after the cutover time.

## RACI and required approvals

| Activity | HR governor | HOD (each department) | People technical owner | Privacy/records owner | Migration operator | Service desk |
| --- | --- | --- | --- | --- | --- | --- |
| Data inventory, classification, retention | A | C | C | A | R | I |
| Identity and source-of-truth mapping | A | C | R | C | R | I |
| Department profile, assessor, gap, action validation | A | A/R | C | I | C | I |
| Dry run, reconciliation, remediation | A | C | A | C | R | I |
| Pilot acceptance and cutover go/no-go | A | A | R | C | R | C |
| Training, communications, and hypercare | A | C | C | I | C | R |
| Legacy archive/retirement | A | I | C | A/R | C | I |

`A` means accountable, `R` responsible, `C` consulted, and `I` informed. The
same individual may not approve their own unresolved reconciliation exception
without HR's written conflict-of-interest decision.

## Dependencies, measurable acceptance, and next action

This plan deliberately does not invent connector mechanics. The provider and
identity/migration capabilities in [#20](https://github.com/BelimbingApp/blb-people/issues/20)
and [#31](https://github.com/BelimbingApp/blb-people/issues/31) must supply the
approved tenant-safe import/provenance seams before a production run. Where a
target record family is not implemented, it stays in the mapping workbook as a
gated dependency rather than being imported into an ad hoc substitute.

The first executable action is for HR to appoint the five HOD data owners and
create the Gate 0 inventory register. The plan is accepted when the signed
inventory, approved mapping/source-of-truth register, dry-run and rollback
evidence, five pilot sign-offs, cutover record, and post-cutover reconciliation
are attached or linked from the migration decision record.
