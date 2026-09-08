# BLB People

People (HR) domain for the [Belimbing (BLB)](https://github.com/BelimbingApp/belimbing) framework: Attendance, Leave, Claim, Payroll, Benefits, Performance, Recruitment, Training, the Employees workbench, and People Settings.

The `people/provider` Module publishes a tenant-scoped, transport-neutral workforce bootstrap seam for the first-party People connector adapter. The canonical provider-neutral SDK and common adapter conformance runner are owned by [`BelimbingApp/blb-people-connector`](https://github.com/BelimbingApp/blb-people-connector/tree/main/Connector); this Domain does not import that optional consumer. It deliberately has no HTTP route yet: remote access remains closed until the service-authentication and authorization work tracked by `BelimbingApp/blb-people#25` is available. Co-located and future authenticated remote adapters must delegate to the same People-owned service rather than read native tables directly.

Ownership between the HR provider and the connector — which system is authoritative for which data, what the connector is allowed to store, and how the two different things called "company" are told apart — is decided in [`docs/contracts/hr-data-boundary.md`](docs/contracts/hr-data-boundary.md).

This repository is a **nested-git domain repo**. It mounts at `app/Domains/People/` inside a Belimbing checkout; the framework discovers its providers, migrations, menus, routes, settings, and tests by path convention — no registration step. See `docs/architecture/module-system.md` in the main repo.

```bash
git clone https://github.com/BelimbingApp/belimbing
git clone https://github.com/BelimbingApp/blb-people belimbing/app/Domains/People
```

Licensed under MIT, same as the framework.

## Local Training examples

`Training/Database/Seeders/Dev/DevTrainingSeeder.php` is discovered by the platform's
normal `php artisan migrate --dev` flow when People is enabled. It runs only with
`APP_ENV=local`, after the migrations, production reference seeds and operator
company primitives. Following a disposable database reset, that development flow
recreates the examples. Plain production migrations/startup do not run this seeder.

To add missing examples to an already migrated local database without rebuilding
tables, run from the **platform checkout root**:

```bash
php artisan db:seed --class='App\Domains\People\Training\Database\Seeders\Dev\DevTrainingSeeder'
```

The operator's primary company receives three active courses: **DEMO - Workplace
Safety Induction** (internal classroom), **DEMO - Excel Fundamentals** (e-learning),
and **DEMO - Customer Service Essentials** (coaching). They share one demo skill
category and each covers one demo skill. A synthetic employee, **DEMO - Training
Coordinator**, organizes a two-hour, twelve-place session for each course, seven
to nine days after its initial seed at 09:00 company time. Schedule/calendar users
can inspect these sessions without adding real participants.

The company-scoped `demo-*` codes and `DEMO-TRAINING-ORGANIZER` employee number are
reserved for these examples. Existing records with these identities are reused;
edits, deactivation and existing event dates/statuses are preserved. A course with
any existing event does not receive another on rerun, even if its event is now in
the past. Reschedule through the normal workflow when needed. The seeder also
adopts the same three courses created during the initial local walkthrough.

No login, role grant, employee-account link, participant, completion history or
notification is created. Access still requires an explicitly assigned People
role in the relevant company; core administrator alone is not HR authority.
The seed is for catalog and delivery walkthroughs. Request approvals and budget
examples are not seeded: they need a deliberate department/requestor/approver
scenario, not an invented approval history or automatic HR permission grant.
