<?php

namespace App\Domains\People\Skills\Export;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Exceptions\InvalidSkillCatalogException;
use App\Domains\People\Skills\Exceptions\ProficiencyScaleStateException;
use App\Domains\People\Skills\Import\SkillWorkbookReader;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Services\ProficiencyScaleStore;
use App\Domains\People\Skills\Services\SkillCatalogDefaults;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Writes the catalogue workbook the reader accepts, from the live catalog.
 *
 * Headers and table positions come from SkillWorkbookReader::TABLES — the
 * contract exists in exactly one place, so the HOD-facing file cannot drift
 * from what the dry run accepts. Values are plain inline strings: no
 * formulas, no merges, no error cells, so the reader's blocking-defect list
 * never fires on our own file. Anything the model has no value for is left
 * blank, never invented.
 */
final class SkillWorkbookWriter
{
    private const string MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const string REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** The Guide table this writer can represent: rows 25-30 under the row-24 header. */
    private const int GUIDE_CAPACITY = 6;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WorkforceSubjects $workforce,
        private readonly ProficiencyScaleStore $scales,
    ) {}

    public function write(int $tenantId, int $companyEntityId, string $localPath): SkillWorkbookExportResult
    {
        if ($this->tenants->requireTenantId() !== $tenantId) {
            throw new InvalidSkillCatalogException('The export tenant must match the bound tenant context.');
        }

        if ($this->workforce->resolve($tenantId, $companyEntityId, WorkforceResourceType::Company, $companyEntityId) === null) {
            throw new InvalidSkillCatalogException('[company] must reference an existing company workforce entity in this tenant.');
        }

        $scale = $this->scales->currentScale($companyEntityId, SkillCatalogDefaults::SCALE_CODE);

        if ($scale === null) {
            throw new ProficiencyScaleStateException(
                'Proficiency scale [standard] has no published version for this company; an export with an empty guide is not the contract.',
            );
        }

        $levels = $scale->levels()->get();

        if ($levels->count() > self::GUIDE_CAPACITY) {
            throw new InvalidSkillCatalogException(
                'The guide table holds six levels; retire or narrow the scale before exporting.',
            );
        }

        $departments = [];
        foreach ($this->workforce->organizationUnits($companyEntityId) as $unit) {
            $departments[$unit->reference->externalId] = $unit->name;
        }

        $owners = [];
        foreach ($this->workforce->employees($companyEntityId) as $employee) {
            $owners[$employee->reference->externalId] = $employee->displayName;
        }

        $catalogue = [SkillWorkbookReader::TABLES['02 Skill Catalogue'][2]];

        $skills = Skill::query()->forCompany($tenantId, $companyEntityId)
            ->with(['category' => fn ($query) => $query->forCompany($tenantId, $companyEntityId)])
            ->orderBy('code')
            ->get();

        foreach ($skills as $skill) {
            $catalogue[] = [
                $skill->code,
                $skill->department_entity_id === null
                    ? 'Shared'
                    : ($departments[(string) $skill->department_entity_id] ?? ''),
                $skill->category?->name ?? '',
                $skill->name,
                $skill->definition,
                $skill->critical_classification?->value ?? '',
                $skill->evidence_guide ?? '',
                $skill->default_assessment_method?->value ?? '',
                $skill->default_reassessment_months === null ? '' : (string) $skill->default_reassessment_months,
                $skill->owner_employee_entity_id === null
                    ? ''
                    : ($owners[(string) $skill->owner_employee_entity_id] ?? ''),
                $skill->active ? 'Yes' : 'No',
            ];
        }

        $guide = [SkillWorkbookReader::TABLES['00 Guide'][2]];

        foreach ($levels as $level) {
            $guide[] = [
                (string) $level->level,
                $level->name,
                $level->anchor,
                $level->authority,
                $level->authority,
                $level->authority,
            ];
        }

        $this->writeArchive($localPath, $catalogue, 5, $guide, 24);

        $hash = hash_file('sha256', $localPath);

        if ($hash === false) {
            throw new RuntimeException("Could not hash the exported workbook at [{$localPath}].");
        }

        return new SkillWorkbookExportResult(
            skills: count($skills),
            levels: $levels->count(),
            sha256: $hash,
            path: $localPath,
        );
    }

    /**
     * @param  list<list<mixed>>  $catalogue  Header row first, data from $catalogueHeader + 1.
     * @param  list<list<mixed>>  $guide  Header row first, data from $guideHeader + 1.
     */
    private function writeArchive(string $localPath, array $catalogue, int $catalogueHeader, array $guide, int $guideHeader): void
    {
        $zip = new ZipArchive;
        $opened = $zip->open($localPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException("Could not open [{$localPath}] for the workbook export.");
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->packageRels());
            $zip->addFromString('xl/workbook.xml', $this->workbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($catalogue, $catalogueHeader));
            $zip->addFromString('xl/worksheets/sheet2.xml', $this->sheet($guide, $guideHeader));

            if (! $zip->close()) {
                throw new RuntimeException("Could not finish the workbook export at [{$localPath}].");
            }

            return;
        } catch (Throwable $exception) {
            $zip->close();
            unlink($localPath);

            throw $exception;
        }
    }

    /** @param  list<list<mixed>>  $rows  Header row first. */
    private function sheet(array $rows, int $headerRow): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="'.self::MAIN.'"><sheetData>';

        $number = $headerRow;
        foreach ($rows as $values) {
            $xml .= '<row r="'.$number.'">';
            $column = 1;
            foreach ($values as $value) {
                $text = $this->cell($value);
                if ($text !== '') {
                    $xml .= '<c r="'.$this->address($column, $number).'" t="inlineStr"><is><t>'
                        .$this->escape($text).'</t></is></c>';
                }
                $column++;
            }
            $xml .= '</row>';
            $number++;
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return (string) $value;
    }

    private function address(int $column, int $row): string
    {
        $letters = '';
        while ($column > 0) {
            $column--;
            $letters = chr(65 + ($column % 26)).$letters;
            $column = intdiv($column, 26);
        }

        return $letters.$row;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
    }

    private function packageRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.self::REL.'/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="'.self::MAIN.'" xmlns:r="'.self::REL.'"><sheets>'
            .'<sheet name="02 Skill Catalogue" sheetId="1" r:id="rId1"/>'
            .'<sheet name="00 Guide" sheetId="2" r:id="rId2"/>'
            .'</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.self::REL.'/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="'.self::REL.'/worksheet" Target="worksheets/sheet2.xml"/>'
            .'</Relationships>';
    }
}
