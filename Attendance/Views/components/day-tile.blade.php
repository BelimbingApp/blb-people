{{--
    Day tile: a single calendar cell for any (employee × date) or (date) surface
    in BLB. Encodes the day-type tint (Normal/Rest/Off/Holiday). Assigned cells
    render their slot content inside an accent pill; empty non-working cells show
    a day-type label; empty normal cells render nothing.

    Props:
        dayType     — normal | rest | off | holiday (per AttendanceDay::DAY_TYPE_*)
        emptyLabel  — Label for non-working empty days. Defaults to vocabulary label.
        state       — Retained for callers that still pass it; no longer drives styling.
        tooltip     — Optional title attribute for hover hints.
        empty       — true renders the empty placeholder; false renders the slot.

    Slot:
        Default — content rendered when not empty (inside the accent pill).

    Usage:
        <x-people-attendance::day-tile day-type="rest" empty />
        <x-people-attendance::day-tile day-type="normal">
            <span class="text-[12px] font-semibold">DAY</span>
        </x-people-attendance::day-tile>
--}}
@use('App\Modules\People\Attendance\Support\DayTypeVocabulary')
@props([
    'dayType' => 'normal',
    'state' => null,
    'tooltip' => null,
    'empty' => false,
    'emptyLabel' => null,
])

@php
    $surfaceClass = DayTypeVocabulary::surfaceClass($dayType);
    $inkClass = DayTypeVocabulary::inkClass($dayType);
    $isNonWorking = DayTypeVocabulary::isNonWorking($dayType);
    $resolvedEmptyLabel = $emptyLabel ?? DayTypeVocabulary::label($dayType);
@endphp

<div {{ $attributes->merge(['class' => $surfaceClass.' group px-1 py-1 text-center align-top', 'title' => $tooltip]) }}>
    @if ($empty)
        @if ($isNonWorking)
            <span class="text-[10px] font-medium uppercase tracking-wide {{ $inkClass }}">{{ $resolvedEmptyLabel }}</span>
        @endif
    @else
        <div class="inline-flex flex-col items-center rounded-full bg-accent/10 text-accent px-2.5 py-0.5">
            {{ $slot }}
        </div>
    @endif
</div>
