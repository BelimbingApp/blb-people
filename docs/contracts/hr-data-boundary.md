# The HR data boundary

**Document type:** Ownership and data-boundary contract
**Status:** Active
**Issue:** BelimbingApp/blb-people#21, under the `[1000]` master BelimbingApp/blb-people#20
**Companion:** `docs/contracts/company-ownership.md` in BelimbingApp/blb-people-connector, which
says how a query is scoped. This document says who owns the data being scoped.
**Last updated:** 2026-09-02

---

## What this document is for

Eleven issues sit behind this one. Each of them will have to answer some version of
"who owns this field, may we store it, and who may see it". If each answers on its own,
we get eleven answers.

So this is a decision record, not a design. It states rules. Where a rule needed a
decision I was not in a position to make, it says so by name instead of guessing — those
are collected in "Open questions" at the end, and each one is pointed at the issue that
should close it.

Three things are already decided elsewhere and are not reopened here:

- **How a query is scoped to a company.** Connector PR #10 shipped that: three ownership
  classes over fifteen tables, a global scope that throws when the company axis is
  missing, a `withoutCompanyScope($reason)` escape you can grep for, a contract test that
  discovers company-owned models, and a lint that fails the suite on an unreasoned bypass.
  Read `docs/contracts/company-ownership.md` in the connector repository for it. This
  document uses that mechanism and does not restate it.
- **The Skill Management System is connector-owned.** Settled by the amendment on
  BelimbingApp/blb-people#9 and by #20's ownership model.
- **The connector is a provider-neutral layer.** Feature code talks to a port, never to
  `blb-people` or HR2000 classes. That is #20's first architectural invariant.

---

## A note on the shape of the rules below

Two habits from this project's recent review history are used deliberately throughout,
because both were earned by finding real defects rather than by argument.

**A rule should decide on what it recognises, never on what it fails to recognise.** A
rule written as "allow only these" gets *stricter* when it meets a name it has never seen.
The same rule written as "allow anything except these" gets *looser* on the identical
unfamiliar name. On connector PR #10 that exact difference was the last bypass to be
found: one guard clause asked whether a table name was absent from a list, a
schema-qualified name was absent for the wrong reason, and a cross-company write went
through. Every rule below that could have been written as "everything except X" is
instead written as "only Y", and where I converted one, I say so.

**Check a rule against its neighbours, not only against attacks.** A rule that looks like
the ones around it borrows their credibility without necessarily sharing their properties.
That is how the bypass above survived two review rounds. Sections 2, 3 and 6 each contain
a finding that came out of this check rather than out of attacking anything.

---

## 1. Two things are called "company", and they are not the same thing

### The decision

There are two identifier spaces in this system, and both of them are spoken of as
"a company". From here on they have different names, and the names are normative.

**Platform company.** A row in the framework's `companies` table. This is the legal entity
a Belimbing user belongs to. It is what `users.company_id` points at, and what
`provider_connections.company_id` stores. It is created and administered inside Belimbing.
No HR provider knows it exists.

**Workforce company.** A row in `people_connector_connector_workforce_entities` whose
`resource_type` is `company`, together with its projection row in
`people_connector_connector_workforce_companies`. This is the connector's own stable id
for a company *as the HR provider describes it*. It is what every `company_entity_id`
column stores. It survives a provider being replaced; the provider's own id for that
company does not.

They are different in kind, not just in value. A platform company is a Belimbing fact. A
workforce company is an observation of somebody else's HR system. Nothing in the schema
converts one into the other — that missing link is section 2.

### Rule 1.1 — a company is referred to by exactly one of two spellings

A column, property, parameter or array key that carries a company identifier must be named
in one of exactly two ways:

- `company_id` — always and only a **platform** company id.
- `company_entity_id`, or `<role>_entity_id` for another workforce role — always and only
  a **workforce** entity id.

A third spelling is a contract violation, not an unclassified case. `hr_company_id`,
`company_ref`, `owning_company`, `company` as a bare integer: all refused at review.

This is the "only Y" form on purpose. The obvious way to write this rule is "anything
ending in `_entity_id` is a workforce id and anything else is a platform id", and that
version is what fails: it hands a confident-looking answer to a name nobody has thought
about yet. The version above has no opinion about an unfamiliar name except that it may
not be used, which is the behaviour we want from a naming rule that a linter will one day
enforce.

