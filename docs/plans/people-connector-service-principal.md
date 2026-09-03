# People Connector Service Principal

**Issue:** BelimbingApp/blb-people#78  
**Refs:** [1005] (#25), [1006] (#26), connector #69/#70  
**Status:** decided — implementation follows on connector #70

## Problem

Every provider port is reached through `ProviderPortResolver`, which requires an `Actor` and authorises it before invoking the adapter. `Actor::validate()` requires `companyId !== null`, and `ProviderPortAuthorization::authorize()` additionally checks that the actor's tenant and company match the scope. A scheduled or headless sync has no user session to present; minting one would violate the identity boundary [1005] explicitly refused.

## Decision

Use `PrincipalType::SCHEDULER` — already defined and auditable in the platform — with a **per-connection service-principal identity**. The identity carries the connection's `company_id` and `tenant_id` so the existing tenant/company scope checks pass unchanged. It is minted as a fresh `Actor` at job dispatch time from the connection record, not stored as a credential, and is valid only for the duration of one sync pass.

**No new PrincipalType is needed.** `SCHEDULER` already exists, is already handled by `EffectivePermissions::forActor()` (which queries `base_authz_principal_capabilities` by `principal_type`), and is already labelled in the admin UI.

### Minting

```php
new Actor(
    type: PrincipalType::SCHEDULER,
    id: $connection->id,      // stable, per-connection identity
    companyId: $connection->company_id,
    tenantId: $connection->tenant_id,
);
```

`id` is the `provider_connections` primary key — stable, unique per tenant, and meaningful in audit records. It is not a user ID.

### Capability grant

The scheduler actor needs `people-connector.provider.read.employee_directory` (and any other directory capabilities declared on the adapter). This grant is inserted by the connector's own migration when a connection is activated and removed on deactivation/deletion — no manual admin step. It is a `PrincipalCapability` row with `principal_type = 'scheduler'` and `principal_id = $connection->id`, scoped to `company_id`.

`Actor::validate()` does not check `actingForUserId` for non-agent types, so the SCHEDULER actor passes validation without a delegating user.

### Audit

`ProviderPortAuthorization` already records the actor type, id, permission, and scope in the authorization evidence. Audit listeners record `SCHEDULER` as the principal type — distinguishable from user sessions in audit views.

### Revocation

Deactivating or deleting the connection removes the `PrincipalCapability` rows. No separate revocation step.

### Not in scope here

- Short-lived signed tokens (for remote placement under [1007]): that requires transport-layer authentication. The service principal decision here covers the co-located case only.
- `ActingForUserId` delegation: not applicable to a scheduler principal.

## Alternatives considered

**Dedicated `PrincipalType::SERVICE`:** would require a new enum value, new audit label, and new DB rows — no benefit over SCHEDULER for this use case, which is already a scheduled process.

**Synthetic user session:** explicitly refused by [1005] and violates the identity model.

**Skip authorization for SCHEDULER actors in ProviderPortResolver:** hides the authorization decision from audit and violates the capability model. Rejected.

## Effect on connector #70

The `SyncScheduler` (artisan command + scheduler entry) mints the `Actor` from the connection record before calling `WorkforceSyncRunner::bootstrap()` / `::incremental()`. A connection activation hook inserts the capability; deactivation removes it. The runner itself is unchanged.
