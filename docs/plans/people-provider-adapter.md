# People provider adapter

**Status:** Foundation published; adapter implementation blocked on identity and synchronization prerequisites
**Last Updated:** 2026-09-02
**Sources:** provider contract [BelimbingApp/blb-people#22](https://github.com/BelimbingApp/blb-people/issues/22), first-party adapter [#27](https://github.com/BelimbingApp/blb-people/issues/27), identity/authentication [#25](https://github.com/BelimbingApp/blb-people/issues/25), synchronization [#26](https://github.com/BelimbingApp/blb-people/issues/26), connector SDK [BelimbingApp/blb-people-connector#1](https://github.com/BelimbingApp/blb-people-connector/pull/1), and native projection seam [BelimbingApp/blb-people#39](https://github.com/BelimbingApp/blb-people/pull/39)
**Agents:** codex/gpt-5, desktop-sol

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

The current People contract is an internal application seam, not a public remote API. The employee high-water mark prevents newly inserted rows from entering a running bootstrap, but mutable row updates and deletions are not a transactionally frozen snapshot across requests. The incremental feed tracked by `[1006]` must replay changes since the captured bootstrap start to close that window. The seam does not yet implement incremental changes, writes, reconciliation, SSO, or health; adapters must omit those ports and capabilities until the corresponding provider behaviour exists.

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
- [ ] Add idempotent incremental changes, deactivation/merge checkpoints, reconciliation, and freshness as part of `[1006]`.
- [ ] Under #27, declare only installed and implemented native capabilities, then add supported commands, provider navigation, health, and provider-version compatibility.
- [ ] Keep connector-owned Skill and Training records independent of native People provider replacement.
