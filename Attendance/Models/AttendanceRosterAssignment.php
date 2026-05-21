<?php

namespace App\Modules\People\Attendance\Models;

use App\Base\Database\Concerns\BelongsToCompany;
use App\Base\Database\Concerns\BelongsToEmployee;
use App\Base\Database\Concerns\HasEffectiveDateRange;
use App\Base\Database\Concerns\TracksExternalSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class AttendanceRosterAssignment extends Model
{
    use BelongsToCompany;
    use BelongsToEmployee;
    use HasEffectiveDateRange;
    use TracksExternalSource;

    protected $table = 'people_attendance_roster_assignments';

    protected $fillable = [
        ...self::COMPANY_FILLABLE,
        ...self::EMPLOYEE_FILLABLE,
        'attendance_roster_pattern_id',
        'attendance_shift_template_id',
        'attendance_policy_group_id',
        'cohort_predicate',
        ...self::EFFECTIVE_DATE_RANGE_FILLABLE,
        'publish_state',
        'lock_state',
        'revision',
        'exceptions',
        ...self::EXTERNAL_SOURCE_FILLABLE,
    ];

    protected function casts(): array
    {
        return [
            'cohort_predicate' => 'array',
            ...self::EFFECTIVE_DATE_RANGE_CASTS,
            'revision' => 'integer',
            'exceptions' => 'array',
            ...self::EXTERNAL_SOURCE_CASTS,
        ];
    }

    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(AttendanceShiftTemplate::class, 'attendance_shift_template_id');
    }

    public function policyGroup(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicyGroup::class, 'attendance_policy_group_id');
    }

    public function rosterPattern(): BelongsTo
    {
        return $this->belongsTo(AttendanceRosterPattern::class, 'attendance_roster_pattern_id');
    }

    /**
     * @return array{name: string, id: int}|null
     */
    public function getAuditSubject(): ?array
    {
        return $this->employee_id !== null
            ? ['name' => 'employee', 'id' => (int) $this->employee_id]
            : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAuditSubjectEntries(string $event, array $oldValues = [], array $newValues = []): array
    {
        if ($this->employee_id === null) {
            return [];
        }

        $changed = array_keys($newValues);

        if ($event === 'created') {
            return $this->buildRangeAuditEntries(
                $event,
                $this->effective_from?->toDateString() ?? '',
                $this->effective_to?->toDateString(),
                null,
                null,
                $this->attendance_shift_template_id,
                $this->attendance_policy_group_id,
            );
        }

        if ($event === 'deleted') {
            return $this->buildRangeAuditEntries(
                $event,
                $this->effective_from?->toDateString() ?? '',
                $this->effective_to?->toDateString(),
                $this->attendance_shift_template_id,
                $this->attendance_policy_group_id,
                null,
                null,
            );
        }

        if ($event !== 'updated') {
            return [];
        }

        if (in_array('exceptions', $changed, true)) {
            return $this->buildExceptionAuditEntries();
        }

        if (array_intersect($changed, ['attendance_shift_template_id', 'attendance_policy_group_id', 'effective_from', 'effective_to']) === []) {
            return [];
        }

        return $this->buildUpdatedRangeAuditEntries(
            $event,
            $this->dateString($this->getOriginal('effective_from')) ?? '',
            $this->dateString($this->getOriginal('effective_to')),
            $this->effective_from?->toDateString() ?? '',
            $this->effective_to?->toDateString(),
            $this->getOriginal('attendance_shift_template_id'),
            $this->getOriginal('attendance_policy_group_id'),
            $this->attendance_shift_template_id,
            $this->attendance_policy_group_id,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRangeAuditEntries(
        string $event,
        string $from,
        ?string $to,
        mixed $oldShiftId,
        mixed $oldPolicyId,
        mixed $newShiftId,
        mixed $newPolicyId,
    ): array {
        $dates = $this->expandedDates($from, $to);

        if ($dates === []) {
            return [];
        }

        $shiftCodes = $this->shiftCodes([$oldShiftId, $newShiftId]);
        $policyCodes = $this->policyCodes([$oldPolicyId, $newPolicyId]);
        $oldValues = $this->auditSummary($oldShiftId, $oldPolicyId, $shiftCodes, $policyCodes);
        $newValues = $this->auditSummary($newShiftId, $newPolicyId, $shiftCodes, $policyCodes);

        return array_map(
            fn (string $date): array => $this->auditEntry($event, $date, $oldValues, $newValues),
            $dates,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildUpdatedRangeAuditEntries(
        string $event,
        string $oldFrom,
        ?string $oldTo,
        string $newFrom,
        ?string $newTo,
        mixed $oldShiftId,
        mixed $oldPolicyId,
        mixed $newShiftId,
        mixed $newPolicyId,
    ): array {
        $oldDates = $this->expandedDates($oldFrom, $oldTo);
        $newDates = $this->expandedDates($newFrom, $newTo);

        $dates = array_values(array_unique([...$oldDates, ...$newDates]));
        sort($dates);

        if ($dates === []) {
            return [];
        }

        $shiftCodes = $this->shiftCodes([$oldShiftId, $newShiftId]);
        $policyCodes = $this->policyCodes([$oldPolicyId, $newPolicyId]);
        $oldSummary = $this->auditSummary($oldShiftId, $oldPolicyId, $shiftCodes, $policyCodes);
        $newSummary = $this->auditSummary($newShiftId, $newPolicyId, $shiftCodes, $policyCodes);
        $oldLookup = array_flip($oldDates);
        $newLookup = array_flip($newDates);
        $entries = [];

        foreach ($dates as $date) {
            $oldValues = isset($oldLookup[$date]) ? $oldSummary : null;
            $newValues = isset($newLookup[$date]) ? $newSummary : null;

            if ($oldValues === $newValues) {
                continue;
            }

            $entries[] = $this->auditEntry($event, $date, $oldValues, $newValues);
        }

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildExceptionAuditEntries(): array
    {
        $oldExceptions = $this->parseExceptions($this->getOriginal('exceptions'));
        $newExceptions = collect($this->exceptions ?? []);
        $dates = $newExceptions->pluck('date')
            ->merge($oldExceptions->pluck('date'))
            ->unique()
            ->filter(fn (mixed $date): bool => is_string($date) && $date !== '')
            ->values();

        $ids = [];
        foreach ($dates as $date) {
            $oldEntry = $oldExceptions->firstWhere('date', $date);
            $newEntry = $newExceptions->firstWhere('date', $date);
            $ids[] = is_array($oldEntry) ? ($oldEntry['attendance_shift_template_id'] ?? null) : null;
            $ids[] = is_array($oldEntry) ? ($oldEntry['attendance_policy_group_id'] ?? null) : null;
            $ids[] = is_array($newEntry) ? ($newEntry['attendance_shift_template_id'] ?? null) : null;
            $ids[] = is_array($newEntry) ? ($newEntry['attendance_policy_group_id'] ?? null) : null;
        }

        $ids[] = $this->getOriginal('attendance_shift_template_id');
        $ids[] = $this->getOriginal('attendance_policy_group_id');
        $ids[] = $this->attendance_shift_template_id;
        $ids[] = $this->attendance_policy_group_id;

        $shiftCodes = $this->shiftCodes($ids);
        $policyCodes = $this->policyCodes($ids);
        $entries = [];

        foreach ($dates as $date) {
            $oldEntry = $oldExceptions->firstWhere('date', $date);
            $newEntry = $newExceptions->firstWhere('date', $date);

            $oldShift = is_array($oldEntry) ? ($oldEntry['attendance_shift_template_id'] ?? null) : $this->getOriginal('attendance_shift_template_id');
            $oldPolicy = is_array($oldEntry) ? ($oldEntry['attendance_policy_group_id'] ?? null) : $this->getOriginal('attendance_policy_group_id');
            $newShift = is_array($newEntry) ? ($newEntry['attendance_shift_template_id'] ?? null) : $this->attendance_shift_template_id;
            $newPolicy = is_array($newEntry) ? ($newEntry['attendance_policy_group_id'] ?? null) : $this->attendance_policy_group_id;

            if ($oldShift === $newShift && $oldPolicy === $newPolicy) {
                continue;
            }

            $entries[] = $this->auditEntry(
                'updated',
                (string) $date,
                $this->auditSummary($oldShift, $oldPolicy, $shiftCodes, $policyCodes),
                $this->auditSummary($newShift, $newPolicy, $shiftCodes, $policyCodes),
            );
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function expandedDates(string $from, ?string $to): array
    {
        if ($from === '') {
            return [];
        }

        try {
            $start = CarbonImmutable::parse($from);
            $end = $to !== null ? CarbonImmutable::parse($to) : $start->addDays(365);
        } catch (\Throwable) {
            return [];
        }

        if ($end->diffInDays($start) >= 366) {
            $end = $start->addDays(365);
        }

        $dates = [];
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->toDateString();
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->toDateString();
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function shiftCodes(array $ids): array
    {
        return AttendanceShiftTemplate::query()
            ->whereKey($this->normalizedIds($ids))
            ->pluck('code', 'id')
            ->all();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function policyCodes(array $ids): array
    {
        return AttendancePolicyGroup::query()
            ->whereKey($this->normalizedIds($ids))
            ->pluck('code', 'id')
            ->all();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function normalizedIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (mixed $id): ?int => $id !== null ? (int) $id : null, $ids),
        )));
    }

    /**
     * @param  array<int, string>  $shiftCodes
     * @param  array<int, string>  $policyCodes
     * @return array{shift_code: string|null, policy_code: string|null}|null
     */
    private function auditSummary(mixed $shiftId, mixed $policyId, array $shiftCodes, array $policyCodes): ?array
    {
        if ($shiftId === null && $policyId === null) {
            return null;
        }

        return [
            'shift_code' => $shiftId !== null ? ($shiftCodes[(int) $shiftId] ?? (string) $shiftId) : null,
            'policy_code' => $policyId !== null ? ($policyCodes[(int) $policyId] ?? (string) $policyId) : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @return array<string, mixed>
     */
    private function auditEntry(string $event, string $date, ?array $oldValues, ?array $newValues): array
    {
        return [
            'subject_name' => 'employee',
            'subject_id' => (int) $this->employee_id,
            'subject_identifier' => $date,
            'event' => $event,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ];
    }

    private function parseExceptions(mixed $raw): Collection
    {
        if (is_array($raw)) {
            return collect($raw);
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return collect(is_array($decoded) ? $decoded : []);
        }

        return collect();
    }
}
