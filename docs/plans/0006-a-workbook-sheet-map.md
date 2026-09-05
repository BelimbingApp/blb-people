# 0006-a Workbook sheet-to-record map

**Status:** source analysis and proposed migration mapping — 2026-09-05.
**Governing plan:** [0006 People data migration and workbook parity](0006-people-data-migration-and-workbook-parity.md).
**Issue:** [People #126](https://github.com/BelimbingApp/blb-people/issues/126).
**Source:** SBTG_Skill_Management_System.xlsx, SHA-256
f6e4830398f4e52053d3df657bbdd6563acfb789467d469ec0b20907861e7e7a.

Read-only enumeration confirms all 18 sheets, in workbook order below. Targets
are logical business records after relocation, not claims that every model is
implemented. Workbook facts require authorized import, provenance and
reconciliation; the selected HR provider remains authoritative for employment.
After an approved source switch, People owns its business records.

F/M counts are formula cells/merged ranges measured in the source. They identify
extraction hazards, not proof that every formula or merge is defective. Formula
results were not recalculated. No stored Excel error-typed cells were found;
that does not establish semantic correctness. No staff rows or source values
are reproduced here.

| Sheet | What it records | People owner and record | Authoritative source | Observed structure and migration hazards |
|---|---|---|---|---|
| 00 Guide | Operating flow, accountable owners, proficiency and closure guidance | Not a People record — governed policy/reference requirements | Workbook requirements evidence, then business-confirmed People policy | F/M 0/17. Narrative and merged headings need interpretation, not row import. |
| 01 Staff Master | Employment context and derived requirement readiness | Employees context; Skills readiness projection | HR provider for employment; workbook identifiers reconciled with provenance | F/M 200/2. Text Staff ID and position names need reviewed matching; formula readiness is derived. |
| 02 Skill Catalogue | Skill definitions, criticality, assessment method and evidence guide | Skills catalog and versioned proficiency definitions | Workbook import with provenance, then People | F/M 0/2. Skill IDs and department/shared labels need controlled mapping; no formula-free guarantee of valid keys. |
| 03 Position Requirements | Position/department/tier skill requirements, weights and effective dates | Skills requirement profile/version/items/selectors | Workbook import with provenance, then People | F/M 1400/2. Composite text profile keys and formulas are not stable identities; reconcile active weights. |
| 04 Assessment Log | Assessment history, verification, certificates and next-due status | Skills assessment/verification history; derived current competency | Workbook input facts with provenance, then People | F/M 2700/2. Lookup names, gap/latest/due formulas must be recomputed; preserve verification evidence. |
| 05 Development Actions | Gap-linked interventions, accountable owners, dates and closure | Skills development action and audit history | Workbook input facts with provenance, then People | F/M 1400/2. Linked assessment IDs require reconciliation; computed improvement does not prove verified closure. |
| 06 Training Register | Training catalog/event details, cohort, schedule and costs | Training course/event and delivery records | Workbook input facts with provenance, then People | F/M 50/3. Event totals derive from participants; planned/actual costs need separate meaning. |
| 07 HOD-HR Dashboard | Department-level coverage, gaps, overdue work and outcomes | Not a separate People record — Skills/Training analytical report | People canonical records, recomputed | F/M 129/6. Formula report: do not import totals as facts; scope, denominators and as-of matter. |
| 08 HOD Matrix Template | Department/position/tier batch assessment layout | Skills assessment capture view; no separate score store | Verified workbook capture with provenance if populated; People thereafter | F/M 84/2. Fixed 12-skill presentation must not cap the model; formula outputs are derived. |
| 09 Critical Skill Coverage | Minimum competent headcount, backups, risk and follow-up | Skills coverage policy/assignments plus derived coverage report | Workbook policy/action inputs with provenance; People verified assessment history for coverage | F/M 300/2. Mixed inputs/report; named backups are not proof of competence. |
| 99 Lists | Departments, tiers, cycles, methods, action types and statuses | Not a single People record — owning module types/reference data | Workbook vocabulary mapping with business confirmation; HR provider for organization authority | F/M 0/3. Text labels need explicit normalization; do not hard-code workbook organization. |
| 10 Training Requests | Needs, nominations, reviews, approvals and event links | Training need/request/approval history | Workbook input facts with provenance, then People | F/M 600/3. Group recipients and text links need explicit relationships; no invented historical plan approvals. |
| 11 Training Attendance | Participant attendance, hours, results, certificates and follow-up | Training participation/result/certificate records | Workbook input facts with provenance, then People | F/M 3750/3. Passport helper columns and lookups are projections; test results are not skill assessments. |
| 12 Training Evaluation | Eight ratings, learning/application feedback and HR follow-up | Training participant evaluation and follow-up | Workbook input facts with provenance, then People | F/M 600/3. Computed averages are not source facts; distinguish blank, zero and not applicable. |
| 13 Effectiveness Review | Staged workplace impact, reassessment, verification and actions | Training effectiveness review linked to Skills reassessment | Workbook input facts with provenance, then People | F/M 900/3. 30/60/90 stages are source policy, not universal rules; recompute improvement. |
| 14 Employee Skill Register | Required/current skills, gaps, due status and linked actions/training | Not a separate People record — Skills register projection, also consumed by Progression | People canonical records, recomputed | F/M 6800/3. Formula-heavy report; preserve source identity links, not cached current scores. |
| 15 Staff Training Passport | Selected employee's skill/training/certificate history | Not a separate People record — scoped Skills/Training passport report | People canonical records, recomputed | F/M 676/5. Confirmed B5/E5 lookup-index defects; selected staff and all helper results need recomputation. |
| 16 Training Form Pack | Printable request/approval, evaluation and effectiveness forms | Training canonical request/evaluation/effectiveness records when completed; blank templates are not records | Completed paper/workbook evidence with provenance, then People | F/M 0/15. Merged print fields/signatures are not table rows; deduplicate against digital records. |

## Input facts versus reports

Candidate input sources are 01–06 and 10–13: separate entered facts from
lookups, formulas and helper columns inside each sheet. Sheet 09 mixes policy,
named assignments and actions with calculated coverage; only reviewed inputs
are import candidates. Sheet 08 can capture assessments when populated, but
must enter the same verified assessment lifecycle once. Sheet 16 is a blank
form pack unless completed evidence is supplied; reconcile completed forms with
canonical requests, evaluations and effectiveness reviews.

Sheets 07, 14 and 15 are derived reports. Recompute them from canonical records
under current authorization and an explicit as-of date, rather than importing
cached totals, current scores or passport helpers. Sheet 00 is requirements
guidance. Sheet 99 supplies labels for reviewed reference mapping, not an
independent organization authority.

The workbook has no dedicated training-plan or Progression tab. Do not invent
approved historical plans, eligibility decisions or awards from attendance,
scores or report cells. Training plans/revisions need the additional evidence
specified in [0003](0003-people-training-planning-and-delivery.md); Progression
consumes verified canonical history under its approved policy.

## Confirmed lookup defect

In 15 Staff Training Passport, A5 labels Manager but B5 looks up Staff Master
column 6. Staff Master F5 is Tier; Manager is H5 (column 8). D5 labels Hire Date
but E5 looks up column 8, which is Manager; Hire Date is I5 (column 9). These
source indices were inspected directly and reproduce plan 0006's finding.

Preserve intended field meanings in the migration mapping, not the incorrect
lookup results. The source workbook is unchanged. This inspection did not
validate every formula, recalculate the workbook or certify all source rows.

## Cross-sheet identity and extraction rules

Merged presentation ranges occur in every sheet; extract the actual data
regions and top-left labels rather than treating merged blanks as missing
business facts. Formula counts include prepared template rows and are not
record counts. Staff/skill/request/event identifiers and text department,
position or status labels must resolve through versioned mappings; dropdowns
do not establish relational integrity or authorize guessed matches.

Quarantine ambiguous keys, missing evidence and invalid dates. Preserve input
hash, sheet/cell or row provenance, stable source identity and idempotency.
Separate live records from examples/template rows with source-owner approval.
Normalize dates, proficiency, statuses and amounts explicitly, including blank
versus unknown versus zero. Reconcile relationships, verified history, weights,
participants, costs and evidence before an authority switch. The mappings do
not authorize importing sensitive workbook content into a public repository.
