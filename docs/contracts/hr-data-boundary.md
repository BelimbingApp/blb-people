# The HR data boundary

**Document type:** Ownership and data-boundary contract
**Status:** Rebaselined 2026-09-05 to the approved ownership in [plan 0001](../plans/0001-people-architecture-and-provider-boundaries.md); enforcement awaits re-audit.
**Issue:** BelimbingApp/blb-people#21, under the `[1000]` master BelimbingApp/blb-people#20
**Companion:** `docs/contracts/company-ownership.md` in BelimbingApp/blb-people-connector, which
says how a query is scoped. This document says who owns the data being scoped.
**Last updated:** 2026-09-05

---

## Governing ownership and implementation status

**People owns Skills, Training and Progression business records.** The connector owns
identities, projections, checkpoints, reconciliation issues and provider connections.
Business workflows and history belong to the selected authoritative People installation;
provider capability declarations do not transfer that ownership to the connector.

The 2026-09-05 ownership decision supersedes the earlier amendments cited by this contract.
[Plan 0001](../plans/0001-people-architecture-and-provider-boundaries.md) governs the boundary;
[plan 0008](../plans/0008-people-existing-work-and-backlog-reconciliation.md) governs preservation
and reconciliation of prior work. Implementation is tracked by
[R1: workforce subject seam](https://github.com/BelimbingApp/blb-people/issues/112),
[R2: Skills relocation](https://github.com/BelimbingApp/blb-people/issues/113),
[R3: Training relocation](https://github.com/BelimbingApp/blb-people/issues/114), and
[R4: integration-only connector](https://github.com/BelimbingApp/blb-people-connector/issues/121).
The existing connector copies are relocation sources, not a second business authority.

This amendment changes documentation only. Statements below about what code does "today",
counts, findings and issue implementation status retain the prior 2026-09-02 evidence;
they are not a fresh enforcement certification. Preserve the identity and security rules
during relocation. The checklist at the end records the claims requiring a later code audit.

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
- **People owns Skills, Training and Progression.** The approved boundary in plan 0001
  supersedes the earlier ownership amendments on BelimbingApp/blb-people#9 and #20.
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

Two of the five identifier spaces in section 8 are spoken of as "a company", and they are
different things. From here on they have different names, and the names are normative. A
third space — the provider's own external reference — can also name a company, and rule 1.1
gives it a spelling of its own so that it is not mistaken for either.

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

### Rule 1.1 — a company is referred to by exactly one of three spellings

Section 8 names five identifier spaces, and three of them can identify a company: the
platform company, the workforce entity, and the provider's own external reference. Each has
exactly one sanctioned spelling.

| Spelling | Means | Example |
|---|---|---|
| `company_id` | A **platform** company id | `provider_connections.company_id` |
| `company_entity_id`, or `<role>_entity_id` | A **workforce** entity id | `workforce_employees.company_entity_id` |
| `<role>Reference` in PHP, `<role>_reference` as a payload key | A **provider external reference** — an `ExternalReference` value carrying provider id, resource type and external id together, never a bare id | `WorkforceEmployee::$companyReference` |

A fourth spelling is a contract violation, not an unclassified case. `hr_company_id`,
`company_ref`, `owning_company`, `company` as a bare integer: all refused at review.

This is the "only Y" form on purpose. The obvious way to write this rule is "anything
ending in `_entity_id` is a workforce id and anything else is a platform id", and that
version is what fails: it hands a confident-looking answer to a name nobody has thought
about yet. The version above has no opinion about an unfamiliar name except that it may
not be used, which is the behaviour we want from a naming rule that a linter will one day
enforce.

**The third row was missing from the first version of this rule, and its absence made the
rule condemn correct code.** `ExternalReference $companyReference` is the property every
adapter DTO carries, in both repositories, and `'company_reference'` is the key every
projection snapshot writes. Those are the right names for what they hold — a provider
reference is not a company id and must not be spelled like one — but under a two-row rule
they were third spellings. A rule that forbids the correct name and offers no replacement
gets ignored, and an ignored naming rule is worse than none.

**What can actually be checked, and what cannot.** The schema lint proposed in section 12
reads migrations, so it covers the column half of row one and row two and nothing else.
That half is true today: every company-carrying column in the connector is `company_id` or
`company_entity_id`, with no exceptions. Properties, parameters and payload keys are a
review rule until something can read them, and this document should not pretend otherwise.

**No known violations, as of `e54e2f0`.** The count covers **persisted names**: schema
columns, and keys written into stored JSON. It does not cover local variables, and the rule's
own wording is looser than that count, which is the gap recorded below.

There was one when this rule was written. `Employees/Livewire/Index.php` wrote the array key
`'scope_company_id'` into the persisted `metadata` JSON of a saved employee view, resolved
from `Auth::user()->getCompanyId()` — a platform company id under a fourth spelling, on a row
that already carried a real `company_id` column set from the same call, so a duplicate as well
as a misspelling. It was filed as #64 and removed by #65, together with a sweep confirming no
other third-spelling company identifier in this repository. So the rule found a real defect
and the defect was gone before the rule landed, which is the outcome to want.

The connector's `ExternalReference $companyReference` and `'company_reference'` surface is
**not** a violation. It is row three, which this rule gained precisely because the two-row
version condemned it.

**A known gap in the rule itself, for whoever writes the lint.** Rows two and three permit a
role prefix — `manager_entity_id`, `companyReference` — and row one does not, so
`$platformCompanyId` and `$actorCompanyId` are literal violations of a rule nobody intends to
break. Either row one gains the same prefix freedom or the rule scopes itself to persisted
names, which is what the count above already does. Deciding that belongs with the lint, in
**#24**, not here — a naming rule and the thing that enforces it should be settled together
or they drift apart again.

### Rule 1.2 — preserve the workforce company axis of the relocated People business records

Existing Skill and Training records become People-owned while retaining their workforce
company scope: either directly, through their own `company_entity_id`, or through a named
parent that has one. That is the existing contract's Class C and Class D. R2 and R3 preserve
these columns and guards; moving the Module does not convert a workforce entity id into a
platform `company_id`. New People business records, including Progression, follow plan 0001's
explicit tenant/company scope and stable workforce subject contract; their schema is not
implicitly dictated by the legacy connector layout.

Three reasons, in order of weight:

1. **Provider replacement has to keep the data.** #20 requires that replacing an HR
   provider preserves People-owned business history and connector integration history.
   Workforce entity ids are connector-issued and stable across connections, so a remap of external identities carries every skill
   record with it. Platform company ids would survive too, but they cannot express the
   case where the provider's idea of a company does not line up one-to-one with
   Belimbing's — and that mismatch is precisely what the connector exists to absorb.
2. **The provider must never need to know about platform companies.** An adapter is handed
   a workforce scope and returns provider records. If the relocated records were silently
   re-keyed on platform companies, every adapter would need the mapping in section 2, and a wrong or hostile
   provider payload could then select which platform company's data it wrote into.
3. **Preserve the existing mechanism.** The current guard is built on `company_entity_id`.
   Relocation must retain its protection behind the People seam, with no unguarded interval.

### Rule 1.3 — two connector tables may store a platform company id, and no others

The two are:

- `people_connector_connector_provider_connections.company_id`, which exists today;
- `people_connector_connector_workforce_companies.company_id`, once decision 2.1's
  attribution column lands under #26.

Everywhere else, a platform company id in the connector is a bug.

**The lint asserts membership, not a count.** The set of connector columns holding a platform
company id must be a **subset** of those two. That is the inclusion form again, and it is
what lets one rule cover both sides of #26 without being rewritten on the day the column
lands: one table is a subset of two, and so is two. A lint that asserted "exactly one" would
go red when correct code arrived, and a lint that asserted "exactly two" is red until then —
both teach the next person to edit the lint rather than the code.

Today the set has one member, and every skill table uses `company_entity_id`. A schema lint
over the connector's migrations can assert the subset, and that belongs with the naming lint
under #24.

The first version of this rule stated the same fact three times with three different numbers:
"exactly one" in the heading, "section 2 adds the second" in the body, and "exactly the two
tables named" in section 12's lint specification. #24 implements that specification, so an
implementer would have had to guess which was authoritative. It is the same defect as rule
8.1's original heading, one round later and one section earlier — a heading that had stopped
tracking its own body.

`provider_connections.company_id` is not an exception to rule 1.2, because a connection is
not a People business record. It is an installation fact: it says which platform company an
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

**This decision depends on an unverified fact, and the fact is open question 4.**

Two mismatches between a provider's idea of a company and Belimbing's are both ordinary in
HR integration, and this decision only handles one of them.

- **Provider finer than platform.** HR2000 keeps separate records for payroll entities that
  Belimbing treats as one legal entity. Many workforce companies, one platform company.
  Allowed, and the reason the many-to-one direction is open.
- **Provider coarser than platform.** HR2000 keeps one company record for the whole group,
  and distinguishes SBG's five legal entities by branch, department or cost centre. One
  workforce company would have to serve five platform companies — which is exactly the
  shape this decision refuses.

If discovery finds the second shape, then under decision 2.5 and the eventual deletion of
the carve-out, nobody can act for anything. That is the same failure mode this document
criticises the derived chain for, arriving from the other direction.

**Rule:** if #28 finds the provider coarser than the platform, decision 2.2 is revisited,
not worked around. Specifically, the workaround that must not be reached for is attributing
one workforce company to several platform companies — that is the shape refused above, and
it is refused for a safety reason that discovery cannot change. The answer would instead
have to come from a finer provider axis: the branch or department that distinguishes the
legal entities is a workforce organization unit, and mapping *that* to platform companies is
a different decision with a different safety argument. This document does not make it,
because making it now would mean designing against a provider nobody has looked at.

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

`WorkforceProjectionStore::persistCurrent()` already complies: it is handed an explicitly
named column array and calls `fill()` with that, not with anything derived from the payload.
So this rule codifies what the code does rather than asking for a change, and the test named
in section 12 is a regression test against a future author widening it.

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

**The first version of this document certified the carve-out's shape as sound, one paragraph
before showing it failing open. That was wrong, and the correction is the most useful thing
in this section.**

The reading was: it grants only when it positively counts exactly one company, so a second
company makes the count two and everything fails closed; an unfamiliar state makes it
stricter. The counting is real, but counting is the surface form, not the decision. What
the rule actually says is *"grant everything, because I cannot see a second company"* — and
a decision made on the absence of something is an exclusion test however it is spelled. By
this document's own rule it fails open on exactly the state it has not thought about, and
the hole below is that state.

So the shape verdict and the hole are one defect seen twice, not a clean bill of health next
to an unrelated finding. The principle in the method note caught its own author's example,
which is the strongest thing that can be said for it.

That does not make the carve-out removable today — decision 2.5 is right that deleting it
before stored attribution lands would take single-company tenants from working to nothing.
It makes it a **temporary measure with a known failure mode**, which is a different thing to
document than a sound rule, and it is why the retirement below is not optional.

The failure mode, reproduced end to end by `agent:opus-5-review-m` and filed as
`BelimbingApp/belimbing#489`, where it is confirmed rather than as-reported. The carve-out
counts rows in `companies`, which is Core's table under Core's deletion rules — and
`Company::forceDelete()` is a public method whose only guard is that you may not hard-delete
the tenant's *primary* company. So:

1. A tenant has two platform companies and one tenant-scoped HR connection.
2. Nothing in the connector references the second platform company, because a tenant-scoped
   connection stores `company_id = null`, so no foreign key restrains the delete.
3. An administrator force-deletes the second platform company. It succeeds — it is not the
   primary company.
4. `withTrashed()->count()` now returns 1. The carve-out reopens.
5. Every workforce company in the tenant, including the deleted company's, is now offered
   to the surviving company's users — and the deleted company's employees, skills and
   assessments persist independently of the platform company, because deleting it deletes
   none of them. Relocating those business records to People must preserve this distinction.

A **soft** delete does not do this: the count includes trashed rows, so archiving the second
company leaves it at two and everything stays closed. That control was run, and it narrows
the fix — the problem is hard deletion specifically, not company removal in general.

The carve-out inherited Core's soft-delete discipline without inheriting the fact that Core
also supports hard deletion for any non-primary company. That is the neighbour problem
exactly: `withTrashed()` is the careful spelling, it looks like the careful spelling, and
it is defeated by a method in a module this one does not own.

**Rule:** while the carve-out exists, `CompanyAttribution` **must state** — in its own
docblock, next to the carve-out — that the carve-out depends on platform companies never
being hard-deleted, and must reference `BelimbingApp/belimbing#489`. It does not say that
today: the class explains the carve-out and points at this issue, and says nothing about
hard deletion. This document contains no code, so nothing here makes it true. **The docblock
is #26's**, alongside the attribution column, and it is listed in section 12.

The first version of this rule was written in the present tense, as though the dependency
were already recorded. It was not. A contract asserting a mitigation that does not exist is
worse than one that asks for it, because the next reader stops looking.

Once stored attribution lands, the carve-out is deleted rather than kept as a fallback —
with the link in place, a tenant with one company simply has every workforce company
attributed to it, and the special case has nothing left to do. Whether Core should
additionally refuse to hard-delete a company that has connector data is Core's decision, not
this contract's, and it is open question 3 below.

---

## 3. Being able to reach data is not the same as owning it

### The problem, found by reading the enum against itself

`PeopleCapability` lists twelve capabilities: company directory, employee directory,
organization directory, manager hierarchy, user directory, payroll, attendance, leave,
claims, training, documents, single sign-on.

**Ten of those are things an HR provider owns. Two are not.**

**`Training`.** Plan 0001 assigns the entire training lifecycle to **People** — needs,
requests, approvals, events, attendance, results, certificates, evaluations, effectiveness
reviews and passports. HR2000's overlapping historical training data is an import source
with provenance, not a second live writer for the selected People Training installation.

**`SingleSignOn`.** The register in section 4 gives it to the platform identity layer under
#25, with nothing stored and no data direction. A provider may well be able to perform it.
That does not make the provider authoritative for it.

So an adapter that truthfully declares `Training: read_write`, or `SingleSignOn: read`, is
telling the truth about what it can do, and feature code that reads either declaration as
"the provider owns this" would be wrong. Both sit in a list where every neighbour means "the
provider owns this", and they inherit that reading for free. Nothing in the type system
stops it.

The first version of this section said eleven of twelve and named only `Training`, which
contradicted this document's own register two sections later. Two cases rather than one
makes the rule below stronger, not weaker: a single odd member of a list reads as an
exception, and two read as what they are — the enum is a list of things a provider can do,
and it was never a list of things a provider owns.

The historical `PeopleCapability` list has no `Skills` case. That absence does not decide
ownership or prohibit a People integration contract: a published capability may expose a
People business operation while its authoritative writer remains the selected People
installation. Adding a declaration requires the explicit contract and authorization
proof in plan 0001; the connector does not become its business owner.

### Rule 3.1 — a capability declaration states reachability, never authority

A `CapabilityDeclaration` answers: can this provider serve this data, in which direction,
over which channel. It never answers: may we treat this as the truth, and may we write to
it. Authority is fixed by this contract's ownership register, per deployment, in one place.

### Rule 3.2 — ownership is consulted before capability

Before any provider read whose result will be **stored**, and before any provider **write**,
feature code asks the ownership register who owns the data class. Only then does it ask the
capability set whether the owner's provider can serve it.

- If the register assigns a business class to People, writes go through that selected
  authoritative People installation's contract. A read from another source of the same
  class is an **import**, and every imported record carries provenance saying which
  connection and which run produced it; the source never becomes a second live writer.
- If the register says the selected HR provider owns it, a declared write channel may be
  used and a connector-side copy is a projection, subject to section 6.
- Connector integration records remain connector-owned; provider declarations grant no
  authority to alter administered identity mappings, attribution or provider connections.

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

**What that default does and does not buy, stated exactly.** `None` is returned *to code
that asks*. Today almost nothing asks. Outside its own file, `CapabilitySet` is reached in
two places: `ProviderConformance`, which is a test helper, and `Livewire/Connections/Index`,
which displays declarations on a screen. `ProviderRegistry` does no capability gating, and
`WorkforceProjectionStore` and `WorkforceIdentityStore` never look at capabilities at all.
An adapter that declares nothing can still be handed a connection id and write projections
through it; what stops it doing so across a company boundary is the company scope guard, not
the capability set.

So the guarantee is: **undeclared means `None` for code that asks, and rule 3.2 is what
makes code ask.** Rule 3.2 is unenforced today. Section 12 lists the check that would close
it and names its owner. Anyone relying on capability declarations as a safety property — and
open question 4 does exactly that — should read that as a rule to be built, not a property
already held.

---

## 4. The ownership register

Who is authoritative, what the connector may keep, which way data flows, and how fresh it
has to be. This table is the register referred to in rule 3.2. A deployment records its
selected authoritative source explicitly under plan 0001. That selection does not move
Skills, Training or Progression business ownership into the connector.

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
| Documents | Provider | **Nothing** | Read-through only | Live at point of use |
| Training | Selected People Training installation | **Nothing** under the current projection allowlist | Historical source → People as import with provenance; authorized operations → selected People installation | Fresh context where the operation requires it |
| Single sign-on | Platform identity layer, #25 | n/a | n/a | n/a |
| Skills and assessments | Selected People Skills installation | **Nothing** under the current projection allowlist | Authorized operations → selected People installation | Fresh context where the operation requires it |
| Progression policy, eligibility and business history | Selected People Progression installation | **Nothing** under the current projection allowlist | Authorized operations → selected People installation | Published policy/version and required current evidence |

**Rule 4.1 — a capability with no row in this register is owned by nobody.** It may not be
stored, and it may not be written, until a row exists. An author who adds a capability,
finds no row, and falls back to what the adapter declares has done the one thing rule 3.1
forbids, so the register has to answer them instead of being silent. This is the same
inclusion form as `CapabilitySet` returning `None`, and without it the register is the
fail-open shape this document claims to have eliminated — a list that decides only about the
entries it recognises and says nothing about the rest.

"Nothing" in the third column means exactly that: no table, no cache, no snapshot, no
denormalised summary field. If a screen needs an employee's leave balance it calls the
provider when the screen is drawn, and if the provider is down the screen says so.

The reasoning is #20's invariant that sensitive HR content is not copied merely because an
adapter can reach it, and it is a much easier rule to hold when it is "nothing" than when
it is "as little as possible". A reviewer can check "nothing".

**Why Documents is "Nothing" rather than "reference and metadata".** The first version of
this register allowed metadata, and it contradicted rule 6.1, which names no document field
and therefore forbids storing any. Rather than add fields to 6.1, the register row is the
one that moves, because document metadata is itself HR content: a stored list of an
employee's document titles is a stored list of what they have been disciplined for. A
document is addressed by a provider reference held for the length of one request, and the
fetch is authorised connector-side before it is made, per rule 7.1 — the provider's own
authorisation applies to it as well, but as a second gate rather than the first one. That
puts Documents under rule 6.3 with the other read-through capabilities, and leaves one answer
where there were two.

---

## 5. People-owned business capabilities

Skills, Training and Progression are People business capabilities. The connector supplies
integration context and transport, not a second store or writer for their business records.

**5.1 — A People business capability does not require a matching external HR feature.**
The selected workforce source supplies workforce and organisation context. It does not
supply the People business capability, and absence of a matching HR2000 feature does not
disable it. This does not waive required context, authorization or availability of the
selected authoritative People installation.

**5.2 — People business records reference the provider only through stable workforce subjects.**
Never a provider's own id, never a name, never an email. A People business record that stored an
external id would be pinned to the provider that issued it, which is exactly what a provider
swap has to be able to change. Where an external id may legitimately be stored is rule 8.1;
a People business table is not on that list. Relocated records retain their workforce entity
ids through the R1 seam.

**5.3 — Relocated People business tables keep their company guard on the workforce axis**
(rule 1.2), directly for Class C and through their named parent for Class D. R2 and R3 must
preserve the existing `CompanyOwned` protection and discovery tests behind the People seam;
physical relocation alone proves neither. New tenant-owned People tables carry `tenant_id`
and explicit company scope under plan 0001. The connector's integration records have their
own scope: `provider_connections` and `workforce_entities` are deliberately tenant-wide,
for the reasons the connector contract gives.

**5.4 — External HR data that overlaps a People business capability is an import, with
provenance, and is never a live second writer.** HR2000's historical training records come
in as an import that records where each record came from and when. After import, the
selected People installation is the only business writer. Changing the authoritative
source requires an explicit recorded transition under plan 0001, not an adapter declaring
a write channel; historical provenance and authority must survive that transition.

**5.5 — An external HR outage does not by itself block an independent People business write.**
The selected People installation is the system of record. If an operation needs fresh
provider context to be correct, it fails with that reason rather than proceeding on a stale
projection and rather than being silently queued. An unavailable authoritative People
installation is not permission for the connector to become a fallback business writer.

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

### Rule 6.2 — snapshot payloads have their own allowlist, in their own vocabulary

`workforce_snapshots` is the connector's append-only history: every projection upsert,
identity attach, remap, deactivation and entity merge is recorded there with a JSON payload.
Because it is append-only, a mistake in what goes into it cannot be fixed by an update, only
by deleting history. So it needs a rule, and the rule needs to be the right one.

**The first version of this rule said "only fields on the list in 6.1", and that was wrong
in a way that would have broken working code.** The 6.1 list is a list of *column* names —
`company_entity_id`, `parent_entity_id`, `display_name`. Snapshot payloads use a different
vocabulary, because they record provider references rather than resolved entity ids:
`company_reference`, `parent_reference`, `external_id`. Not one payload key the connector
writes today appears on the 6.1 list. An implementer building the filter section 12 asks for,
against the rule as first written, would have rejected every snapshot the connector writes
and stopped merge and remap history working.

The allowlist below is the payload's own vocabulary, taken from what
`WorkforceHistoryEvent`'s five factories actually construct.

**Projection events** (`projection.upserted`) — `reference`, `active`, `observed_at`,
`source_version`, and then by resource type: `name` and `code` for a company;
`company_reference`, `parent_reference`, `name`, `code`, `kind`, `effective_at` for an
organization unit; `company_reference`, `organization_reference`, `name`, `code`, `tier`,
`effective_at` for a position; `company_reference`, `user_reference`,
`organization_reference`, `position_reference`, `manager_reference`,
`department_head_reference`, `display_name`, `employee_number`, `email`, `effective_at` for
an employee.

**Identity and merge events** — `external_id`, `superseded_external_id`,
`replacement_external_id`, `replacement_identity_id`, `superseded_entity_id`,
`surviving_entity_id`, `surviving_external_id`.

**Every `*_reference` value** is a three-key object: `provider_id`, `resource_type`,
`external_id`. That is a provider external reference under rule 1.1's third spelling, and it
is deliberate — see rule 8.1.

**Rule:** a snapshot payload contains only keys on the two lists above. The reference and
event scaffolding is what those lists are made of; the constraint that matters is on the
fields carried over from a **provider record**, and those are exactly the fields rule 6.1
permits, expressed here under the payload's own names.

### The hazard this rule prevents is prospective, not present

Worth being exact about, because the first version of this document described it as a live
hole and it is not one.

**Today a raw provider response cannot reach this table.** `WorkforceHistoryEvent` has a
`private` constructor and a `private array $payload`. The only ways to build one are its five
named factories, and each assembles the payload field by field from a typed DTO —
`WorkforceCompany`, `WorkforceOrganizationUnit`, `WorkforcePosition`, `WorkforceEmployee` —
whose fields are precisely the permitted ones. `WorkforceHistory::record()` always writes
`$event->payload()`, and nothing else in the domain writes a `WorkforceSnapshot`. An adapter
does not hand over a provider response; it hands over a typed record and the connector builds
the payload. The private constructor is doing real work.

So the salary-arriving-in-the-same-JSON-object scenario is what **would** happen if a future
factory took a payload array from a caller, or if an adapter were ever allowed to supply one.
It is not what happens now.

The rule is still worth having, for the same reason connector PR #10 built a guard instead of
writing a style guide: what holds the line today is one `private` keyword in one class, and
nothing states that it is load-bearing. Enforcing rule 6.2 — a filter at the snapshot write
path, plus a test that an unlisted key is rejected rather than silently trimmed — belongs to
**#24**, and its value is that it survives the day somebody adds a sixth factory.

### Rule 6.3 — read-through data is not stored anywhere, including in a cache

For every capability whose "connector may store" column says nothing: no cache table, no
Redis entry keyed by employee, no session copy, no debug log line containing the response,
no snapshot. If it needs to be shown twice on one page, it is fetched once and held for the
duration of that request.

---

## 7. Sensitivity, access, and what co-location actually protects

### Rule 7.1 — every row of the table below names an audience, never a gate

The gate is **rule 7.3**, for all six rows: a role reaches any of this data only through a
permission that names the capability, and decision 2.6 bounds which companies that permission
can reach. The Rule column says *who, within that*. It never says *instead of that*.

**The test for a cell:** if it names a **condition** rather than an **audience**, it is a gate
and it is wrong. Three rows failed that test across three review rounds, in two distinct ways,
and both ways are worth naming because neither is obvious while you are writing the cell.

**Naming a weaker gate.** The Directory row read "visible to authenticated users of the
attributed platform company", which made *being logged in* the gate. It would have handed a
developer, a support role or any platform administrator the whole employee directory of every
attributed company — the exact outcome #20's acceptance criteria forbid. The slip: **breadth
of audience got written as absence of a gate.** A directory really is the widest of these six
classes, and the wide end of a scale reads like no restriction if you are not careful. "Widest
audience among permission holders" and "everyone with a session" are not near each other; the
second includes every role that was never meant to hold HR access at all.

**Naming somebody else's gate.** The Documents and Compensation rows deferred to "the
provider's own authorisation". That is a gate, and it is not ours. It also does less than it
appears to: where the connector fetches under its own service credential — the normal shape
for a server-to-server adapter — the provider authorises **the connector**, not the person
looking at the screen. Every request is equally authorised, so "the provider authorises it"
authorises everything.

**So, for any content fetched from a provider rather than stored: the connector-side
permission is checked before the fetch is made.** This gate knows who is asking; a broad
service credential does not replace it. Under plan 0001, the authoritative backend also
rechecks employee binding, tenant/company, operation and record access using the approved
identity assertion. Provider authorisation remains an additional gate, never a substitute
for the caller's permission or the backend's record-level checks.

Where a row is genuinely permissive, say what it is permissive *within*.

### Data classes

| Class | Contents | Audience, within rule 7.3's gate |
|---|---|---|
| **Directory** | Names, employee numbers, work email, active flag, org unit, position, manager, department head | The broadest audience here, and still a granted one: a permission naming the directory capability being read — there are five and they are separate — bounded to the workforce companies attributed to the holder's platform company |
| **Employment** | Employment dates, position history, termination details | **Never projected**; none of this is on rule 6.1's list. Read-through: the employee themselves, their management chain, HR |
| **Compensation** | Payroll of any kind, bank details, claims amounts | **Never projected.** Read-through: payroll role only. Authorised connector-side before the fetch, per rule 7.1 |
| **Absence** | Leave and attendance records | **Never projected.** Read-through: the employee, their manager, HR |
| **Competence** | Skills, assessments, results, certificates, training history — People-owned | The employee themselves, their management chain, the HOD for their department, HR |
| **Documents** | Provider-held files and their metadata | **Never projected**, metadata included. Read-through: the employee themselves, their management chain, HR. Addressed by a provider reference held for one request; authorised connector-side before the fetch, per rule 7.1 |

**Rule 7.2 — data that fits no class in this table is treated as Compensation until it is
classified.** Never projected, read-through only, narrowest audience. Six classes decide
about the data they recognise, and without a default they say nothing about the seventh
thing somebody adds — which is the fail-open shape this document exists to remove. The
default is deliberately the most restrictive class rather than a middle one, so that
classifying a new kind of data is always a loosening, made on purpose, by a change to this
table.

This default is a poor fit for data People **originates**, where "never projected" and
"read-through" have nothing to refer to and only "narrowest audience" carries. That is a real
gap and it is deferred rather than patched here, as open question 8.

### What the sweep of this table found

Three review rounds each found one contradiction in the six rows above. That is a pattern,
not bad luck, so the fourth round swept every cell against every rule governing the same
subject rather than waiting to be told again. Three more corrections came out of it, and they
are listed because the misses are more useful than the fixes.

- **The Employment row contradicted rule 6.1.** It named employment dates, department head and
  position history as though the class were stored data with an audience. Employment dates and
  position history are on no allowlist in section 6 and therefore cannot be stored at all;
  only `department_head_entity_id` can, and that is organisation structure, so it has moved to
  the Directory row where the rest of the structure lives. Employment is now read-through, like
  Compensation and Absence.
- **The Compensation row carried the same "provider-authorised" gate as Documents.** The
  earlier round caught one instance and not its twin, four rows apart, because the word used
  was different. Both now defer to rule 7.1.
- **The Directory row named a permission that does not exist.** "The directory-read permission"
  is one name for five separate capabilities — company, employee, organization, manager
  hierarchy and user directory are distinct declarations, and rule 7.3 says a permission names
  *the* capability. #25 implements these, so an invented singular would have been built.

**The mechanical form of the check, for reuse.** Tables are the highest-risk neighbours in a
specification. A cell is written to be scannable, so it is written in isolation and read as a
complete statement — prose carries its qualifications inline, and a cell drops them. All the
defects above were that same compression: Documents dropped rule 6.1's allowlist, Directory
dropped rule 7.3's gate, Documents again dropped rule 7.1, Employment dropped rule 6.1 a
second time. So: **for every table, check each cell against every rule governing the same
subject, and treat a cell naming a condition rather than a value as a gate until proven
otherwise.**

Competence data is performance data. It is more sensitive than the directory and it is
owned by us, which means we cannot borrow the provider's access rules for it — we have to
write our own. That is #25's work; the classes in the table above are what it grants
against.

### Rule 7.3 — HR access is granted, never inherited

A role holds access to a provider or People-owned HR capability only if it has been
granted a permission that names that capability. No platform-administration role acquires
it by being administrative: not tenant owner, not company administrator, not the role that
configures provider connections, not a developer or support role.

Stated as an inclusion test on purpose. The tempting form is "administrative roles get
everything except HR", and that form loses the moment somebody adds a new administrative
role and does not think about the exception.

Configuring a provider connection is a genuine administrative task and it does not require
reading a single employee record. Those are two different permissions and they must not be
bundled.

### Rule 7.4 — break-glass is time-boxed, logged, and never silent

Emergency access to HR data is granted for a stated reason, expires on its own, is written
to an audit record that the emergency access itself cannot edit, and notifies the HR
governor. An access path that has none of these is not break-glass, it is a back door with
a nicer name.

### Rule 7.5 — say plainly what co-location does not protect against

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

**Rule 8.1 — a provider's own id may be stored in exactly two columns and in append-only
history, and nowhere else.**

The permitted places, each for a stated reason:

- `external_identities.external_id` — the mapping itself, keyed by connection, provider,
  resource type and external id. This is the one that makes a provider replaceable.
- `reconciliation_issues.external_id` — nullable, deliberate, and necessary: a reconciliation
  issue has to be able to name a provider record that has **no** workforce entity yet, which
  is often the whole problem being reported. `ReconciliationIssueStore` passes it explicitly
  and validates its length.
- `workforce_snapshots.payload` — every reference in an append-only history event carries
  `provider_id`, `resource_type` and `external_id` together, by design, because a history
  event has to remain readable after the identity it refers to has been remapped or merged
  away. See rule 6.2.

**A provider id on a projection row, or on a People-owned business record, is a bug**,
for the same reason as rule 1.3: it would pin live data to a provider we intend to be able to
replace. History and reconciliation are not live data — one is a record of what was said, the
other is a record of what could not be resolved.

The first version of this rule said "exactly one table". That was false, and it was false in
a way that mattered: section 12 proposes a schema lint over the migrations, and an implementer
extending it to cover 8.1 as first written would have flagged `reconciliation_issues.external_id`
— a column that is there on purpose — and most likely deleted it.

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

**Rule 9.3 — an external HR outage degrades the operations that depend on it.** Independent
People business work and connector integration work may continue; rule 5.5 still refuses
operations requiring fresh unavailable context. Read-through capabilities fail with a clear
reason. Projections continue to be served, marked stale. There is no local fallback writer
for an unavailable authoritative source.

**Rule 9.4 — a write whose outcome is unknown is not retried blindly.** The connector
already has `ProviderUnknownOutcomeException` for this. An unknown outcome is reconciled
against the provider before it is retried, because a duplicated payroll or leave write is
worse than a failed one.

---

## 10. Provider replacement

The whole point of stable workforce subjects for People business records (rule 1.2) is that
this stays possible.

Replacing a provider means: a new connection, a new set of external identities, and an
administered remap from the old workforce entities to the new provider's records. Every
People business record retains its stable subject because it references the entity, not
the provider. Relocation must preserve and verify those references; it does not prove
that remapping or cutover is automatic.

**Rule 10.1 — the remap is administered and reviewable.** Same reasoning as decision 2.3:
an automatic matcher deciding that HR2000's employee 4471 is the same person as the native
provider's employee 88 is a matching-on-mutable-attributes rule wearing a migration
costume.

**Rule 10.2 — attribution survives replacement.** It is an administered field (decision
2.4), so a new provider does not clear it. The workforce company keeps its platform company
even though everything the provider said about it has been replaced.

**Rule 10.3 — cutover is reversible within a stated window.** #31 owns the mechanics. The
constraint this contract places on it is that People business history and connector
integration history are never rewritten during a cutover, only re-pointed.

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
   but that is a role definition and it interacts with rule 7.3. **#25**, with the
   governance surface in **#24.**

3. **Should Core refuse to hard-delete a platform company that has connector data?** The
   defect itself is filed and confirmed as `BelimbingApp/belimbing#489`, reproduced end to
   end, with a control showing a soft delete does not do it. What is still open is the
   remedy. Once stored attribution lands, the composite foreign key restrains the delete for
   *attributed* companies, but an unattributed tenant-scoped deployment has no such restraint.
   Whether Core grows a general "this company is referenced by domain data" guard is Core's
   decision and affects modules beyond this one. **Core, on `belimbing#489`.** The
   connector-side half — the docblock in `CompanyAttribution`, and deleting the carve-out
   when attribution lands — is **#26** and is listed in section 12.

4. **Everything about HR2000 is unverified.** The product edition, hosting mode, enabled
   modules, vendor support arrangement and integration rights are all still unknown, and
   this document deliberately contains no HR2000 capability matrix, because writing one
   from marketing material would be inventing evidence — #20 says in its own words that
   marketing capability names are not evidence. **#28.**

   Two things this item depends on, both stated rather than assumed. First, the safety
   argument: an unverified adapter declaring nothing yields `None` **to code that asks**, and
   today the write paths do not ask — rule 3.2 is what would make them, and rule 3.2 is
   unenforced. See rule 3.3 and section 12. Second, decision 2.2 assumes the provider is at
   worst *finer* than the platform; if discovery finds it coarser, that decision is revisited,
   as section 2 sets out.

   This is in #21's **Scope**, not its Acceptance. It is the one scope bullet this document
   does not deliver, and because #21 closes with this pull request, it is recorded on #28 so
   that closing #21 does not lose it.

5. **The rule for a record the adapter cannot give a stable id to.** Fail the sync, or omit
   the record and report it. Already filed as **#44**; rule 8.2 only forbids guessing.

6. **Should `provider_connections` be readable by a company administrator?** Today it is
   tenant-wide and tenant-administered, which is safe and may be too coarse for a tenant
   whose companies each run their own HR install. Carried over from
   `company-ownership.md`'s own open list. **#24.**

7. **Merge, deactivate, and cross-connection bind authority on `workforce_entities`.**
   Carried over, and wider than it first looked. The merge half now has an answer — a merge
   should be performed with the authority of the platform company that both entities
   attribute to, and refused when they attribute differently.

   The half that was missing: `WorkforceIdentityStore::resolveOrCreateIdentity()` takes a
   `preferredEntityId`, gated only on reviewed provenance, and `assertReferenceFitsConnection()`
   checks only the provider id and the length of the external id. So two connections scoped to
   two different platform companies can be bound to **one** workforce entity without any merge
   taking place — the exact shape decision 2.2 refuses, reached by a different door. Under
   today's derived attribution the answer then flips between the two platform companies
   depending on which connection synced last, which is an independent argument for decision
   2.1 that this document did not have when it was written.

   It needs a gated write path rather than a plain sync, so it is not urgent. Turning any of
   this into an enforced rule is a change to `WorkforceIdentityStore` and belongs with
   **#26**, not in a document.

8. **Is rule 7.2's default meaningful for data People originates?** The default sends
   an unclassified data class to Compensation — never projected, read-through only, narrowest
   audience. Two of those three are statements about *provider* data. For data People
   originates there is nothing to read through to, so only "narrowest audience" carries and
   the rest of the sentence is decoration.

   Deferred rather than patched, and the reason is worth stating because the obvious fix is
   wrong. Adding a second default for People-originated data puts a branch in the one rule
   whose entire value is having no branches — a default that asks a question before it applies
   is a default an author can argue their way out of. The real question underneath is whether
   an unclassified People-owned business data class should be able to exist at all, given that
   rule 4.1 requires an explicit ownership-register entry before storage or writes. That is a governance question, not a wording one. **#24.** Raised by
   `agent:opus-5-review-m`.

   Recorded here rather than left as a judgement call I made quietly. This document's best
   feature is that it names what it does not decide, and an unnamed deferral is the one thing
   that practice forbids.

---

## 12. How this contract is checked

A contract nobody can test is a preference. What exists today, and what should exist:

**Exists.** The connector's company guard, its discovery contract test and its bypass lint.
`CapabilitySet` returning `None` for undeclared capabilities — as a default for code that
asks, which is not the same as a gate; see rule 3.3. `ProviderConformance`, which resolves
every declared port and fails an adapter that declares one it cannot supply.
`CompanyAttribution` failing closed on an unattributable workforce company.
`WorkforceHistoryEvent`'s private constructor, which is what actually keeps raw provider
payloads out of the snapshot table today. `WorkforceProjectionStore::persistCurrent()`
already naming the columns it writes.

**Should exist, and where it belongs.**

- **The check rule 3.2 needs.** Nothing consults the ownership register today, and nothing
  makes a write path consult the capability set either. Until something does, "the provider
  declared nothing so it can do nothing" is a rule, not a property. This is the one open
  question 4 leans on, so it is first on the list. **#24.**
- A schema lint asserting rules 1.1 and 1.3 **at the column level, which is all a schema
  lint can see**: only `company_id` and `company_entity_id` as company-carrying column names;
  `company_id` only on the two tables rule 1.3 names, asserted as a subset so that it holds
  both before and after #26 lands the attribution column; and `external_id` only on the two
  columns rule 8.1 names. Subset, not count, in all three cases. The property, parameter and
  payload-key half of rule 1.1 stays a review rule until something can read those. **#24.**
- A test that the projection write path never writes an administered column, per decision
  2.4 — a regression test against a future widening, since the current store already
  complies. **#26.**
- A filter at the snapshot write path enforcing rule 6.2 **against the payload vocabulary in
  that rule, not the column list in 6.1**, and a test that an unlisted key is rejected rather
  than trimmed silently. **#24.**
- The `CompanyAttribution` docblock recording that the carve-out depends on platform
  companies never being hard-deleted, referencing `belimbing#489`; and deleting the carve-out
  when stored attribution lands. **#26.**
- A test that the in-process and remote adapters refuse the same unauthorised call, per
  rule 7.5. **#30.**
- A test that a provider omitting a record does not deactivate its projection, per rule
  9.1. **#29.**

The pattern in each case is the connector guard's: the test discovers what it covers rather
than being copied per slice, so a future capability enrols by declaring itself rather than by
somebody remembering.

---

## Provenance of this document

The first version made five statements about existing code that were wrong or overstated,
found by `agent:opus-5-review-m` reading it as rules to be checked rather than as prose:
rule 8.1's "exactly one table", rule 6.2's use of the wrong vocabulary, rule 1.1's scope
exceeding both its evidence and its lint, the snapshot hazard described as present when a
private constructor closes it, and "safe by construction" for a capability set that no write
path consults. Two of those would have sent an implementer to build something that broke
working code. All five are corrected above, in place, with the correction stated rather than
quietly applied — a contract that revises itself silently teaches its readers to trust the
current text less, not more.

The same review is why the carve-out's shape is now recorded as an exclusion test rather than
certified as sound, and why decision 2.2 now states its dependency on open question 4.

Two further rounds found four more, and every one of them was in the same six-row table in
section 7. Round two: the **Directory** row made being authenticated the gate, contradicting
rule 7.3 one section below it and decision 2.6 four sections above it — found by
`agent:desktop-terra`. Round three: **Documents** named the provider's authorisation as its
own gate, one line beneath the sentence forbidding exactly that; **Employment** named contents
that rule 6.1 forbids storing; **Compensation** carried the same provider-authorised gate as
Documents, four rows away, missed in the previous round because the wording differed. Round
three also found rule 1.3 stating one fact with three different numbers across its heading,
its body and section 12's lint specification.

Six defects, five of them in one table, none of them a disagreement with the code. That is
the finding, and it is why section 7 now carries the sweep that produced the last three: **a
table cell is written to be scannable, so it is written in isolation and read as a complete
statement, while the rule it must obey is a paragraph away carrying its qualifications
inline.** Checking a rule against the code turned out to be the easy half. Checking it against
its own neighbours is where this document kept failing, and tables are where the neighbours
are hardest to see.

---

## Enforcement re-audit checklist

These are follow-up checks, not findings or claims that the 2026-09-05 amendment verified code.

- [ ] Re-audit the R1–R4 ownership seam: People business records have one authoritative writer and the connector retains integration records only.
- [ ] Re-audit tenant/company guards, Class C/D parent resolution, discovery coverage and justified scope bypasses after relocation (rules 1.2 and 5.3).
- [ ] Re-audit identifier naming and permitted platform-company/external-id persistence, including JSON and history exceptions (rules 1.1, 1.3 and 8.1).
- [ ] Re-audit stored attribution, tenant-safe foreign keys, administration-only mapping changes and refusal of unattributed access (section 2).
- [ ] Re-audit the single-company carve-out, hard-delete scenario and claimed retirement/docblock mitigation against current code (section 2 and belimbing#489).
- [ ] Re-audit ownership-before-capability enforcement, undeclared capability refusal and provider conformance on actual read/write paths (section 3).
- [ ] Re-audit projection field allowlists and preservation of administered fields during sync and provider replacement (rules 2.4 and 6.1).
- [ ] Re-audit snapshot factories, payload vocabulary, all snapshot writers and rejection of unlisted provider fields (rule 6.2).
- [ ] Re-audit read-through non-persistence across caches, sessions, logs, snapshots and exports (rule 6.3).
- [ ] Re-audit granted HR permissions, audience/company boundaries, connector-before-fetch checks, authoritative-backend checks and co-located/remote denial parity (section 7 and plan 0001).
- [ ] Re-audit break-glass expiry, immutable audit evidence and HR notification (rule 7.4).
- [ ] Re-audit workforce identity remapping, merge/deactivation authority and employee-to-login binding without matching mutable attributes (section 8).
- [ ] Re-audit positive deactivation, visible staleness, fresh-context refusal and unknown-outcome reconciliation without blind retry or fallback writers (sections 5 and 9).
- [ ] Re-audit provider cutover, retained attribution and business/integration history, and the stated reversal window (section 10).
