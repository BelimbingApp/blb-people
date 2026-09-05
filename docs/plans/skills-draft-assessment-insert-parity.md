# skills-draft-assessment-insert-parity.md

**Status:** Reproduced; migration repair remains open.
**Last Updated:** 2026-09-06
**Sources:** [People #179 CI run](https://github.com/BelimbingApp/blb-people/actions/runs/33976173747), Skills migration 0330_02_05_000000_harden_skill_assessment_workflow.php.
**Agents:** desktop-astra/gpt-6-astra

## Problem Essence

SQLite skips the assessment insertion guard entirely for draft rows. PostgreSQL
accepts a draft only with pending HOD verification and null finalization fields.
A draft carrying finalized_at was accepted in local SQLite tests and rejected by
PostgreSQL CI. This is a pre-existing persistence-boundary difference, not a
reason to weaken the published-read predicate or skip a supported driver.

## Desired Outcome

Both drivers refuse the same inconsistent draft lifecycle fields while preserving
ordinary drafts and authorized submitted-to-finalized transitions. Existing
inconsistent rows are identified and refused by a migration preflight without
silently erasing or fabricating approval history.

## Design Decisions

Recommend a new Skills-owned migration that brings SQLite insertion semantics
into parity with PostgreSQL. Editing the already-applied migration would leave
installed databases unchanged. Weakening PostgreSQL to accept inconsistent rows
would discard the stronger lifecycle contract. Keep the read service defensive
against legacy data independently of this repair.

## Phases

- [x] Reproduce the same fixture's acceptance on SQLite and rejection on PostgreSQL; remove that nonportable fixture from the self-read suite. desktop-astra/gpt-6-astra
- [ ] Add one shared behavioral fixture matrix for draft verification/finalization fields, ordinary draft insertion and authorized submission on both drivers.
- [ ] Add an upgrade-safe migration with explicit preflight refusal for existing inconsistent rows and parity for new writes.
- [ ] Verify migration replay and the full People domain on fresh SQLite and PostgreSQL databases; retain finalized-row immutability and workflow authority checks.
