# People denial parity matrix

This inventory records the denial evidence that exists for every business
operation currently exposed by the People Skills, Provider, and Progression
modules. Training is listed as an explicit gap until its relocation introduces
an application boundary in this repository.

The inventory boundary is the user-callable Livewire commands and the public
provider/progression contracts. DTO constructors, model accessors, framework
lifecycle methods, and internal projection helpers are not business
operations. A `covered` cell means the named test file exercises that denial;
`missing` means that the repository has no such proof today. Missing evidence
is an inventory result, not a claim that the operation must necessarily enforce
that denial at its current boundary.

All named evidence currently exercises the co-located implementation. There is
no remote People transport or first-party remote adapter in this repository, so
remote denial parity is **missing for every operation in this matrix**. A remote
implementation must add equivalent evidence before changing that statement;
co-located coverage alone is not transport parity.

**Projection path** records whether the operation has a second, projection-side
implementation that must refuse identically to the native one. Today the People
provider seam has exactly one such pair: `ResolvesWorkforceSubjects` is answered
natively by `Provider/Services/NativeWorkforceSubjectResolver.php` and, when the
optional `blb-people-connector` is composed, by its
`ProjectionWorkforceSubjectResolver`; the connector's
`WorkforceSubjectDenialParityTest` executes both over shared fixtures. Every
other row is a Livewire command or native service with a single implementation,
so its cell reads `missing`: there is no second path to hold to parity, not a
gap in evidence. A `connector:` prefix marks a path relative to the connector
domain root; the inventory test below cannot see that repository and only
checks the shape, while the connector's suite is what executes it.

Paths in **Test file(s)** are relative to the People domain root. The contract
test requires every named path to exist, preventing evidence references from
drifting as tests move or disappear.

