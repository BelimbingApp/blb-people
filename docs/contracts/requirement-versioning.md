# Requirement profile versioning

**Status:** Contract and implementation-gap record for [issue #156](https://github.com/BelimbingApp/blb-people/issues/156); documentation only.
**Source:** [0002 Skills and assessment](../plans/0002-people-skills-and-assessment.md), with HOD accountability and HR governance from [0000](../plans/0000-people-epic-roadmap.md).
**Owner:** People Skills. This document does not mark the proposed plan implemented or decide its open HR policies.

## Identity and governed history

A profile describes applicable skill requirements. A profile version identifies one immutable set of requirements, effective dates, weights, mandatory certifications, evidence expectations and critical gates. A profile code or a link labelled “current” is insufficient evidence of the version used.

Publishing a changed requirement produces a new version; it does not rewrite a previously published version, an assessment, or a downstream decision. A mandatory safety-critical qualification remains a gate even when weighted achievement is high. The 0–5 proficiency scale and its evidence standards remain stable: unknown or unassessed is not zero, and unverified, expired and current are separate states.

HODs propose and remain accountable for departmental requirements; HR governs their publication. These are responsibilities, not authorization grants inferred from a job title. Publication must follow the scoped, accountable review workflow. This contract does not invent new role names, permissions or approval shortcuts.

## Lifecycle and eligibility

| State or historical relationship | Meaning | Permitted use |
| --- | --- | --- |
| Draft | Editable proposal, not published policy | Prepare and review; refuse a new assessment against it |
| Published | Approved, immutable version with recorded publication and effective dates | Select only when applicable for the requested scope and date |
| Superseded | A later published version replaces this version for subsequent applicability | Retain and read its original requirements and dependent evidence; do not silently repoint references |
| Retired | Withdrawn from new use | Refuse a new assessment against it; retain historical reads and evidence |

“Superseded” describes a relationship in the contract, not a claim that the database has a separate status with that spelling. The shipped enum has draft, pending HOD review, pending HR review, approved, published and retired states. Publishing a successor currently retires the predecessor. Review states freeze changes until an accountable return to draft.

Refuse publication with no change, as required by issue #156. The plan does not specify the semantic comparison policy: which changes count, including an effective-date-only change, must be agreed before implementing that refusal. Do not silently pick byte equality, timestamps, or a new version number as proof of a material change. The current store's version increment alone does not establish this refusal.

Retirement and supersession do not erase evidence. Authorized historical reads must still resolve the version and its requirements, publication/review attribution and relevant dates. Correct finalized assessment facts through explicit supersession with reason and attribution, preserving the original score and verification.

## Applicable version as of a date

An as-of result answers which published requirements applied to the employee's relevant organisation and position at the requested date, using the effective-dated workforce context from [0001](../plans/0001-people-architecture-and-provider-boundaries.md). Today's profile and today's employee assignment cannot silently replace that context.

Selection must be deterministic, including ties, validity boundaries and late-entered evidence. Record which version was selected and the date/context the selection answers. A version can be retired now and still be the correct historical version for a date when it applied; reading that evidence does not authorize creating a new assessment against a retired version.

The plan requires deterministic policy but does not prescribe the tie-breaker, the treatment of backdated publication, or whether a historical report is reconstructed from what was known then or from all evidence known now. Those decisions remain explicit implementation prerequisites. Do not present a current report with late evidence as an unchanged reproduction of a report produced earlier.

Current runtime evidence is narrower:

- RequirementResolver filters by effective date (falling back to publication date) and retirement date at day granularity, sorts by effective date and version, and rejects overlapping matching profiles.
- RequirementProfileStore keeps the current published version distinct from historical versions; published models/items are immutable.
- These mechanisms are reusable evidence, not proof that the plan's complete historical workforce, tied/late assessment and policy-version semantics have been delivered.

## Consumer pins

The following are logical references, not new storage columns or an API schema.

| Consumer | Required retained reference | What a later publication must not do |
| --- | --- | --- |
| Assessment | The particular requirement profile version and relevant requirement item/skill used at assessment time, alongside its evidence and verification | Change the assessed target, score or verification retrospectively |
| Development action | Its source gap/assessment and the particular requirement version that established the target | Recompute the recorded reason for the action using today's requirements |
| Training request | Its source development action or gap and that source's requirement version, when requirement-driven | Repoint the approved need to a newer requirement automatically |

Not every training request originates from a skill gap: regulatory requirements, new equipment/products and general development remain first-class sources. Do not fabricate a requirement-version pin for an independent source. Training participation, test results and certificates do not themselves finalize a Skills assessment. A reassessment is independent evidence; its applicable requirement version must be explicit.

Consumers use [ResolvesSkillRequirements](skill-requirements.md), whose result carries a requirement reference and numeric version. They must not depend on selector, tier or store internals. Persisted consumer pins and historical lookups need implementation proof; returning these values from the resolver alone is not proof that every consumer retains them.

## Source-to-contract trace

| Source rule | Contract consequence / proof required |
| --- | --- |
| 0002: version requirements, effective dates, weights and mandatory certifications | Version the complete requirement policy; preserve critical gates despite weighted averages |
| 0002: immutable finalized facts, verification and supersession | Preserve old versions and assessments; corrections carry reason and attribution |
| 0002: deterministic as-of selection, ties, validity, reassessment and late evidence | Explicit selection and historical context; unresolved policy identified above, with due dates and validity retained by assessment records |
| 0002: separate participation, certificate and competency facts | Training references cannot silently become finalized assessments or erase expired-certificate history |
| 0002: valid verified coverage in defined organisation/shift scope | Historical coverage uses the pinned requirements and applicable workforce context, not named backups or aggregate scores alone |
| 0002: publish governed profiles with evidence expectations, critical gates and valid weights | HOD proposal and HR-governed publication; validate the complete proposed version |
| 0002: reproduce standing and supply policy-version semantics to progression | Registers, explorer and progression consume retained evidence references without exposing private assessor notes |
| Issue #156: lifecycle, consumer pins and refusals | Draft/retired assessment refusal, retained superseded reads and no-change publication refusal, with runtime gaps stated |

Implementation acceptance must exercise changed requirements, historical reads after supersession, draft/retired refusal, no-change publication, tied and late assessments, expiry, unknown versus zero, unverified versus current, rehire/transfer and cross-scope denial. These are required scenarios, not tests claimed to have run for this documentation change.
