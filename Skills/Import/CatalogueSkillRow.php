<?php

namespace App\Domains\People\Skills\Import;

final readonly class CatalogueSkillRow
{
    public function __construct(
        public string $code,
        public string $department,
        public string $category,
        public string $name,
        public string $definition,
        public string $criticalClassification,
        public string $evidenceGuide,
        public string $assessmentMethod,
        public string $reassessmentMonths,
        public string $owner,
        public string $active,
        public WorkbookSource $source,
    ) {}
}
