# skills-draft-assessment-insert-parity.md

**Status:** Implemented in PR #187; database CI verification pending.
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
- [x] Add one shared behavioral fixture matrix for draft verification/finalization fields, ordinary draft insertion and authorized submission on both drivers.
- [x] Add an upgrade-safe migration with explicit preflight refusal for existing inconsistent rows and parity for new writes.
- [ ] Verify migration replay and the full People domain on fresh SQLite and PostgreSQL databases; retain finalized-row immutability and workflow authority checks.

## Implementation Evidence

PR #187 adds migration 0330_02_06_000000_align_draft_assessment_insert_guards.php.
It preflights existing drafts on both drivers and adds only the missing SQLite
insert trigger. The already-applied workflow migration and PostgreSQL guard stay
unchanged. A refusal leaves records intact and requires source reconciliation.

DraftAssessmentInsertGuardTest has one portable matrix for four invalid insert
shapes plus an ordinary draft. Before the migration: 4 failed, 1 passed (8
assertions); afterward, including isolated legacy-SQLite preflight, replay and
rollback coverage: 10 passed (32 assertions). Existing AssessmentStoreTest
continues to exercise authorized submission/finalization and immutable history.
Legacy upgrade tests use a disposable in-memory connection on both CI drivers;
they never remove guards from the application's test database. Domain CI runs
the full People directory on SQLite and PostgreSQL.

The full local People suite passed: 697 tests, 4,086 assertions before the final
non-draft insertion assertion; the final focused suite passed 10/32. Five
mutations were caught: remove preflight, omit each of the three invalid-field
predicates, and apply the trigger to every status. The last mutation initially
survived; an explicit post-upgrade non-draft insertion assertion now catches it.
