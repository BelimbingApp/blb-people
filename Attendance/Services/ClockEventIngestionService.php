<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Exceptions\AttendanceClockEventIngestionException;
use App\Modules\People\Attendance\Models\AttendanceClockEvent;
use App\Modules\People\Attendance\Models\AttendanceDay;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class ClockEventIngestionService
{
    /** @param array<string, mixed> $evidence */
    public function recordWebClock(
        Employee $employee,
        string $eventType,
        int $actorUserId,
        ?string $ipAddress,
        DateTimeInterface|string|null $occurredAt = null,
        ?string $timezone = null,
        array $evidence = [],
    ): AttendanceClockEvent {
        return $this->record(
            employee: $employee,
            eventType: $eventType,
            source: AttendanceClockEvent::SOURCE_WEB,
            occurredAt: $occurredAt ?? now(),
            timezone: $timezone,
            actorUserId: $actorUserId,
            evidence: array_merge($evidence, ['ip_address' => $ipAddress]),
            metadata: ['surface' => 'people.attendance.index'],
        );
    }

    /** @param array<string, mixed> $attributes */
    public function recordManualClock(Employee $employee, string $eventType, DateTimeInterface|string $occurredAt, int $actorUserId, array $attributes = []): AttendanceClockEvent
    {
        return $this->record(
            employee: $employee,
            eventType: $eventType,
            source: AttendanceClockEvent::SOURCE_MANUAL,
            occurredAt: $occurredAt,
            timezone: $attributes['timezone'] ?? null,
            actorUserId: $actorUserId,
            evidence: $attributes,
            metadata: $attributes['metadata'] ?? [],
        );
    }

    /** @param array<string, mixed> $attributes */
    public function importClockEvent(Employee $employee, string $eventType, DateTimeInterface|string $occurredAt, string $sourceSystem, string $sourceCode, array $attributes = []): AttendanceClockEvent
    {
        return $this->record(
            employee: $employee,
            eventType: $eventType,
            source: AttendanceClockEvent::SOURCE_IMPORT,
            occurredAt: $occurredAt,
            timezone: $attributes['timezone'] ?? null,
            sourceSystem: $sourceSystem,
            sourceCode: $sourceCode,
            sourceLabel: $attributes['source_label'] ?? null,
            evidence: $attributes,
            metadata: $attributes['metadata'] ?? [],
        );
    }

    /** @param array<string, mixed> $attributes */
    public function correctClockEvent(AttendanceClockEvent $correctedEvent, string $eventType, DateTimeInterface|string $occurredAt, int $actorUserId, array $attributes = []): AttendanceClockEvent
    {
        $employee = $correctedEvent->employee()->firstOrFail();
        if ((int) $employee->company_id !== (int) $correctedEvent->company_id) {
            throw AttendanceClockEventIngestionException::correctedEventCompanyMismatch($correctedEvent->id);
        }

        return $this->record(
            employee: $employee,
            eventType: $eventType,
            source: AttendanceClockEvent::SOURCE_MANUAL,
            occurredAt: $occurredAt,
            timezone: $attributes['timezone'] ?? $correctedEvent->timezone,
            actorUserId: $actorUserId,
            correctsClockEventId: $correctedEvent->id,
            evidence: $attributes,
            metadata: array_merge($attributes['metadata'] ?? [], [
                'correction_of_clock_event_id' => $correctedEvent->id,
            ]),
        );
    }

    /** @param array<string, mixed> $evidence */
    private function record(
        Employee $employee,
        string $eventType,
        string $source,
        DateTimeInterface|string $occurredAt,
        ?string $timezone = null,
        ?int $actorUserId = null,
        ?string $sourceSystem = null,
        ?string $sourceLabel = null,
        ?string $sourceCode = null,
        ?int $correctsClockEventId = null,
        array $evidence = [],
        array $metadata = [],
    ): AttendanceClockEvent {
        $this->assertEventType($eventType);

        $occurred = CarbonImmutable::parse($occurredAt, $timezone);
        $resolvedTimezone = $timezone ?? $occurred->timezoneName;
        $attendanceDate = $occurred->setTimezone($resolvedTimezone)->toDateString();

        return DB::transaction(function () use ($employee, $eventType, $source, $occurred, $resolvedTimezone, $actorUserId, $sourceSystem, $sourceLabel, $sourceCode, $correctsClockEventId, $evidence, $metadata, $attendanceDate): AttendanceClockEvent {
            $day = AttendanceDay::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $attendanceDate)
                ->first();

            if ($day === null) {
                $day = AttendanceDay::query()->create([
                    'company_id' => $employee->company_id,
                    'employee_id' => $employee->id,
                    'attendance_date' => $attendanceDate,
                    'status' => AttendanceDay::STATUS_IN_PROGRESS,
                    'day_type' => 'normal',
                    'expected_minutes' => 480,
                    'payroll_period_date' => $attendanceDate,
                    'metadata' => ['source' => 'clock-event-ingestion'],
                ]);
            }

            if ($day->locked_at !== null || $day->status === AttendanceDay::STATUS_LOCKED) {
                throw AttendanceClockEventIngestionException::lockedAttendanceDay($day->id);
            }

            $clockEvent = AttendanceClockEvent::query()->create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'attendance_day_id' => $day->id,
                'attendance_geofence_id' => $evidence['attendance_geofence_id'] ?? null,
                'attendance_geofence_group_id' => $evidence['attendance_geofence_group_id'] ?? null,
                'event_type' => $eventType,
                'occurred_at' => $occurred,
                'timezone' => $resolvedTimezone,
                'source' => $source,
                'actor_user_id' => $actorUserId,
                'card_number' => $evidence['card_number'] ?? null,
                'device_identifier' => $evidence['device_identifier'] ?? null,
                'outlet_label' => $evidence['outlet_label'] ?? null,
                'ip_address' => $evidence['ip_address'] ?? null,
                'latitude' => $evidence['latitude'] ?? null,
                'longitude' => $evidence['longitude'] ?? null,
                'geofence_result' => $evidence['geofence_result'] ?? null,
                'photo_evidence_present' => (bool) ($evidence['photo_evidence_present'] ?? false),
                'corrects_clock_event_id' => $correctsClockEventId,
                'source_system' => $sourceSystem,
                'source_label' => $sourceLabel,
                'source_code' => $sourceCode,
                'metadata' => $metadata,
            ]);

            app(AttendanceDayProjectionService::class)->project($day)->save();

            return $clockEvent;
        });
    }

    private function assertEventType(string $eventType): void
    {
        if (! in_array($eventType, [
            AttendanceClockEvent::TYPE_IN,
            AttendanceClockEvent::TYPE_OUT,
            AttendanceClockEvent::TYPE_BREAK_OUT,
            AttendanceClockEvent::TYPE_BREAK_IN,
        ], true)) {
            throw AttendanceClockEventIngestionException::invalidEventType($eventType);
        }
    }
}
