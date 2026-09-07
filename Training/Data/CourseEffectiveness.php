<?php

namespace App\Domains\People\Training\Data;

/** What one course's training looks like thirty, sixty and ninety days on. */
final readonly class CourseEffectiveness
{
    /**
     * @param  array<string, CheckpointEffectiveness>  $checkpoints  keyed by EffectivenessCheckpoint value
     * @param  list<EffectivenessComment>  $comments
     */
    public function __construct(
        public int $courseId,
        public string $courseTitle,
        public array $checkpoints,
        public array $comments,
    ) {}
}
