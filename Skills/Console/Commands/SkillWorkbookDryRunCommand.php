<?php

namespace App\Domains\People\Skills\Console\Commands;

use App\Domains\People\Skills\Import\SkillWorkbookReader;
use App\Domains\People\Skills\Import\SkillWorkbookResult;
use App\Domains\People\Skills\Import\UnreadableSkillWorkbook;
use App\Domains\People\Skills\Import\WorkbookSource;
use Illuminate\Console\Command;

/** Parse and report the supported workbook records without persisting them. */
final class SkillWorkbookDryRunCommand extends Command
{
    protected $signature = 'people:skills-workbook-dry-run
                            {workbook : Path to the local XLSX workbook}';

    protected $description = 'Parse the skill catalogue workbook and report candidate records without writing them';

    public function handle(SkillWorkbookReader $reader): int
    {
        $blockingKinds = config('people-skills.workbook.blocking_defects');

        if (! is_array($blockingKinds)
            || ! array_is_list($blockingKinds)
            || array_any($blockingKinds, static fn (mixed $kind): bool => ! is_string($kind))) {
            $this->error('people-skills.workbook.blocking_defects must be a list of defect names.');

            return self::FAILURE;
        }

        try {
            $result = $reader->read((string) $this->argument('workbook'));
        } catch (UnreadableSkillWorkbook $exception) {
            $this->error($exception->getMessage());
            $this->line('Database writes: 0');

            return self::FAILURE;
        }

        $this->line('Workbook SHA-256: '.$this->workbookHash($result));
        $this->line(sprintf(
            '02 Skill Catalogue: skills=%d, category occurrences=%d',
            count($result->skills),
            count($result->categories),
        ));
        $this->line(sprintf('00 Guide: proficiency levels=%d', count($result->levels)));
        $this->newLine();
        $this->line('Candidate records (before identity resolution):');

        foreach ($result->skills as $skill) {
            $this->record('skill', $skill, $skill->source);
        }

        foreach ($result->categories as $category) {
            $this->record('category occurrence', $category, $category->source);
        }

        foreach ($result->levels as $level) {
            $this->record('proficiency level', $level, $level->source);
        }

        $blocking = 0;
        foreach ($result->defects as $defect) {
            $isBlocking = in_array($defect->kind, $blockingKinds, true);
            $blocking += (int) $isBlocking;
            $this->line(sprintf(
                '[%s] %s at %s!%s | provenance sha256=%s row=%d',
                $isBlocking ? 'blocking' : 'warning',
                $defect->kind,
                $defect->source->sheet,
                $defect->cell,
                $defect->source->sha256,
                $defect->source->row,
            ));
        }

        $this->line(sprintf('Defects: %d (blocking: %d)', count($result->defects), $blocking));
        $this->line('Database writes: 0');

        return $blocking > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function record(string $type, object $record, WorkbookSource $source): void
    {
        $payload = get_object_vars($record);
        unset($payload['source']);

        $this->line(sprintf(
            '- %s %s | provenance sha256=%s sheet=%s row=%d',
            $type,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $source->sha256,
            json_encode($source->sheet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $source->row,
        ));
    }

    private function workbookHash(SkillWorkbookResult $result): string
    {
        $row = $result->skills[0] ?? $result->categories[0] ?? $result->levels[0] ?? null;

        return $row?->source->sha256 ?? $result->defects[0]?->source->sha256 ?? 'unavailable';
    }
}
