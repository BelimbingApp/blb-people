# People provider adapter

**Status:** Supplemental foundation published; completion remains blocked on the connector-owned Skill and Training sequence
**Last Updated:** 2026-09-04
**Sources:** provider contract [BelimbingApp/blb-people#22](https://github.com/BelimbingApp/blb-people/issues/22), supplemental persistence [#24](https://github.com/BelimbingApp/blb-people/issues/24), first-party adapter [#27](https://github.com/BelimbingApp/blb-people/issues/27), identity/authentication [#25](https://github.com/BelimbingApp/blb-people/issues/25), synchronization [#26](https://github.com/BelimbingApp/blb-people/issues/26), connector SDK [BelimbingApp/blb-people-connector#1](https://github.com/BelimbingApp/blb-people-connector/pull/1), connector persistence foundation [BelimbingApp/blb-people-connector#2](https://github.com/BelimbingApp/blb-people-connector/pull/2), connector access boundary [BelimbingApp/blb-people-connector#21](https://github.com/BelimbingApp/blb-people-connector/pull/21), and native projection seam [BelimbingApp/blb-people#39](https://github.com/BelimbingApp/blb-people/pull/39)
**Agents:** codex/gpt-5, desktop-sol/gpt-5.6-sol

## Problem Essence

The connector cannot treat native People data as a special case without losing provider portability, but `blb-people` must not import an optional connector contract or expose workforce data before a service-authentication boundary exists.

## Desired Outcome

Native People publishes one tenant-safe workforce projection seam. A co-located adapter calls it in process, while a future authenticated remote endpoint delegates to the same seam and preserves the same records, pagination, and errors.

## Top-Level Components

- `people/provider` owns the native projection boundary and its provider-facing data vocabulary.
- `ReadsWorkforceBootstrap` is the transport-neutral application contract.
- `NativeWorkforceBootstrapReader` projects companies, organization units, employees, user links, managers, and department heads without returning Eloquent models.
- The connector repository owns the canonical provider-neutral SDK, common conformance runner, and adapter that translates this boundary into that SDK.
- Base Integration owns outbound remote transport; the People server owns inbound authentication and authorization enforcement once a service-principal design is available.

## Design Decisions

Three shapes were considered. Importing the connector SDK into People would eliminate a small mapping layer but would make an optional consumer a hard dependency of the authoritative HR Domain. Letting the connector read People tables directly would be quick but would bypass People invariants, tenant enforcement, and future remote placement. A People-owned projection contract plus a connector-owned adapter wins because both co-located and remote transports terminate at the same application service while repository dependencies remain one-way.

Publishing an HTTP route now was also considered. Existing user-session authorization is not a service-to-service credential model, and emulating a human session would violate the identity work tracked by `[1005]`. The route therefore remains absent until short-lived, audience-bound service authentication and an explicit People capability are implemented. Page and resume cursors are authenticated opaque values. The page cursor binds the tenant, bootstrap start, highest eligible employee ID at that start, and last emitted ID; it is pagination state, never an authorization source.

The first slice projects Core departments as organization units because they have stable native identities and explicit heads. Native designations are mutable strings, so the service truthfully declares no position resource until a stable native position source and mapping are agreed.

## Public Contract

`ReadsWorkforceBootstrap::read()` requires an ambient tenant context and fails closed when none exists. It returns typed, JSON-serializable pages with provider and contract versions, stable typed references, an employee high-water mark, and tenant-bound authenticated cursors. Human employees only are projected. Cross-tenant or Agent manager, department-head, user, department, and company relationships are omitted rather than emitted as trusted references. Companies and organization-unit definitions are emitted on the first page only, so later mutable reads cannot replace the reference snapshot mid-bootstrap.

The canonical neutral contract is the `people-connector/connector` Module published by connector PR #1. It owns stable adapter descriptors, semantic contract versions, capability channels, narrow read/write/file/event ports, structured provider errors, provider health, workforce DTOs, registry preflight, and `ProviderConformance`. Connector-owned features compile against that Module only. This Domain owns the native data projection on the other side of the adapter and deliberately does not import the optional connector package.

Contract compatibility follows semantic major versions. An adapter descriptor declares its adapter and contract versions; `ProviderRegistry` refuses a contract major outside the configured compatibility window before the adapter becomes active, and `ProviderConformance` resolves and exercises only ports the capability matrix declares. Additive DTOs, capabilities, and optional ports may advance within major version 1 when existing semantics remain valid. Removing or changing an existing field, error meaning, port method, cursor meaning, or capability direction requires a new contract major and an explicit compatibility window. Provider-specific version negotiation and real-adapter certification remain adapter deliverables under #27 and #28, not alternate copies of the neutral contract.

The current People contract is an internal application seam, not a public remote API. The employee high-water mark prevents newly inserted rows from entering a running bootstrap, but mutable row updates and deletions are not a transactionally frozen snapshot across requests. The incremental feed tracked by `[1006]` must replay changes since the captured bootstrap start to close that window. The seam implements bootstrap and incremental changes; it does not yet implement writes, reconciliation, SSO, or health, and adapters must omit those ports and capabilities until the corresponding provider behaviour exists.

## Provider-contract publication evidence

| Contract outcome | Published evidence | Downstream proof |
| --- | --- | --- |
| Discovery, configuration, health, capability truth, and compatibility preflight | `ProviderAdapter`, `ProviderDescriptor`, `CapabilitySet`, `ProviderHealth`, and `ProviderRegistry` in connector PR #1 | Per-connection persistence and activation policy continue under #23–#26. |
| Stable workforce identity and provenance | Provider-qualified `ExternalReference`, exhaustive `WorkforceResourceType`, typed workforce records, and bootstrap/change DTOs in connector PR #1 | Native and HR2000 mappings are certified under #27 and #28. Mutable names and email addresses are never identity keys. |
| Paginated reads and optional operation-specific ports | `BootstrapsWorkforce`, `ReadsWorkforceChanges`, writable/file/event/reconciliation ports, and separate page/resume cursors | Each adapter declares only supported ports; unsupported capability directions remain absent before UI/workflow presentation. |
| Structured failure vocabulary | Unsupported, validation/configuration, authentication, conflict, temporary, compatibility, and unknown-outcome provider exceptions | Adapter-specific translation and recovery scenarios remain #27–#29 acceptance. |
| Reusable conformance | Connector-owned `ProviderConformance` plus contract fixtures/tests shipped unchanged in connector PR #1 | The same runner must pass against the real native and HR2000 adapters in #27/#28; those executions cannot precede the adapters they test. |
| Native provider-side boundary | `people/provider`, `ReadsWorkforceBootstrap`, typed JSON payloads, tenant-bound cursors, and fail-closed projection tests in native PR #39 | The connector-owned adapter maps this boundary without privileged model/table access in #27. |

This table is a publication record, not a claim that either real adapter already exists. It keeps #22's SDK result separate from #27/#28's provider certification and preserves the dependency spine `#22 → #23/#25/#26 → #27/#28`.

## Connector repository handoff

`BelimbingApp/blb-people-connector` is now the canonical installable home for the connector foundation. It is an optional nested BLB Domain mounted at `app/Domains/PeopleConnector/`; its [README installation procedure](https://github.com/BelimbingApp/blb-people-connector/blob/main/README.md), [Domain module manifest](https://github.com/BelimbingApp/blb-people-connector/blob/main/Connector/composer.json), and [owner-controlled CI](https://github.com/BelimbingApp/blb-people-connector/blob/main/.github/workflows/ci.yml) make that placement explicit.

The `[1004]` persistence foundation began in [BelimbingApp/blb-people-connector#2](https://github.com/BelimbingApp/blb-people-connector/pull/2) at merge `c8bff0572ffdc044bea98b300132f111d31b56b9`. It establishes tenant/company ownership, provider-linked stable references, remap/merge provenance, append-only snapshots, checkpoints, and reconciliation state, and is explicitly part of #24 rather than a closure of it. Progress since that handoff remains connector-owned; this People repository does not duplicate the tables:

- **Export / backup / restore** — landed on connector `main` via the DataShare vehicle ([connector #53](https://github.com/BelimbingApp/blb-people-connector/pull/53) and the company-ownership export section), with follow-up export-governance wording in [connector commit `36e131d`](https://github.com/BelimbingApp/blb-people-connector/commit/36e131d).
- **Privacy deletion / retention** — landed on connector `main` through [connector #60](https://github.com/BelimbingApp/blb-people-connector/pull/60), preserving identity and append-only provenance while redacting or tombstoning company-scoped personal projections.
- **Synchronization recovery and freshness** — the connector-side runner, durable checkpoint, deactivation/reactivation behavior, and freshness policy landed with the `[1006]` spine; the native incremental feed landed through [People #77](https://github.com/BelimbingApp/blb-people/pull/77).
- **Skill / Training aggregates** — catalogues and requirement profiles are on connector `main`; assessments and the batch matrix remain active under [#12](https://github.com/BelimbingApp/blb-people/issues/12), training catalogue/events remain active under [#14](https://github.com/BelimbingApp/blb-people/issues/14), and the later lifecycle remains on the remaining children of [#9](https://github.com/BelimbingApp/blb-people/issues/9).

#24 therefore has no unowned implementation slice to invent in this repository. It cannot close until the active connector landings and the Skill/Training child sequence prove the final acceptance criterion. While those dependencies remain open, #24 belongs in `task:blocked` without an agent claim; keeping an empty draft writer lane would misstate both ownership and progress.

The current foundation provides the acceptance boundary for #23:

- **Install and disconnected state:** the module can be mounted without a provider; [`active_provider` defaults to `null`](https://github.com/BelimbingApp/blb-people-connector/blob/main/Connector/Config/people-connector.php), and the [Connections feature tests](https://github.com/BelimbingApp/blb-people-connector/blob/main/Connector/Tests/Feature/ConnectionsPageTest.php) cover both unconfigured and missing-adapter states.
- **Secret-free configuration:** the [foundation migration](https://github.com/BelimbingApp/blb-people-connector/blob/main/Connector/Database/Migrations/0330_01_01_000000_create_people_connector_foundation_tables.php) stores provider identity and allowlisted public metadata, not credentials or tokens. Connection diagnostics and ordinary settings must continue to use the same redacted boundary.
- **Provider-neutral feature seam:** [port resolution](https://github.com/BelimbingApp/blb-people-connector/blob/main/Connector/Services/ProviderPortResolver.php) rejects undeclared operations before an adapter is called; feature modules resolve ports through the neutral contract rather than importing an adapter.
- **One authoritative provider:** the [connection model and migration](https://github.com/BelimbingApp/blb-people-connector/blob/main/Connector/Models/ProviderConnection.php) enforce tenant/company scope and one active connection for a scope, while the [registry](https://github.com/BelimbingApp/blb-people-connector/blob/main/Connector/Services/ProviderRegistry.php) keeps installed adapters separate from the selected provider.
- **Tenant, authorization, audit, and tests:** tenant-owned connector records use the shared tenancy boundary; connector permissions are declared in [`authz.php`](https://github.com/BelimbingApp/blb-people-connector/blob/main/Connector/Config/authz.php); provider identity, configuration, activation, health, projection, and reconciliation mutations must emit tenant-scoped audit evidence without secrets. The persistence and retention implementation is explicitly owned by [#24](https://github.com/BelimbingApp/blb-people/issues/24), so this handoff does not claim that the full audit/export/deletion lifecycle is finished.

The legacy Skill and Training sequence is explicitly retained in the owning People board rather than silently disappearing during the repository split:

| Legacy identity | Current tracked issue | Connector disposition |
| --- | --- | --- |
| `[0000]` | [#9](https://github.com/BelimbingApp/blb-people/issues/9) | Connector-owned Skill and Training spine; implementation remains tracked in `blb-people` until its modules land in the connector. |
| `[0001]` | [#10](https://github.com/BelimbingApp/blb-people/issues/10) | Connector implementation is also tracked by [connector #5](https://github.com/BelimbingApp/blb-people-connector/pull/5); the People issue remains the coordination record. |
| `[0002]` | [#11](https://github.com/BelimbingApp/blb-people/issues/11) | Connector-owned requirement catalogue; explicitly tracked, not superseded. |
| `[0003]` | [#12](https://github.com/BelimbingApp/blb-people/issues/12) | Connector-owned assessment and matrix; explicitly tracked, not superseded. |
| `[0004]` | [#13](https://github.com/BelimbingApp/blb-people/issues/13) | Connector-owned development actions; explicitly tracked, not superseded. |
| `[0005]` | [#14](https://github.com/BelimbingApp/blb-people/issues/14) | Connector-owned training catalogue and events; explicitly tracked, not superseded. |
| `[0006]` | [#15](https://github.com/BelimbingApp/blb-people/issues/15) | Connector-owned reassessment and score history; explicitly tracked, not superseded. |
| `[0007]` | [#16](https://github.com/BelimbingApp/blb-people/issues/16) | Connector-owned dashboards and coverage; explicitly tracked, not superseded. |
| `[0008]` | [#17](https://github.com/BelimbingApp/blb-people/issues/17) | Connector-owned workbook import and starter profiles; explicitly tracked, not superseded. |
| `[0009]` | [#18](https://github.com/BelimbingApp/blb-people/issues/18) | Connector-owned automation and cutover governance; explicitly tracked, not superseded. |

Connector repository follow-up issues [#3](https://github.com/BelimbingApp/blb-people-connector/issues/3), [#6](https://github.com/BelimbingApp/blb-people-connector/issues/6), [#8](https://github.com/BelimbingApp/blb-people-connector/issues/8), and [#16](https://github.com/BelimbingApp/blb-people-connector/issues/16) remain linked operational/conformance work. They do not replace the `[0000]`–`[0009]` product sequence above.

## Phases

### Provider-neutral SDK publication

- [x] Publish provider descriptors, semantic compatibility preflight, capability channels, health, narrow ports, structured errors, provider-qualified workforce DTOs, and separate page/resume checkpoints in connector PR #1. {codex/gpt-5}
- [x] Publish the common `ProviderConformance` runner and contract fixtures without importing `blb-people` or HR2000 models. {codex/gpt-5}
- [x] Document the version window and the ownership split between contract publication (#22) and real-adapter certification (#27/#28). {desktop-sol}

### Provider-side bootstrap seam

- [x] Add the `people/provider` Module manifest and transport-neutral bootstrap contract. {codex/gpt-5}
- [x] Project tenant companies, Core organization units, human employees, native users, managers, and department heads into typed records. {codex/gpt-5}
- [x] Add bounded pagination with an employee high-water mark, first-page reference snapshot, and authenticated tenant-bound cursors. {codex/gpt-5}
- [x] Prove missing-context failure, exact tenant inclusion/exclusion, unsafe related-reference suppression, cursor isolation/tamper rejection, late-row exclusion, and the intentionally absent HTTP endpoint. {codex/gpt-5}

### Authenticated transport and connector adapter

- [ ] Define and implement the `[1005]` service principal, short-lived audience-bound credential, route capability, revocation, and audit policy before registering the remote endpoint.
- [ ] Add the remote controller as a thin serializer over `ReadsWorkforceBootstrap`; send connector HTTP calls through Base Integration.
- [ ] Implement connector-owned co-located and remote adapters that map this wire contract to the common SDK without importing native models.
- [ ] Under #27, pass the published common adapter conformance runner in both placements and prove equivalent result semantics without changing the runner.

### Complete first-party capability coverage

- [ ] Add a stable native position projection rather than deriving identity from mutable designation text.
- [x] Add the incremental change seam `ReadsWorkforceChanges` (`NativeWorkforceChangeReader`): replays companies, units and employees changed at or after the resume instant — inclusive, so the window a running bootstrap cannot freeze is closed by the first incremental read — including employees whose department head or portal access changed without touching the employee row; soft-deleted companies as deactivations; employees walked under a start-of-read watermark; tenant-bound page and resume cursors. Connector-side execution, checkpoints and freshness landed in blb-people-connector#72. {kiat-20, `[1006]`}
- [ ] Still open from `[1006]`: a scheduled entry point (blb-people-connector#70, blocked on the service principal #78), an operator surface for reviewed remap/merge and reconciliation issues over the stores that exist, and a typed user projection.
- [ ] Under #27, declare only installed and implemented native capabilities, then add supported commands, provider navigation, health, and provider-version compatibility.
- [ ] Keep connector-owned Skill and Training records independent of native People provider replacement.