### Rule 1.2 — connector-owned data is keyed on the workforce company, never the platform company

Every connector-owned supplemental record — the whole Skill and Training lifecycle, and
anything added to it later — resolves to a workforce company: either directly, through its
own `company_entity_id`, or through a named parent that has one. That is the connector
contract's Class C and Class D. No connector-owned supplemental table may carry a platform
`company_id`.

Three reasons, in order of weight:

1. **Provider replacement has to keep the data.** #20 requires that replacing an HR
   provider preserves connector-owned history. Workforce entity ids are connector-issued
   and stable across connections, so a remap of external identities carries every skill
   record with it. Platform company ids would survive too, but they cannot express the
   case where the provider's idea of a company does not line up one-to-one with
   Belimbing's — and that mismatch is precisely what the connector exists to absorb.
2. **The provider must never need to know about platform companies.** An adapter is handed
   a workforce scope and returns provider records. If supplements were keyed on platform
   companies, every adapter would need the mapping in section 2, and a wrong or hostile
   provider payload could then select which platform company's data it wrote into.
3. **The mechanism is already there.** The connector guard is built on `company_entity_id`. A
   second axis with a second guard is a second thing to forget.

### Rule 1.3 — exactly one connector table may store a platform company id

That table is `people_connector_connector_provider_connections`, column `company_id`,
and section 2 adds the second. Everywhere else, a platform company id in the connector is
a bug.

This is checkable today and true today: `company_id` appears in exactly one connector
migration table, and every skill table uses `company_entity_id`. Keeping it checkable is
the point — a schema lint over the connector's migrations can assert it, and that belongs
with the naming lint under #24.

`provider_connections.company_id` is not an exception to rule 1.2, because a connection is
not a supplemental record. It is an installation fact: it says which platform company an
integration was *configured for*. That distinction matters in the next section, because it
is the exact thing the current code mistakes for ownership.

### Rule 1.4 — the words in prose

In issues, comments, commit messages and documentation, write "platform company" or
"workforce company". Unqualified "company" is only acceptable where the whole surrounding
paragraph is about one of them and says which. This costs one word and removes the
condition that produced three separately reproduced isolation failures.

---

## 2. Company attribution: which platform company a workforce company belongs to

This is the decision the issue was reassigned for, and it is the one this document exists
to make.

### What is broken today

`Connector/Services/CompanyAttribution` answers "which workforce companies may this user
act for". It walks a chain: company projection → its source external identity → that
identity's provider connection → `provider_connections.company_id` → compare with the
actor's own platform company.

That chain answers a different question than the one being asked. It resolves *which
platform company the connection was configured for*, and then treats the answer as if it
were *which platform company this workforce company belongs to*. Those coincide only when
a connection serves exactly one platform company.

`provider_connections.company_id` is nullable specifically so that it need not — one
HR2000 install serving five companies is the normal SBG shape, not an edge case. When the
connection is tenant-scoped the chain yields null, and the service correctly refuses to
guess: an unattributable workforce company is offered to nobody.

That is the right behaviour and the wrong resting place. Fail-closed means the multi-company
tenant with one HR install — the deployment we are actually building for — cannot use the
connector at all. Something has to be decided, and only this issue can decide it.

This is the "check a rule against its neighbours" case. The derived chain looks exactly
like the other identity chains in the connector: projection → identity → connection is how
provenance, snapshots and reconciliation all resolve, and every one of those is correct,
because every one of those really is asking about the connection. Attribution borrowed a
shape that was right four times and wrong the fifth, and nothing about reading the code
makes the fifth stand out.

### Decision 2.1 — attribution is a stored fact, not a derived one

**A workforce company is attributed to a platform company by a stored, administered link.
The link is not inferred from the connection, from names, from codes, or from anything
else.**

Nullable, because a workforce company that has just arrived from a sync has not been
attributed yet and there is no honest value to put there.

### Decision 2.2 — at most one platform company per workforce company

A workforce company attributes to **at most one** platform company. A platform company may
have several workforce companies attributed to it.

The asymmetry is deliberate and it is the whole safety argument. Many workforce companies
pointing at one platform company is a real situation — a provider that models payroll
entities separately from the legal entity Belimbing recognises — and it widens nobody's
access, because everyone who can see any of them could already see the platform company.
One workforce company pointing at two platform companies is the opposite: it would mean
one HR company's employees, skills and assessments are visible to two separate legal
entities. Refuse it.

