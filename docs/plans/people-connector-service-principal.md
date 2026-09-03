# People Connector Service Principal

**Issue:** BelimbingApp/blb-people#78  
**Refs:** [1005] (#25), [1006] (#26), connector #69/#70  
**Status:** decided — implementation follows on connector #70

## Problem

Every provider port is reached through `ProviderPortResolver`, which requires an `Actor` and authorises it before invoking the adapter. `Actor::validate()` currently requires `companyId !== null` for every principal type, and `ProviderPortAuthorization::authorize()` additionally checks that the actor's tenant matches the ambient tenant and that the actor's company matches the scope when the scope has a company. A scheduled or headless sync has no user session to present; minting one would violate the identity boundary [1005] explicitly refused.

## Decision

Use `PrincipalType::SCHEDULER` — already defined and auditable in the platform — with a **per-connection service-principal identity**. The identity is minted as a fresh `Actor` at job dispatch time from the connection record, not stored as a credential, and is valid only for the duration of one sync pass.

**No new PrincipalType is needed.** `SCHEDULER` already exists, is already handled by `EffectivePermissions::forActor()` (which queries `base_authz_principal_capabilities` by `principal_type`), and is already labelled in the admin UI.

### Minting — per tenant and scope

| Connection scope | Actor `id` | Actor `tenantId` | Actor `companyId` |
| --- | --- | --- | --- |
| Company (`scope_key` carries a company) | connection primary key | connection `tenant_id` | connection `company_id` |
| Tenant (`company_id` null, `scope_key = tenant`) | connection primary key | connection `tenant_id` | **null** |

The `id` is the `provider_connections` primary key — stable, unique per tenant, and meaningful in audit records. It is not a user ID.

Tenant-scoped connections are first-class in `ProviderConnectionStore` and in the [1006] runner. For those, `ProviderPortAuthorization` already skips the company equality check when the scope has no company. The remaining hard fail is `Actor::validate()`, which today denies any actor with a null `companyId`.

**Platform change required on #70 (or a tiny Base Authz follow-up it lands with):** process-type principals (`PrincipalType::isProcess()` — `CONSOLE`, `SCHEDULER`, `QUEUE`) may omit `companyId` when the work is tenant-scoped. User and agent actors keep the existing company requirement. That is the honest reading of "minted per tenant and scope": company-scoped sync keeps a company on the actor; tenant-scoped sync does not invent a fake company.

Scheduled sync is **not** restricted to company-scoped connections.

### Capability grant

On connection activation, a connector **activation hook** (not a migration) inserts `PrincipalCapability` rows for `principal_type = scheduler` and `principal_id =` the connection id. On deactivation or deletion those rows are removed. No manual admin step.

The grant list is not a single permission. Bootstrap and incremental read every directory port the adapter declares. The activation hook therefore inserts one grant per **read** capability the adapter's capability matrix declares for the Employee Directory channel family used by sync — at minimum the ports the [1006] runner already exercises through `ProviderPortResolver`:

- `people-connector.provider.read.employee_directory`
- `people-connector.provider.read.company_directory`
- `people-connector.provider.read.organization_directory`
- `people-connector.provider.read.user_directory`
- `people-connector.provider.read.manager_hierarchy`

If an adapter declares a subset, insert only that subset. If it later declares more, reactivation (or an explicit re-sync of grants on activate) adds the new rows. Do not grant write ports, payroll, documents, or other non-directory capabilities from the scheduler principal.

Company-scoped grants carry `company_id` from the connection. Tenant-scoped grants leave `company_id` null so `EffectivePermissions` matches them the same way it matches other null-company grants.

`Actor::validate()` does not check `actingForUserId` for non-agent types, so the SCHEDULER actor needs no delegating user.

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

**Restrict scheduled sync to company-scoped connections only:** would leave tenant-wide connections unable to run on a schedule even though [1006] already supports them. Rejected in favour of process-type actors omitting company when the connection does.

**Invent a stand-in company id for tenant scope:** would lie to company-scope policies and audit. Rejected.

## Effect on connector #70

The `SyncScheduler` (artisan command + scheduler entry) mints the `Actor` from the connection record before calling `WorkforceSyncRunner::bootstrap()` / `::incremental()`. A connection activation hook inserts the directory-read capability grants listed above; deactivation removes them. `Actor::validate()` (or `ActorContextPolicy`) must accept process-type actors with a null company when the connection is tenant-scoped. The runner itself is unchanged.
