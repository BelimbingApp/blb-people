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

Paths in **Test file(s)** are relative to the People domain root. The contract
test requires every named path to exist, preventing evidence references from
drifting as tests move or disappear.

| Module | Business operation | Wrong tenant | Wrong company | Missing capability | Unauthorized actor | Test file(s) |
|---|---|---|---|---|---|---|
| Skills | View skill catalogue | covered | covered | covered | covered | `Skills/Tests/Feature/CatalogPageTest.php` |
| Skills | Install starter catalogue | covered | covered | covered | covered | `Skills/Tests/Feature/CatalogPageTest.php` |
| Skills | Define a skill | covered | covered | covered | covered | `Skills/Tests/Feature/CatalogPageTest.php`<br>`Skills/Tests/Feature/SkillCatalogTest.php` |
| Skills | Revise a skill | covered | covered | covered | covered | `Skills/Tests/Feature/CatalogPageTest.php`<br>`Skills/Tests/Feature/SkillCatalogTest.php` |
| Skills | Activate or deactivate a skill | covered | covered | covered | covered | `Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php`<br>`Skills/Tests/Feature/CompanyIsolationTest.php` |
| Skills | Define a category | covered | covered | covered | covered | `Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php`<br>`Skills/Tests/Feature/CompanyIsolationTest.php` |
| Skills | Rename a category | covered | covered | covered | covered | `Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php`<br>`Skills/Tests/Feature/CompanyIsolationTest.php` |
| Skills | Activate or deactivate a category | covered | covered | covered | covered | `Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php`<br>`Skills/Tests/Feature/CompanyIsolationTest.php` |
| Skills | Draft a proficiency scale | covered | covered | covered | covered | `Skills/Tests/Feature/ProficiencyScaleTest.php`<br>`Skills/Tests/Feature/CatalogPageTest.php` |
| Skills | Publish a proficiency scale | covered | covered | covered | covered | `Skills/Tests/Feature/ProficiencyScaleTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` |
| Skills | Draft a new proficiency-scale version | covered | covered | covered | covered | `Skills/Tests/Feature/ProficiencyScaleTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` |
| Skills | Retire a proficiency scale | covered | covered | missing | missing | `Skills/Tests/Feature/ProficiencyScaleTest.php`<br>`Skills/Tests/Feature/CompanyIsolationTest.php` |
| Skills | Discard a draft proficiency scale | covered | covered | missing | missing | `Skills/Tests/Feature/ProficiencyScaleTest.php`<br>`Skills/Tests/Feature/CompanyIsolationTest.php` |
| Skills | View a requirement profile | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` |
| Skills | Draft a requirement profile | covered | covered | missing | missing | `Skills/Tests/Feature/RequirementProfileTest.php` |
| Skills | Draft a new requirement-profile version | covered | covered | missing | missing | `Skills/Tests/Feature/RequirementProfileTest.php` |
| Skills | Submit a requirement profile for review | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` |
| Skills | Approve a requirement profile as HOD | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` |
| Skills | Return a requirement profile as HOD | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` |
| Skills | Approve a requirement profile as HR | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` |
| Skills | Return a requirement profile as HR | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` |
| Skills | Publish an approved requirement profile | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` |
| Skills | Retire a governed requirement profile | covered | covered | covered | covered | `Skills/Tests/Feature/RequirementProfileTest.php` |
| Skills | Discard a draft requirement profile | covered | covered | missing | missing | `Skills/Tests/Feature/RequirementProfileTest.php` |
| Skills | Resolve applicable skill requirements | covered | covered | missing | missing | `Skills/Tests/Unit/ResolvesSkillRequirementsContractTest.php`<br>`Skills/Tests/Feature/RequirementProfileTest.php` |
| Skills | View the assessment matrix | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentMatrixPageTest.php` |
| Skills | Draft an assessment | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php`<br>`Skills/Tests/Feature/AssessmentMatrixPageTest.php` |
| Skills | Submit an assessment | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php`<br>`Skills/Tests/Feature/AssessmentMatrixPageTest.php` |
| Skills | Submit an assessment batch | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php`<br>`Skills/Tests/Feature/AssessmentMatrixPageTest.php` |
| Skills | Request HOD assessment verification | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php` |
| Skills | Resubmit an assessment after correction | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php` |
| Skills | Return an assessment for correction | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php` |
| Skills | Verify an assessment as HOD | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php` |
| Skills | Finalize a verified assessment | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php` |
| Skills | Finalize an assessment batch | covered | covered | covered | covered | `Skills/Tests/Feature/AssessmentStoreTest.php` |
| Skills | Confirm an actor binding | covered | covered | missing | covered | `Skills/Tests/Feature/SkillAudienceTest.php` |
| Skills | Revoke an actor binding | covered | covered | missing | covered | `Skills/Tests/Feature/SkillAudienceTest.php` |
| Skills | Assign an assessor | covered | covered | missing | covered | `Skills/Tests/Feature/SkillAudienceTest.php` |
| Skills | View development actions | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionPageTest.php`<br>`Skills/Tests/Feature/DevelopmentActionStoreTest.php` |
| Skills | Propose development actions from gaps | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` |
| Skills | Propose a manual development action | covered | covered | missing | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php` |
| Skills | Approve a development action | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` |
| Skills | Tailor a development-action proposal | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` |
| Skills | Start a development action | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` |
| Skills | Put a development action on hold | covered | covered | missing | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php` |
| Skills | Complete a development intervention | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` |
| Skills | Link a reassessment | covered | covered | missing | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php` |
| Skills | Cancel a development action | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` |
| Skills | Comment on a development action | covered | covered | covered | covered | `Skills/Tests/Feature/DevelopmentActionStoreTest.php`<br>`Skills/Tests/Feature/SkillsLivewireActionCoverageTest.php` |
| Training | Training application boundary after relocation | missing | missing | missing | missing | missing |
| Provider | Read a workforce bootstrap page | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceBootstrapReaderTest.php` |
| Provider | Read incremental workforce changes | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceChangeReaderTest.php` |
| Provider | Resolve a platform company | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceDirectoryTest.php` |
| Provider | Resolve a stable company reference | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceDirectoryTest.php` |
| Provider | Enumerate company employees | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceDirectoryTest.php` |
| Provider | Resolve an employee for a platform user | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceDirectoryTest.php` |
| Provider | Resolve a workforce remap | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceDirectoryTest.php` |
| Provider | Resolve a workforce subject | covered | covered | missing | missing | `Provider/Tests/Feature/NativeWorkforceSubjectResolverTest.php`<br>`Provider/Tests/Feature/WorkforceSubjectContractTest.php` |
| Progression | Read the published progression policy | covered | covered | missing | missing | `Progression/Tests/Feature/PublishedProgressionPolicyTest.php` |