That cardinality is also what makes the storage shape a nullable column rather than a
pivot table, which settles the question `agent:opus-5-contract-a` left open.

### Decision 2.3 — attribution is written by an administrator, never by an adapter

The only write path is a deliberate administrative action inside Belimbing. No adapter,
no sync run, no provider payload and no automatic matcher may set or change it.

An adapter that could set attribution would be an adapter that could choose which platform
company's data it writes into. Adapter code is the least trusted code in the connector: for
HR2000 it will be talking to a system whose integration surface we have not yet verified.
Rule 1.2 keeps platform company ids out of the adapter's reach; this rule keeps them out of
its *influence*.

Confirming a mapping is a reviewable act with an actor and a timestamp. The action is
governed under #24, and which role may perform it is #25's to name — see the open
questions.

### Decision 2.4 — a projection row now has two kinds of field, and sync may only touch one

This is new and it generalises past attribution, so it is stated as its own rule.

- **Projected fields** come from the provider. Sync overwrites them on every run. On the
  company projection these are `name`, `code`, `active`, `effective_at`, `observed_at`,
  `source_version`.
- **Administered fields** come from Belimbing. Sync must never write them, and a provider
  replacement must not clear them. Attribution is the first one.

**Rule:** any write path that updates a projection row must name the columns it writes.
Replacing a whole row from a provider payload — building an attribute array from the
payload and handing it to `updateOrCreate` or `fill` — is refused for any table that holds
an administered field, because it silently erases every field the author did not think
about.

A mixed-provenance row is a genuine hazard and I would rather name it than pretend the
column is free. The alternative shape — a separate one-row-per-company attribution table,
keeping the projection purely provider-owned — was considered and rejected because the
company projection is already unique on `(tenant_id, workforce_entity_id)`, so the side
table would be one-to-one with it, and a one-to-one table is a column wearing a disguise.
The cost of the column is this rule. The cost of the side table would have been a join on
the hot path of every attribution check, plus another table to classify and guard.

### Decision 2.5 — no attribution means no access, and that is not a gap

An unattributed workforce company is offered to nobody. This is exactly what
`CompanyAttribution` does today, so nothing regresses while the stored link is being built,
and there is no window during which behaviour is looser than it is now.

Absence of attribution is a state to be resolved by an administrator, not a condition for
feature code to work around. Feature code must never fall back to "the tenant's only
company", "the connection's company", or "the actor's own company" when attribution is
missing.

### Decision 2.6 — attribution is necessary, not sufficient

Attribution narrows the set of workforce companies a user could possibly act for. It does
not by itself say the user may act for them.

Concretely: "workforce company W is attributed to platform company P" does **not** mean
every user of P may read W's employees, skills or assessments. It means no user outside P
may. The permission that decides within P is an access-control question and it belongs to
#25 and to section 7 of this document.

Keeping these two apart is the same distinction connector PR #10 drew between scoping and
authorization, and for the same reason: an author who reads a passing attribution check as
a granted permission will build the second half of an exploit out of the first half of a
guard.

### The schema change this implies

Named here so #26 can implement it without re-deciding anything, and flagged as a schema
change rather than snuck in:

```
people_connector_connector_workforce_companies
  + company_id  unsigned big integer, nullable
  + foreign key (company_id, tenant_id) -> companies (id, tenant_id), restrict on delete
  + index (tenant_id, company_id)
```

The composite foreign key is not decoration. It is what makes it impossible to attribute a
workforce company to a platform company in another tenant, which is the same trick
`provider_connections` already uses and the same reason it uses it.

`restrict on delete` has a consequence worth stating out loud: once a workforce company is
attributed, the platform company cannot be force-deleted until an administrator
un-attributes it. That is the behaviour we want. It converts a silent orphaning into a
refusal with a message.

**These tables are incubating schema**, so this lands as an edit to the original
`create_*` migration under the project's schema-incubation policy, not as a follow-up
`ALTER`. Implementation and migration mechanics belong to #26.

### A finding: the single-company carve-out has one way to fail open

`CompanyAttribution` keeps one carve-out. If a tenant has only ever held one platform
company, every workforce company in it is attributable, because there is no second company
to leak to. The count deliberately includes soft-deleted companies, so archiving a second
company does not reopen it.

