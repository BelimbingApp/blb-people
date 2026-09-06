<?php

use App\Domains\People\Employees\Livewire\MyStanding;

/** @var MyStanding $this */
?>
<div>
    <x-slot name="title">{{ __('My skill standing') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('My skill standing')"
            :subtitle="__('Your published skill results and current requirements.')"
        />

        @if ($unavailable !== null)
            <x-ui.alert variant="warning">{{ $unavailable }}</x-ui.alert>
        @elseif ($standing !== null)
            <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-muted">
                <span>
                    {{ __('Generated') }}
                    <x-ui.datetime :value="$standing->skills->generatedAt" format="datetime" />
                </span>
                <span>
                    {{ __('Workforce data observed') }}
                    <x-ui.datetime :value="$standing->skills->workforceObservedAt" format="datetime" />
                </span>
            </div>

            <x-ui.card>
                <div class="mb-4">
                    <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Current standing') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Only finalized assessments used by your current standing are shown.') }}</p>
                </div>

                <div class="sm:hidden">
                    @forelse ($standing->skills->standing as $outcome)
                        <div wire:key="mobile-standing-assessment-{{ $outcome->assessmentId }}" class="border-t border-border-default py-4 first:border-t-0 first:pt-0">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium text-ink">{{ $outcome->requirementReference }}</p>
                                    <p class="text-xs text-muted">{{ __('Version :version', ['version' => $outcome->requirementVersion]) }}</p>
                                </div>
                                <x-ui.badge :variant="$this->standingVariant($outcome)">{{ $this->standingLabel($outcome) }}</x-ui.badge>
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-3">
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Level') }}</dt>
                                    <dd class="text-sm tabular-nums text-ink">{{ __(':assessed of :required', ['assessed' => $outcome->assessedLevel ?? __('Not assessed'), 'required' => $outcome->requiredLevel]) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Valid until') }}</dt>
                                    <dd class="text-sm text-ink">
                                        @if ($outcome->validUntil !== null)
                                            <x-ui.datetime :value="$outcome->validUntil" format="date" />
                                        @else
                                            {{ __('No expiry recorded') }}
                                        @endif
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    @empty
                        <p class="py-8 text-center text-sm text-muted">{{ __('No published skill standing is available yet.') }}</p>
                    @endforelse
                </div>

                <div class="hidden sm:block">
                    <x-ui.table container="flush" :caption="__('Current skill standing')" :row-hover="false">
                        <x-slot name="head">
                            <tr>
                                <x-ui.th>{{ __('Requirement') }}</x-ui.th>
                                <x-ui.th>{{ __('Standing') }}</x-ui.th>
                                <x-ui.th align="right">{{ __('Assessed level') }}</x-ui.th>
                                <x-ui.th align="right">{{ __('Required level') }}</x-ui.th>
                                <x-ui.th>{{ __('Valid until') }}</x-ui.th>
                            </tr>
                        </x-slot>

                        @forelse ($standing->skills->standing as $outcome)
                            <tr wire:key="standing-assessment-{{ $outcome->assessmentId }}">
                            <td class="px-table-cell-x py-table-cell-y">
                                <div class="text-sm font-medium text-ink">{{ $outcome->requirementReference }}</div>
                                <div class="text-xs text-muted">{{ __('Version :version', ['version' => $outcome->requirementVersion]) }}</div>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y">
                                <x-ui.badge :variant="$this->standingVariant($outcome)">
                                    {{ $this->standingLabel($outcome) }}
                                </x-ui.badge>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">
                                {{ $outcome->assessedLevel ?? __('Not assessed') }}
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">
                                {{ $outcome->requiredLevel }}
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                @if ($outcome->validUntil !== null)
                                    <x-ui.datetime :value="$outcome->validUntil" format="date" />
                                @else
                                    {{ __('No expiry recorded') }}
                                @endif
                            </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-10 text-center text-sm text-muted">
                                    {{ __('No published skill standing is available yet.') }}
                                </td>
                            </tr>
                        @endforelse
                    </x-ui.table>
                </div>
            </x-ui.card>

            <x-ui.alert variant="info">
                {{ __('Training history is not available from the current People source.') }}
            </x-ui.alert>
        @endif
    </div>
</div>
