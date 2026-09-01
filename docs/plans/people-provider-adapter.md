# People provider adapter

**Status:** In progress
**Last Updated:** 2026-08-31
**Sources:** [BelimbingApp/blb-people#27](https://github.com/BelimbingApp/blb-people/issues/27), connector issues [#22](https://github.com/BelimbingApp/blb-people/issues/22), [#25](https://github.com/BelimbingApp/blb-people/issues/25), and [#26](https://github.com/BelimbingApp/blb-people/issues/26)
**Agents:** codex/gpt-5

## Problem Essence

The connector cannot treat native People data as a special case without losing provider portability, but `blb-people` must not import an optional connector contract or expose workforce data before a service-authentication boundary exists.

## Desired Outcome

Native People publishes one tenant-safe workforce projection seam. A co-located adapter calls it in process, while a future authenticated remote endpoint delegates to the same seam and preserves the same records, pagination, and errors.

## Top-Level Components

- `people/provider` owns the native projection boundary and its provider-facing data vocabulary.
- `ReadsWorkforceBootstrap` is the transport-neutral application contract.
- `NativeWorkforceBootstrapReader` projects companies, organization units, employees, user links, managers, and department heads without returning Eloquent models.
- The connector repository owns the adapter that translates this boundary into its provider-neutral SDK.
- Base Integration owns outbound remote transport; the People server owns inbound authentication and authorization enforcement once a service-principal design is available.

## Design Decisions

Three shapes were considered. Importing the connector SDK into People would eliminate a small mapping layer but would make an optional consumer a hard dependency of the authoritative HR Domain. Letting the connector read People tables directly would be quick but would bypass People invariants, tenant enforcement, and future remote placement. A People-owned projection contract plus a connector-owned adapter wins because both co-located and remote transports terminate at the same application service while repository dependencies remain one-way.

Publishing an HTTP route now was also considered. Existing user-session authorization is not a service-to-service credential model, and emulating a human session would violate the identity work tracked by `[1005]`. The route therefore remains absent until short-lived, audience-bound service authentication and an explicit People capability are implemented. Page and resume cursors are authenticated opaque values. The page cursor binds the tenant, bootstrap start, highest eligible employee ID at that start, and last emitted ID; it is pagination state, never an authorization source.

The first slice projects Core departments as organization units because they have stable native identities and explicit heads. Native designations are mutable strings, so the service truthfully declares no position resource until a stable native position source and mapping are agreed.

## Public Contract

`ReadsWorkforceBootstrap::read()` requires an ambient tenant context and fails closed when none exists. It returns typed, JSON-serializable pages with provider and contract versions, stable typed references, an employee high-water mark, and tenant-bound authenticated cursors. Human employees only are projected. Cross-tenant or Agent manager, department-head, user, department, and company relationships are omitted rather than emitted as trusted references. Companies and organization-unit definitions are emitted on the first page only, so later mutable reads cannot replace the reference snapshot mid-bootstrap.

The current contract is an internal application seam, not a public remote API. The employee high-water mark prevents newly inserted rows from entering a running bootstrap, but mutable row updates and deletions are not a transactionally frozen snapshot across requests. The incremental feed tracked by `[1006]` must replay changes since the captured bootstrap start to close that window. The seam does not yet declare incremental changes, writes, reconciliation, SSO, health, or conformance completion.

## Phases

### Provider-side bootstrap seam

- [x] Add the `people/provider` Module manifest and transport-neutral bootstrap contract. {codex/gpt-5}
- [x] Project tenant companies, Core organization units, human employees, native users, managers, and department heads into typed records. {codex/gpt-5}
- [x] Add bounded pagination with an employee high-water mark, first-page reference snapshot, and authenticated tenant-bound cursors. {codex/gpt-5}
- [x] Prove missing-context failure, exact tenant inclusion/exclusion, unsafe related-reference suppression, cursor isolation/tamper rejection, late-row exclusion, and the intentionally absent HTTP endpoint. {codex/gpt-5}

### Authenticated transport and connector adapter

- [ ] Define and implement the `[1005]` service principal, short-lived audience-bound credential, route capability, revocation, and audit policy before registering the remote endpoint.
- [ ] Add the remote controller as a thin serializer over `ReadsWorkforceBootstrap`; send connector HTTP calls through Base Integration.
- [ ] Implement connector-owned co-located and remote adapters that map this wire contract to the common SDK without importing native models.
- [ ] Pass the common adapter conformance suite in both placements and prove equivalent result semantics.

### Complete first-party capability coverage

- [ ] Add a stable native position projection rather than deriving identity from mutable designation text.
- [ ] Add idempotent incremental changes, deactivation/merge checkpoints, reconciliation, and freshness as part of `[1006]`.
- [ ] Declare only installed and implemented native capabilities, then add supported commands, provider navigation, health, and version compatibility.
- [ ] Keep connector-owned Skill and Training records independent of native People provider replacement.
