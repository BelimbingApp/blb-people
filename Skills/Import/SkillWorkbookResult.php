<?php

namespace App\Domains\People\Skills\Import;

final readonly class SkillWorkbookResult
{
    /**
     * @param  list<CatalogueSkillRow>  $skills
     * @param  list<CatalogueCategoryRow>  $categories  Category occurrences, not deduplicated identities.
     * @param  list<CatalogueLevelRow>  $levels  Source proposals, not published proficiency policy.
     * @param  list<WorkbookDefect>  $defects
     */
    public function __construct(
        public array $skills,
        public array $categories,
        public array $levels,
        public array $defects,
    ) {}
}
