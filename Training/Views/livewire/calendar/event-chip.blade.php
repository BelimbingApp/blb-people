<div class="mt-1 rounded bg-muted/20 p-1 text-xs">
    <div class="font-medium">{{ $event->course_title_snapshot }}</div>
    <div class="text-muted">{{ ($counts[$event->id] ?? 0) . ' of ' . $event->capacity . ' enrolled' }}</div>
    @include('people::livewire.calendar.event-actions', ['event' => $event])
</div>