| Module | Business operation | Wrong tenant | Wrong company | Missing capability | Unauthorized actor | Test file(s)  Projection path |
|---|---|---|---|---|---|---|---|
| Skills | View skill catalogue | covered | covered | covered | covered | `Skills/Tests/Feature/CatalogPageTest.php` | missing |
| Skills | Install starter catalogue | covered | covered | covered | covered | `Skills/Tests/Feature/CatalogPageTest.php` | missing |
| Skills | Define a skill | covered | covered | covered | covered | `Skills/Tests/Feature/CatalogPageTest.php`<br>`Skills/Tests/Feature/SkillCatalogTest.php` | missing |
| Skills | Revise a skill | covered | covered | covered | covered | `Skills/Tests/Feature/CatalogPageTest.php`<br>`Skills/Tests/Feature/SkillCatalogTest.php` | missing |
| Skills | Activate or deactivate a skill | covered | covered | covered | covered | `Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php`<br>`Skills/Tests/Feature/CompanyIsolationTest.php` | missing |
| Skills | Define a category | covered | covered | covered | covered | `Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php`<br>`Skills/Tests/Feature/CompanyIsolationTest.php` | missing |
| Skills | Rename a category | covered | covered | covered | covered | `Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php`<br>`Skills/Tests/Feature/CompanyIsolationTest.php` | missing |
| Skills | Activate or deactivate a category | covered | covered | covered | covered | `Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php`<br>`Skills/Tests/Feature/CompanyIsolationTest.php` | missing |
| Skills | Draft a proficiency scale | covered | covered | covered | covered | `Skills/Tests/Feature/ProficiencyScaleTest.php`<br>`Skills/Tests/Feature/CatalogPageTest.php` | missing |
| Skills | Publish a proficiency scale | covered | covered | covered | covered | `Skills/Tests/Feature/ProficiencyScaleTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` | missing |
| Skills | Draft a new proficiency-scale version | covered | covered | covered | covered | `Skills/Tests/Feature/ProficiencyScaleTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` | missing |
| Skills | Retire a proficiency scale | covered | covered | missing | missing | `Skills/Tests/Feature/ProficiencyScaleTest.php`<br>`Skills/Tests/Feature/CompanyIsolationTest.php` | missing |
| Skills | Discard a draft proficiency scale | covered | covered | missing | missing | `Skills/Tests/Feature/ProficiencyScaleTest.php`<br>`Skills/Tests/Feature/CompanyIsolationTest.php` | missing |
| Skills | View a requirement profile | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` | missing |
| Skills | Draft a requirement profile | covered | covered | missing | missing | `Skills/Tests/Feature/RequirementProfileTest.php` | missing |
| Skills | Draft a new requirement-profile version | covered | covered | missing | missing | `Skills/Tests/Feature/RequirementProfileTest.php` | missing |
| Skills | Submit a requirement profile for review | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` | missing |
| Skills | Approve a requirement profile as HOD | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` | missing |
| Skills | Return a requirement profile as HOD | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` | missing |
| Skills | Approve a requirement profile as HR | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` | missing |
| Skills | Return a requirement profile as HR | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` | missing |
| Skills | Publish an approved requirement profile | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` | missing |
| Skills | Retire a governed requirement profile | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` | missing |
| Skills | Discard a draft requirement profile | covered | covered | missing | missing | `Skills/Tests/Feature/RequirementProfileTest.php` | missing |
| Skills | Resolve applicable skill requirements | covered | covered | missing | missing | `Skills/Tests/Unit/ResolvesSkillRequirementsContractTest.php`<br>`Skills/Tests/Feature/RequirementProfileTest.php` | missing |
| Skills | View the assessment matrix | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentMatrixPageTest.php` | missing |
| Skills | Draft an assessment | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php`<br>`Skills/Tests/Feature/AssessmentMatrixPageTest.php` | missing |
| Skills | Submit an assessment | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php`<br>`Skills/Tests/Feature/AssessmentMatrixPageTest.php` | missing |
| Skills | Submit an assessment batch | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php`<br>`Skills/Tests/Feature/AssessmentMatrixPageTest.php` | missing |
| Skills | Request HOD assessment verification | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php` | missing |
| Skills | Resubmit an assessment after correction | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php` | missing |
| Skills | Return an assessment for correction | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php` | missing |
| Skills | Verify an assessment as HOD | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php` | missing |
| Skills | Finalize a verified assessment | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php` | missing |
| Skills | Finalize an assessment batch | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php` | missing |
| Skills | Confirm an actor binding | covered | covered | missing | covered | `Skills/Tests/Feature/SkillAudienceTest.php` | missing |
| Skills | Revoke an actor binding | covered | covered | missing | covered | `Skills/Tests/Feature/SkillAudienceTest.php` | missing |
| Skills | Assign an assessor | covered | covered | missing | covered | `Skills/Tests/Feature/SkillAudienceTest.php` | missing |
| Skills | View development actions | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionPageTest.php`<br>`Skills/Tests/Feature/DevelopmentActionStoreTest.php` | missing |
| Skills | Propose development actions from gaps | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` | missing |
| Skills | Propose a manual development action | covered | covered | missing | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php` | missing |
| Skills | Approve a development action | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` | missing |
| Skills | Tailor a development-action proposal | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` | missing |
| Skills | Start a development action | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` | missing |
| Skills | Put a development action on hold | covered | covered | missing | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php` | missing |
| Skills | Complete a development intervention | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` | missing |
| Skills | Link a reassessment | covered | covered | missing | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php` | missing |
| Skills | Cancel a development action | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` | missing |
| Skills | Comment on a development action | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` | missing |
| Training | Training application boundary after relocation | missing | missing | missing | missing | missing | missing |
| Provider | Read a workforce bootstrap page | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceBootstrapReaderTest.php` | missing |
| Provider | Read incremental workforce changes | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceChangeReaderTest.php` | missing |
| Provider | Resolve a platform company | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceDirectoryTest.php` | missing |
| Provider | Resolve a stable company reference | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceDirectoryTest.php` | missing |
| Provider | Enumerate company employees | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceDirectoryTest.php` | missing |
| Provider | Resolve an employee for a platform user | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceDirectoryTest.php` | missing |
| Provider | Resolve a workforce remap | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceDirectoryTest.php` | missing |
| Provider | Resolve a workforce subject | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceSubjectResolverTest.php`<br>`Provider/Tests/Feature/WorkforceSubjectContractTest.php` | `connector:Connector/Services/ProjectionWorkforceSubjectResolver.php`<br>`connector:Connector/Tests/Feature/WorkforceSubjectDenialParityTest.php` |
| Progression | Read the published progression policy | covered | covered | missing | missing | `Progression/Tests/Feature/PublishedProgressionPolicyTest.php` | missing |
