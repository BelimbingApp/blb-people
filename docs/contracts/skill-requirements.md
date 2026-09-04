# Skill requirements contract ([0002-c])

**Status:** Delivered in PeopleConnector
**Issue:** BelimbingApp/blb-people#80
**Implementation:** `App\Domains\PeopleConnector\Skill\Contracts\ResolvesSkillRequirements`

Assessment and development-action modules must resolve employee skill requirements through that interface (and `ResolvedSkillRequirement`) only. They must not import requirement-profile selectors, tiers, or `RequirementProfileStore` internals.

Gap formula: `ResolvedSkillRequirement::gap($currentValidLevel)` → `max(requiredLevel - currentValidLevel, 0)`.