I checked this rule the way the method note above says to, and the first result is that it
is *fine* in the shape that matters. It is an inclusion test: it grants only when it
positively counts exactly one company. If a second company appears, the count becomes two and everything
fails closed. An unfamiliar state makes it stricter, not looser. It does not need rewriting
and it should stay until the stored link makes it dead code.

The second result is a real hole, and it comes from the neighbour check rather than from
the shape. The carve-out counts rows in `companies`, which is Core's table under Core's
deletion rules — and `Company::forceDelete()` is a public method whose only guard is that
you may not hard-delete the tenant's *primary* company. So:

1. A tenant has two platform companies and one tenant-scoped HR connection.
2. Nothing in the connector references the second platform company, because a tenant-scoped
   connection stores `company_id = null`, so no foreign key restrains the delete.
3. An administrator force-deletes the second platform company. It succeeds — it is not the
   primary company.
4. `withTrashed()->count()` now returns 1. The carve-out reopens.
5. Every workforce company in the tenant, including the deleted company's, is now offered
   to the surviving company's users — and the deleted company's employees, skills and
   assessments are all still in the connector, because deleting a platform company deletes
   none of them.

The carve-out inherited Core's soft-delete discipline without inheriting the fact that Core
also supports hard deletion for any non-primary company. That is the neighbour problem
exactly: `withTrashed()` is the careful spelling, it looks like the careful spelling, and
it is defeated by a method in a module this one does not own.

**Rule:** while the carve-out exists, it is documented as depending on platform companies
never being hard-deleted, and that dependency is stated in `CompanyAttribution` itself.
Once stored attribution lands, the carve-out is deleted rather than kept as a fallback —
with the link in place, a tenant with one company simply has every workforce company
attributed to it, and the special case has nothing left to do. Tracked as an open question
below, because whether Core should refuse to hard-delete a company that has connector data
is Core's decision, not this contract's.

---

## 3. Being able to reach data is not the same as owning it

### The problem, found by reading the enum against itself

`PeopleCapability` lists twelve capabilities: company directory, employee directory,
organization directory, manager hierarchy, user directory, payroll, attendance, leave,
claims, training, documents, single sign-on.

Eleven of those are things an HR provider owns. One is not. #20's scope amendment says the
entire training lifecycle is **connector-owned** for SBG — needs, requests, approvals,
events, attendance, results, certificates, evaluations, effectiveness reviews, passports —
and that any training data HR2000 holds is an import source with provenance, not a second
live writer.

So an adapter that truthfully declares `Training: read_write` is telling the truth about
what it can do, and feature code that reads that declaration as "the provider owns
training" would be wrong. `Training` sits in a list where every neighbour means "the
provider owns this", and it inherits that reading for free. Nothing in the type system
stops it.

Note that `PeopleCapability` has no `Skills` case, and should not gain one. That is
correct and it is the model to follow: a connector-owned capability is not something a
provider declares.

### Rule 3.1 — a capability declaration states reachability, never authority

A `CapabilityDeclaration` answers: can this provider serve this data, in which direction,
over which channel. It never answers: may we treat this as the truth, and may we write to
it. Authority is fixed by this contract's ownership register, per deployment, in one place.

### Rule 3.2 — ownership is consulted before capability

Before any provider read whose result will be **stored**, and before any provider **write**,
feature code asks the ownership register who owns the data class. Only then does it ask the
capability set whether the owner's provider can serve it.

- If the register says the connector owns it, the provider is not written to, whatever it
  declares. A provider read of the same class is an **import**, and every imported record
  carries provenance saying which connection and which run produced it.
- If the register says the provider owns it, a declared write channel may be used and a
  connector-side copy is a projection, subject to section 6.

A capability alone is never authority to write. That sentence is the rule; the rest of this
section is why it needed writing down.

### Rule 3.3 — feature code asks what is supported, never what is unsupported

Always `canRead()` / `canWrite()`. Never "is this capability not declared as unsupported".

`CapabilitySet::direction()` already returns `None` for a capability that was never
declared, which is the inclusion form and is correct: an adapter that forgets to declare
something is treated as not supporting it, and a capability name this build has never heard
of is treated the same way. Nothing should be added that turns that into an exclusion test —
in particular, no "assume read is available unless the adapter says otherwise" default, and
no capability list built by subtracting the unsupported ones from the enum.

