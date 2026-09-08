<div class="mt-1 space-y-1 rounded bg-muted/20 p-2 text-xs">
    <div class="break-words font-medium text-ink">{{ $event->course_title_snapshot }}</div>
    <div class="text-muted"><x-ui.datetime :value="$event->starts_at" /></div>
    @if ($event->venue)
        <div class="break-words text-muted">{{ $event->venue }}</div>
    @endif
    <div class="text-muted">{{ ($counts[$event->id] ?? 0) . ' of ' . $event->capacity . ' enrolled' }}</div>
    @include('people::livewire.calendar.event-actions', ['event' => $event])
</div>
