<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Data\ClockEventAttributes;
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
            attrs: new ClockEventAttributes(
                timezone: $timezone,
                actorUserId: $actorUserId,
                evidence: array_merge($evidence, ['ip_address' => $ipAddress]),
                metadata: ['surface' => 'people.attendance.index'],
            ),
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
            attrs: new ClockEventAttributes(
                timezone: $attributes['timezone'] ?? null,
                actorUserId: $actorUserId,
                evidence: $attributes,
                metadata: $attributes['metadata'] ?? [],
            ),
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
            attrs: new ClockEventAttributes(
                timezone: $attributes['timezone'] ?? null,
                sourceSystem: $sourceSystem,
                sourceCode: $sourceCode,
                sourceLabel: $attributes['source_label'] ?? null,
                evidence: $attributes,
                metadata: $attributes['metadata'] ?? [],
            ),
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
            attrs: new ClockEventAttributes(
                timezone: $attributes['timezone'] ?? $correctedEvent->timezone,
                actorUserId: $actorUserId,
                correctsClockEventId: $correctedEvent->id,
                evidence: $attributes,
                metadata: array_merge($attributes['metadata'] ?? [], [
                    'correction_of_clock_event_id' => $correctedEvent->id,
                ]),
            ),
        );
    }

    private function record(
        Employee $employee,
        string $eventType,
        string $source,
        DateTimeInterface|string $occurredAt,
        ClockEventAttributes $attrs,
    ): AttendanceClockEvent {
        $this->assertEventType($eventType);

        $occurred = CarbonImmutable::parse($occurredAt, $attrs->timezone);
        $resolvedTimezone = $attrs->timezone ?? $occurred->timezoneName;
        $attendanceDate = $occurred->setTimezone($resolvedTimezone)->toDateString();

        return DB::transaction(function () use ($employee, $eventType, $source, $occurred, $resolvedTimezone, $attrs, $attendanceDate): AttendanceClockEvent {
            $day = app(AttendanceDayResolverService::class)->resolve($employee, $attendanceDate);

            if ($day->locked_at !== null || $day->status === AttendanceDay::STATUS_LOCKED) {
                throw AttendanceClockEventIngestionException::lockedAttendanceDay($day->id);
            }

            if ($day->status === AttendanceDay::STATUS_SCHEDULED) {
                $day->forceFill(['status' => AttendanceDay::STATUS_IN_PROGRESS])->save();
            }

            $clockEvent = AttendanceClockEvent::query()->create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'attendance_day_id' => $day->id,
                'attendance_geofence_id' => $attrs->evidence['attendance_geofence_id'] ?? null,
                'attendance_geofence_group_id' => $attrs->evidence['attendance_geofence_group_id'] ?? null,
                'event_type' => $eventType,
                'occurred_at' => $occurred,
                'timezone' => $resolvedTimezone,
                'source' => $source,
                'actor_user_id' => $attrs->actorUserId,
                'card_number' => $attrs->evidence['card_number'] ?? null,
                'device_identifier' => $attrs->evidence['device_identifier'] ?? null,
                'outlet_label' => $attrs->evidence['outlet_label'] ?? null,
                'ip_address' => $attrs->evidence['ip_address'] ?? null,
                'latitude' => $attrs->evidence['latitude'] ?? null,
                'longitude' => $attrs->evidence['longitude'] ?? null,
                'geofence_result' => $attrs->evidence['geofence_result'] ?? null,
                'photo_evidence_present' => (bool) ($attrs->evidence['photo_evidence_present'] ?? false),
                'corrects_clock_event_id' => $attrs->correctsClockEventId,
                'source_system' => $attrs->sourceSystem,
                'source_label' => $attrs->sourceLabel,
                'source_code' => $attrs->sourceCode,
                'metadata' => $attrs->metadata,
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