This matters most for HR2000, where nothing is verified yet. An unverified adapter should
declare nothing and therefore be able to do nothing, and today it is.

---

## 4. The ownership register

Who is authoritative, what the connector may keep, which way data flows, and how fresh it
has to be. This table is the register referred to in rule 3.2. A deployment may override a
row, but only by an explicit recorded decision — the default is what is written here.

| Capability | Authoritative owner | Connector may store | Direction | Freshness requirement |
|---|---|---|---|---|
| Company directory | Provider | Projection, section 6 list only | Provider → connector | Best effort; staleness must be visible |
| Organization directory | Provider | Projection, section 6 list only | Provider → connector | Best effort; staleness must be visible |
| Employee directory | Provider | Projection, section 6 list only | Provider → connector | Best effort; staleness must be visible |
| Manager hierarchy | Provider | Entity references only | Provider → connector | Best effort; staleness must be visible |
| User directory | Provider, subject to #25 | Entity references only | Provider → connector | Best effort |
| Payroll | Provider | **Nothing** | Read-through only | Live at point of use |
| Attendance | Provider | **Nothing** | Read-through only | Live at point of use |
| Leave | Provider | **Nothing** | Read-through only | Live at point of use |
| Claims | Provider | **Nothing** | Read-through only | Live at point of use |
| Documents | Provider | Reference and metadata only, never content | Read-through only | Live at point of use |
| Training | **Connector** | Everything; it is the system of record | Provider → connector as import only | n/a |
| Single sign-on | Platform identity layer, #25 | n/a | n/a | n/a |
| Skills and assessments (not a provider capability) | **Connector** | Everything; it is the system of record | n/a | n/a |

"Nothing" in the third column means exactly that: no table, no cache, no snapshot, no
denormalised summary field. If a screen needs an employee's leave balance it calls the
provider when the screen is drawn, and if the provider is down the screen says so.

The reasoning is #20's invariant that sensitive HR content is not copied merely because an
adapter can reach it, and it is a much easier rule to hold when it is "nothing" than when
it is "as little as possible". A reviewer can check "nothing".

---

## 5. Connector-owned supplemental capabilities

The rules that apply to anything the connector owns. Today that is Skills and Training;
these are written to be true of the next one too.

**5.1 — A connector-owned capability works with no provider support at all.** The provider
supplies workforce and organisation context. It does not supply the capability, and the
capability does not degrade when the provider has no matching feature. This is the test
that decides whether something is genuinely connector-owned or is really a provider
capability with a connector-side cache.

**5.2 — Connector-owned records reference the provider only through workforce entity ids.**
Never a provider's own id, never a name, never an email. External ids live in
`external_identities` and nowhere else, which is what allows a provider swap to be a remap.

**5.3 — Every table belonging to a connector-owned capability is company-owned on the
workforce axis** (rule 1.2), and carries the `CompanyOwned` trait so the connector's company
guard applies — directly for Class C, through its named parent for Class D. A new table
enrols by adding the trait; the discovery contract test then covers it automatically. This
is about capability data, not about the connector's own plumbing:
`provider_connections` and `workforce_entities` are deliberately tenant-wide, for the
reasons the connector contract gives.

**5.4 — Provider data that overlaps a connector-owned capability is an import, with
provenance, and is never a live second writer.** HR2000's historical training records come
in as an import that records where each record came from and when. After import, the
connector is the only writer. Reversing this for a particular deployment requires an
explicit recorded decision under #28, not an adapter declaring a write channel.

**5.5 — A provider outage never blocks a connector-owned write.** Skills and training are
the system of record; they do not need the provider to be reachable. If an operation needs
fresh provider context to be correct, it fails with that reason rather than proceeding on
a stale projection and rather than being silently queued.

---

## 6. What the connector may store: the permitted projection

### Rule 6.1 — a field may be stored only if it is listed here

The connector may persist a provider field only if this section names it. A field that is
not named is not storable, whatever the adapter returns and however convenient it is.

