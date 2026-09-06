<?php

use App\Domains\People\Employees\Livewire\TrainingPassport;

/** @var TrainingPassport $this */
?>
<div>
    <x-slot name="title">{{ __('Training passport') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Training passport')"
            :subtitle="__('Your scheduled and completed training, certificates, and skills covered.')"
        />

        <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-muted">
            <span>{{ __('Generated') }} <x-ui.datetime :value="$passport->generatedAt" format="datetime" /></span>
            <span>{{ __('Read-only employee record') }}</span>
        </div>

        <x-ui.card>
            <div class="mb-4">
                <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Training events') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Attendance and event completion are shown separately from competence.') }}</p>
            </div>

            <div class="sm:hidden">
                @forelse ($passport->events as $event)
                    <div wire:key="passport-event-mobile-{{ $event->eventId }}" class="border-t border-border-default py-4 first:border-t-0 first:pt-0">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-ink">{{ $event->title }}</p>
                                <p class="text-xs text-muted"><x-ui.datetime :value="$event->startsAt" format="date" /></p>
                            </div>
                            <x-ui.badge :variant="$event->statusVariant">{{ $event->statusLabel }}</x-ui.badge>
                        </div>
                        <p class="mt-2 text-sm text-muted">
                            {{ $event->attended ? __('Attended') : __('Attendance not recorded') }}
                            · {{ __(':minutes minutes', ['minutes' => $event->actualMinutes]) }}
                        </p>
                    </div>
                @empty
                    <p class="py-8 text-center text-sm text-muted">{{ __('No training events recorded yet.') }}</p>
                @endforelse
            </div>

            <div class="hidden sm:block">
                <x-ui.table container="flush" :caption="__('Training events')" :row-hover="false">
                    <x-slot name="head">
                        <tr>
                            <x-ui.th>{{ __('Training') }}</x-ui.th>
                            <x-ui.th>{{ __('Date') }}</x-ui.th>
                            <x-ui.th>{{ __('Status') }}</x-ui.th>
                            <x-ui.th>{{ __('Attendance') }}</x-ui.th>
                            <x-ui.th align="right">{{ __('Minutes') }}</x-ui.th>
                        </tr>
                    </x-slot>

                    @forelse ($passport->events as $event)
                        <tr wire:key="passport-event-{{ $event->eventId }}">
                            <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $event->title }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink"><x-ui.datetime :value="$event->startsAt" format="date" /></td>
                            <td class="px-table-cell-x py-table-cell-y"><x-ui.badge :variant="$event->statusVariant">{{ $event->statusLabel }}</x-ui.badge></td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $event->attended ? __('Attended') : __('Attendance not recorded') }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $event->actualMinutes }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No training events recorded yet.') }}</td></tr>
                    @endforelse
                </x-ui.table>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4">
                <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Certificates') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Certificate validity does not by itself determine competence.') }}</p>
            </div>

            <x-ui.table container="flush" :caption="__('Certificates')" :row-hover="false">
                <x-slot name="head">
                    <tr><x-ui.th>{{ __('Certificate') }}</x-ui.th><x-ui.th>{{ __('Training') }}</x-ui.th><x-ui.th>{{ __('Valid until') }}</x-ui.th><x-ui.th>{{ __('State') }}</x-ui.th></tr>
                </x-slot>
                @forelse ($passport->certificates as $certificate)
                    <tr wire:key="passport-certificate-{{ $certificate->eventId }}-{{ $certificate->reference }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $certificate->reference }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $certificate->eventTitle }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $certificate->validUntil?->format('Y-m-d') ?? __('No expiry recorded') }}</td>
                        <td class="px-table-cell-x py-table-cell-y">
                            <span data-certificate-status="{{ $certificate->expired ? 'expired' : 'current' }}">
                                <x-ui.badge :variant="$certificate->statusVariant">{{ $certificate->statusLabel }}</x-ui.badge>
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No certificates recorded yet.') }}</td></tr>
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4">
                <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Skills covered') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('These are skills mapped to your training events; training does not finalize a Skills assessment.') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @forelse ($passport->skills as $skill)
                    <x-ui.badge wire:key="passport-skill-{{ $skill->skillId }}" variant="info">{{ $skill->name }} ({{ $skill->code }})</x-ui.badge>
                @empty
                    <p class="text-sm text-muted">{{ __('No skills are mapped to your training events yet.') }}</p>
                @endforelse
            </div>
        </x-ui.card>
    </div>
</div>
