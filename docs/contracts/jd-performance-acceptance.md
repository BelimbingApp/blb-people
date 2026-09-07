# JD/performance acceptance matrix (JP-A01–JP-A13)

JP-G08 (#200) inventory: one row per acceptance scenario in
`docs/plans/0007-people-verification-security-and-rollout.md`, naming the
test files that prove it from the G01–G06 lanes. Paths in **Test file(s)**
are relative to the People domain root. The contract test
(`Tests/Feature/JpAcceptanceMatrixTest.php`) requires every named file to
exist and every named path to resolve, so references cannot drift as tests
move or disappear.

**Direct path** is the store, service or data type the scenario executes
through. **Explorer path** is the drill-through surface that reads it.
**Export path** is the download that carries it out. A `missing` cell means
that path does not exist for the scenario not an evidence gap: there is
nothing to hold to parity there.

One honest boundary: JP-A12's run-twice JD/KPI import execution is unbuilt
(JP-G07 has no import path to run). Its row holds what is proven today —
the downstream-write invariants and explicit-unknown rules — and says so in
the observable cell rather than naming a file that does not prove it.

| Scenario | Observable result | Test file(s) | Direct path | Explorer path | Export path |
|---|---|---|---|---|---|
| JP-A01 | Existing assignment/history resolves its exact applicable version; future content does not replace today's JD; structured fields and competency links survive across published versions. | `Performance/Tests/Feature/JobDescriptionStoreTest.php`<br>`Performance/Tests/Feature/PositionVersionLinkageTest.php` | `class:App\Domains\People\Performance\Services\JobDescriptionStore` | `class:App\Domains\People\Performance\Services\OrganisationPerformanceDetail` | missing |
| JP-A02 | Transfer, vacancy, acting and concurrent appointments each yield unambiguous applicable descriptions; no shared JD is silently edited for one employee. | `Performance/Tests/Feature/PositionVersionLinkageTest.php` | `class:App\Domains\People\Organisation\Services\PositionDirectory` | missing | missing |
| JP-A03 | JD authority text grants no application, financial or payroll permission, however it is worded; holding the described position gains nothing from it. | `Performance/Tests/Feature/JobDescriptionSectionTest.php`<br>`Performance/Tests/Feature/JobDescriptionStoreTest.php` | `class:App\Domains\People\Performance\Services\JobDescriptionStore` | missing | missing |
| JP-A04 | Original KPI target and approval remain; the approved amendment carries explicit effective treatment and reason; history is not recalculated against a replaced target. | `Performance/Tests/Feature/KpiRecordServiceTest.php` | `class:App\Domains\People\Performance\Services\KpiRecordService` | missing | missing |
| JP-A05 | Missing, zero and zero-denominator measures are distinct typed values with declared calculation and rubric semantics; no fabricated score or incompatible average. | `Performance/Tests/Feature/KpiRecordServiceTest.php` | `class:App\Domains\People\Performance\Services\KpiRecordService` | missing | missing |
| JP-A06 | Team KPI attribution follows approved policy with personal attribution; no duplicate headcount and no automatic team-to-person score copy. | `Performance/Tests/Feature/KpiRecordServiceTest.php` | `class:App\Domains\People\Performance\Services\KpiRecordService` | missing | missing |
| JP-A07 | Original evidence, review and released rationale stay traceable; a versioned correction or employee response preserves who changed what and why without rewriting the finalized row. | `Performance/Tests/Feature/PerformanceReviewCorrectionTest.php`<br>`Performance/Tests/Feature/ManagerReviewsPageTest.php` | `class:App\Domains\People\Performance\Services\PerformanceReviewStore` | `class:App\Domains\People\Performance\Livewire\Reviews\Index` | missing |
| JP-A08 | KPI and competence records stay independent: performance evidence attaches without deciding competence and cannot set, revoke, promote or pay. | `Skills/Tests/Unit/PerformanceEvidenceBoundaryTest.php` | `class:App\Domains\People\Skills\Data\PerformanceEvidenceReference` | missing | missing |
| JP-A09 | Only policy-eligible versioned review evidence is consumed; missing or disputed periods follow the published rule; corrections flag governed reevaluation without rewriting awards. | `Progression/Tests/Feature/ProgressionPerformanceEvidenceTest.php` | `class:App\Domains\People\Performance\Services\PerformanceReviewStore` | missing | missing |
| JP-A10 | Own, released and scoped records match the audience matrix across chart, page and export reads; confidential material and other employees stay denied; aggregates confer no personal access. | `Performance/Tests/Feature/KpiRecordServiceTest.php`<br>`Performance/Tests/Feature/ManagerReviewsPageTest.php` | `class:App\Domains\People\Performance\Services\KpiRecordService` | `route:people.performance.reviews.index` | missing |
| JP-A11 | Historical reads resolve the version effective at a date and name it with cutoff; old and corrected views stay distinguishable under current authorization. | `Performance/Tests/Feature/PerformanceReviewCorrectionTest.php`<br>`Performance/Tests/Feature/ManagerReviewsPageTest.php` | `class:App\Domains\People\Performance\Services\PerformanceReviewStore` | `class:App\Domains\People\Performance\Livewire\Reviews\Index` | missing |
| JP-A12 | No fabricated approvals, zero values or downstream pay/skill changes; missing history stays explicit. Proven for the executed paths: workbook provenance with zero writes, unknown-kept-unknown effectiveness, pay/competence boundary refusals. Run-twice JD/KPI import execution is unbuilt (JP-G07) and named as the gap, not covered by these files. | `Skills/Tests/Feature/SkillWorkbookDryRunCommandTest.php`<br>`Training/Tests/Feature/TrainingEffectivenessStoreTest.php`<br>`Skills/Tests/Unit/PerformanceEvidenceBoundaryTest.php`<br>`Attendance/Tests/Feature/AttendanceDoesNotImportPayrollTest.php`<br>`Claim/Tests/Feature/ClaimDoesNotImportPayrollTest.php`<br>`Leave/Tests/Feature/LeaveDoesNotImportPayrollTest.php`<br>`Employees/Tests/Feature/EmployeesDoesNotImportPayrollTest.php` | `class:App\Domains\People\Skills\Console\Commands\SkillWorkbookDryRunCommand` | missing | missing |
| JP-A13 | KPI evidence keeps measure, period, source, baseline and permission context; a business outcome is never automatic proof of training causation and never changes competence by itself. | `Training/Tests/Unit/PerformanceEffectivenessBoundaryTest.php` | `class:App\Domains\People\Training\Data\EffectivenessPerformanceReference` | missing | missing |
