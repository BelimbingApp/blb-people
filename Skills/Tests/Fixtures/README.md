# Skill workbook fixture

skill-catalogue.xlsx is a synthetic, two-sheet XLSX matching the source layout in
[the workbook map](../../../docs/plans/0006-a-workbook-sheet-map.md).
It contains two invented skills, one repeated category label and six invented
proficiency levels. No source employee, department, assessment or training rows
were copied. The source column headers and table positions are preserved.

Tests alter copies of this fixture to inject formulas, merges, missing keys,
malformed XML and broken relationships; they delete those copies after each run.
The fixture is never modified in place.

SkillWorkbookReader::read($localPath) returns typed raw rows and defects. Each
row and defect carries the SHA-256 of the complete workbook, sheet and row.
Category rows are occurrences, so repeated labels retain separate provenance.
The Guide table is a proposal from the source, not published policy.

The supported layout is deliberately narrow: 02 Skill Catalogue A5:K with
data from row 6; 00 Guide A24:F30 with six data rows. Other sheets, narrative,
decorative merges outside these tables and unrelated columns are not imported.
Missing sheets or unexpected headers refuse the file. Formula/error cells,
overlapping merges and blank skill/category/level keys exclude affected rows
and produce coordinate-bearing defects. Cached formula results are never used.
Entirely blank catalogue rows are ignored; missing guide levels are defects.
Consumers must inspect defects before any later import step.

Values retain source text (including zero and blank optional values). The reader
does not resolve business IDs, coerce domain enums, validate policy, deduplicate
categories, execute formulas, write database records or expose a UI. It uses the
platform's existing ZIP and DOM extensions and bounds archive/XML size.

The export command people:skills-workbook-export writes this same layout from
the company's live catalogue: headers and table positions come from
SkillWorkbookReader::TABLES so the file cannot drift from what the dry run
accepts, and the written file reads back with zero defects. This fixture stays
the reader-side synthetic source and is still never modified in place.

## Assessment-log fixture

skill-assessment-log.xlsx is the same synthetic two-sheet workbook plus a
third sheet, `04 Assessment Log` (A5:L, data from row 6), holding three
invented assessment rows over the two invented skills and two invented Staff
IDs (EMP-00001, EMP-00002). No source assessment rows were copied. The sheet
is opt-in: `SkillWorkbookReader::read($path, [SkillWorkbookReader::ASSESSMENT_LOG])`
reads it and refuses a workbook without it; a plain `read($path)` never looks
for it, so skill-catalogue.xlsx keeps reading as before. Blank Staff ID or
Skill ID cells, formulas, error cells and merges are defects exactly as on
the catalogue sheet.

`people:skills-assessment-log-dry-run` resolves those rows against one
company (Staff ID by employee number through the workforce directory, Skill
ID by catalogue code, level by the published standard scale, dates against
today) and reports reason codes with cell references. It writes nothing.
Tests alter copies of this fixture the same way as the catalogue tests.