This is the second place I converted an "everything except" rule into an "only these". The
natural phrasing is #20's own — "provider data is projected minimally; sensitive
payroll/HR content is not copied merely because an adapter can access it" — and as a
principle it is right, but as a rule it fails open. It requires each author to correctly
recognise a field as sensitive, in a domain where the sensitivity of a field is sometimes a
matter of local law. An allowlist requires them to recognise nothing: a field they have
never considered is simply not on the list.

### The list

**Workforce company** — `name`, `code`, `active`, `effective_at`, `observed_at`,
`source_version`, plus the connector's own keys and the administered `company_id` from
section 2.

**Workforce organization unit** — `name`, `code`, `kind`, `active`, `parent_entity_id`,
`company_entity_id`, `effective_at`, `observed_at`, `source_version`.

**Workforce position** — `name`, `code`, `tier`, `active`, `organization_entity_id`,
`company_entity_id`, `effective_at`, `observed_at`, `source_version`.

**Workforce employee** — `display_name`, `employee_number`, `email`, `active`,
`company_entity_id`, `user_entity_id`, `organization_entity_id`, `position_entity_id`,
`manager_entity_id`, `department_head_entity_id`, `effective_at`, `observed_at`,
`source_version`.

That is the whole list. It is deliberately identical to the columns that exist today, so
this rule adds no data and forbids the next addition from arriving without a decision.

Note what is **not** on it and would be easy to add without thinking: salary, grade, bank
details, national identity number, date of birth, home address, personal phone number,
marital status, dependants, medical or disability information, disciplinary history,
termination reason, leave balances, attendance records. None of these may be projected.
Several of them will be visible to an adapter, because they sit in the same provider record
as `display_name`.

Adding a field to this list is a change to this document and needs the reason written next
to it.

### Rule 6.2 — the allowlist applies to raw snapshots too

`workforce_snapshots` stores the raw provider payload as JSON, append-only. It is the one
place where every rule in this section can be bypassed wholesale, and because the table is
append-only, a mistake there cannot be fixed by an update — only by deleting history.

The resource *type* is already constrained, and correctly: `WorkforceResourceType` has five
cases and there is no payroll or leave among them, so a payroll record cannot become a
snapshot. That is an inclusion list and it does its job.

The *fields inside the payload* are not constrained by anything. An adapter that fetches an
employee record from HR2000 and snapshots the response verbatim would persist the salary
that arrived in the same JSON object, permanently, in a table nobody thinks of as holding
compensation data.

**Rule:** a snapshot payload contains only fields on the list in 6.1, for the resource type
being snapshotted. An adapter filters before it hands the payload over; it does not hand
over a provider response and let something downstream trim it.

This rule is currently unenforced. Enforcing it — a filter at the snapshot write path
rather than a convention in each adapter — belongs to #24. The reason it should be a filter
rather than a convention is the same reason connector PR #10 built a guard instead of
writing a style guide: three authors already followed the convention.

### Rule 6.3 — read-through data is not stored anywhere, including in a cache

For every capability whose "connector may store" column says nothing: no cache table, no
Redis entry keyed by employee, no session copy, no debug log line containing the response,
no snapshot. If it needs to be shown twice on one page, it is fetched once and held for the
duration of that request.

---

## 7. Sensitivity, access, and what co-location actually protects

### Data classes

| Class | Contents | Rule |
|---|---|---|
| **Directory** | Names, employee numbers, work email, active flag, org unit, position, manager | Visible to authenticated users of the attributed platform company |
| **Employment** | Employment dates, department head, position history | Employee themselves, their management chain, HR |
| **Compensation** | Payroll of any kind, bank details, claims amounts | Never projected. Read-through, provider-authorised, payroll role only |
| **Absence** | Leave and attendance records | Never projected. Read-through, employee, their manager, HR |
| **Competence** | Skills, assessments, results, certificates, training history — connector-owned | Employee themselves, their management chain, HOD for their department, HR |
| **Documents** | Provider-held files | Never projected. Reference only; content is fetched from the provider under the provider's own authorisation |

Competence data is performance data. It is more sensitive than the directory and it is
owned by us, which means we cannot borrow the provider's access rules for it — we have to
write our own. That is #25's work; the classes in the table above are what it grants
against.

### Rule 7.1 — HR access is granted, never inherited

A role holds access to a provider or connector-owned HR capability only if it has been
granted a permission that names that capability. No platform-administration role acquires
it by being administrative: not tenant owner, not company administrator, not the role that
configures provider connections, not a developer or support role.

