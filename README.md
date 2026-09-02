# BLB People

People (HR) domain for the [Belimbing (BLB)](https://github.com/BelimbingApp/belimbing) framework: Attendance, Leave, Claim, Payroll, Benefits, Performance, Recruitment, Training, the Employees workbench, and People Settings.

The `people/provider` Module publishes a tenant-scoped, transport-neutral workforce bootstrap seam for the first-party People connector adapter. It deliberately has no HTTP route yet: remote access remains closed until the service-authentication and authorization work tracked by `BelimbingApp/blb-people#25` is available. Co-located and future authenticated remote adapters must delegate to the same People-owned service rather than read native tables directly.

Ownership between the HR provider and the connector — which system is authoritative for which data, what the connector is allowed to store, and how the two different things called "company" are told apart — is decided in [`docs/contracts/hr-data-boundary.md`](docs/contracts/hr-data-boundary.md).

This repository is a **nested-git domain repo**. It mounts at `app/Domains/People/` inside a Belimbing checkout; the framework discovers its providers, migrations, menus, routes, settings, and tests by path convention — no registration step. See `docs/architecture/module-system.md` in the main repo.

```bash
git clone https://github.com/BelimbingApp/belimbing
git clone https://github.com/BelimbingApp/blb-people belimbing/app/Domains/People
```

Licensed under MIT, same as the framework.