Stated as an inclusion test on purpose. The tempting form is "administrative roles get
everything except HR", and that form loses the moment somebody adds a new administrative
role and does not think about the exception.

Configuring a provider connection is a genuine administrative task and it does not require
reading a single employee record. Those are two different permissions and they must not be
bundled.

### Rule 7.2 — break-glass is time-boxed, logged, and never silent

Emergency access to HR data is granted for a stated reason, expires on its own, is written
to an audit record that the emergency access itself cannot edit, and notifies the HR
governor. An access path that has none of these is not break-glass, it is a back door with
a nicer name.

### Rule 7.3 — say plainly what co-location does not protect against

Running the connector alongside the provider on one host, or in one container, or in
separate repositories, is not a security boundary against anyone with host access. A host
administrator can read both databases, both configuration files and every credential either
holds. #20 already lists claiming otherwise as a non-goal; this is where the claim is
retired for good.

What placement *does* change is which credentials exist and who holds them, and that is
worth doing well: the remote placement needs a short-lived, audience-bound service
credential (#25), and the co-located placement should not be allowed to skip authorisation
just because the call never leaves the process.

**Rule:** the in-process adapter enforces the same authorisation checks as the remote one.
The transport is the only difference between the two placements. #20's invariant says the
same thing about interfaces and tests; this extends it to authorisation, because "it is a
local method call, so the caller is already trusted" is exactly how the check gets dropped.

---

## 8. Identity

Five identifier spaces, kept separate. Nothing may be silently converted between them.

| Space | Where it lives | Issued by | Stable across |
|---|---|---|---|
| Tenant | `tenants.id` | Platform | Everything |
| Platform company | `companies.id` | Platform | Everything |
| Platform user | `users.id` | Platform | Everything |
| Workforce entity | `workforce_entities.id` | Connector | Connections, provider replacement |
| Provider external id | `external_identities.external_id` | Provider | Nothing outside its connection |

**Rule 8.1 — a provider's own id is stored in exactly one table.** `external_identities`,
keyed by connection, provider, resource type and external id. A provider id appearing on a
projection row or a connector-owned record is a bug, for the same reason as rule 1.3: it
would pin data to a provider we intend to be able to replace.

**Rule 8.2 — never match on a mutable attribute.** No adapter resolves identity by name,
email, or any other value a person can change. This is #20's invariant and it is restated
here because it is the rule that decides what happens when an adapter *cannot* supply a
stable id. That case is genuinely undecided — whether to fail the whole sync or to omit the
record and report it — and it is already filed as #44. This contract does not pre-empt it;
it only forbids the third option of guessing.

**Rule 8.3 — the employee-to-platform-user link is #25's, not this document's.**
`workforce_employees.user_entity_id` points at a workforce entity of type `user`. Whether
that entity resolves to a Belimbing `users.id`, and who is allowed to assert that it does,
is an identity and SSO decision. It is named here so that #26 does not quietly invent one
while building projections.

---

## 9. Freshness, outage, and stale data

**Rule 9.1 — absence is not deletion.** The connector never deactivates or deletes a
projection because a provider stopped mentioning a record. Deactivation requires a positive
statement from the provider that the record is gone, or an administrator's action. A
partial sync, a filtered response, a permissions change on the provider side and an outage
all look identical to "not mentioned", and three of those four must not destroy data.

This is the same inclusion-versus-exclusion choice as everywhere else in this document.
"Delete what the provider no longer lists" decides on what it fails to see. "Deactivate
what the provider says is gone" decides on what it recognises.

**Rule 9.2 — staleness is visible, never hidden.** Every projection row already carries
`observed_at`. Any screen showing projected data can say how old it is, and when a provider
is unreachable the screen says the data is stale rather than presenting it as current.
Hiding the data entirely is also wrong: it turns an outage into an apparent deletion, which
is rule 9.1 wearing a different hat.

**Rule 9.3 — a provider outage degrades provider capabilities only.** Connector-owned work
continues. Read-through capabilities fail with a clear reason. Projections continue to be
served, marked stale.

**Rule 9.4 — a write whose outcome is unknown is not retried blindly.** The connector
already has `ProviderUnknownOutcomeException` for this. An unknown outcome is reconciled
against the provider before it is retried, because a duplicated payroll or leave write is
worse than a failed one.

---

## 10. Provider replacement

The whole point of keying supplements on workforce entities (rule 1.2) is that this stays
possible.

Replacing a provider means: a new connection, a new set of external identities, and an
administered remap from the old workforce entities to the new provider's records. Every
connector-owned record follows automatically because it references the entity, not the
provider.

**Rule 10.1 — the remap is administered and reviewable.** Same reasoning as decision 2.3:
an automatic matcher deciding that HR2000's employee 4471 is the same person as the native
provider's employee 88 is a matching-on-mutable-attributes rule wearing a migration
costume.

**Rule 10.2 — attribution survives replacement.** It is an administered field (decision
2.4), so a new provider does not clear it. The workforce company keeps its platform company
even though everything the provider said about it has been replaced.

**Rule 10.3 — cutover is reversible within a stated window.** #31 owns the mechanics. The
constraint this contract places on it is that connector-owned history is never rewritten
during a cutover, only re-pointed.

---

## 11. Open questions, named and left

Each of these needed a decision I was not in a position to make. They are listed rather
than guessed, and each names who should close it.

1. **Does attribution grant access, or only permit it?** Decision 2.6 says attribution is
   necessary and not sufficient, which means somebody still has to say which users of a
   platform company may act for its workforce companies, and at what granularity — company,
   department, or explicit assignment. **#25.**

2. **Who may confirm an attribution?** Decision 2.3 says an administrator, deliberately.
   #20 names HR as system governor, which suggests HR rather than a platform administrator,
   but that is a role definition and it interacts with rule 7.1. **#25**, with the
   governance surface in **#24.**

3. **Should Core refuse to hard-delete a platform company that has connector data?** The
   fail-open path in section 2 is closed for attributed companies by the foreign key, but
   an *unattributed* tenant-scoped deployment has no such restraint. Whether Core grows a
   general "this company is referenced by domain data" guard is Core's decision and it
   affects modules beyond this one. **Core, via a new issue** — this contract only records
   the dependency.

4. **Everything about HR2000 is unverified.** The product edition, hosting mode, enabled
   modules, vendor support arrangement and integration rights are all still unknown, and
   this document deliberately contains no HR2000 capability matrix, because writing one
   from marketing material would be inventing evidence. The contract is built so that an
   unverified provider declares nothing and can therefore do nothing (rule 3.3), which is
   the correct behaviour to hold until discovery lands. **#28.**

5. **The rule for a record the adapter cannot give a stable id to.** Fail the sync, or omit
   the record and report it. Already filed as **#44**; rule 8.2 only forbids guessing.

6. **Should `provider_connections` be readable by a company administrator?** Today it is
   tenant-wide and tenant-administered, which is safe and may be too coarse for a tenant
   whose companies each run their own HR install. Carried over from
   `company-ownership.md`'s own open list. **#24.**

7. **Merge and deactivate authority on `workforce_entities`.** Also carried over. It needed
   the attribution answer first, and now has one — a merge should be performed with the
   authority of the platform company that both entities attribute to, and refused when they
   attribute differently — but turning that into an enforced rule is a change to
   `WorkforceIdentityStore` and belongs with **#26**, not in a document.

---

## 12. How this contract is checked

A contract nobody can test is a preference. What exists today, and what should exist:

**Exists.** The connector's company guard, its discovery contract test and its bypass lint.
`CapabilitySet` returning `None` for undeclared capabilities. `ProviderConformance`, which
resolves every declared port and fails an adapter that declares one it cannot supply.
`CompanyAttribution` failing closed.

**Should exist, and where it belongs.**

- A schema lint asserting rules 1.1 and 1.3: only two company-column spellings, and
  `company_id` on exactly the two tables named. **#24.**
- A test that the projection write path never writes an administered column, per decision
  2.4. **#26.**
- A filter at the snapshot write path enforcing rule 6.2, and a test that a payload
  carrying an unlisted field is rejected rather than trimmed silently. **#24.**
- A test that the in-process and remote adapters refuse the same unauthorised call, per
  rule 7.3. **#30.**
- A test that a provider omitting a record does not deactivate its projection, per rule
  9.1. **#29.**

The pattern in each case is the connector guard's: the test discovers what it covers rather than being
copied per slice, so a future capability enrols by declaring itself rather than by somebody
remembering.
