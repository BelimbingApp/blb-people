<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('My performance reviews')"
        :subtitle="__('Reviews you released, with their correction history. Read-only.')"
    />

    @error('selected')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.card>
        <x-ui.table container="flush" :caption="__('Reviews you released')">
            <x-slot name="head">
                <tr>
                    <x-ui.th>{{ __('Period') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Version') }}</x-ui.th>
                    <x-ui.th>{{ __('Status') }}</x-ui.th>
                    <x-ui.th>{{ __('Outcome') }}</x-ui.th>
                    <x-ui.th>{{ __('Released rationale') }}</x-ui.th>
                    <x-ui.th>{{ __('Correction') }}</x-ui.th>
                </tr>
            </x-slot>
            @forelse ($reviews as $review)
                <tr wire:key="review-{{ $review['id'] }}" data-review-version="{{ $review['version'] }}">
                    <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $review['period'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $review['version'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $review['status'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $review['outcome_label'] }}</td>
                    {{-- The rationale of this version, exactly as it was released. --}}
                    <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $review['rationale'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                        @if ($review['correction_reason'] !== null)
                            <span class="block">{{ $review['correction_reason'] }}</span>
                            <span class="block text-xs">
                                {{ __('supersedes review') }} #{{ $review['supersedes_review_id'] }}
                                @if ($review['finalized_at'] !== null)
                                    · <x-ui.datetime :value="$review['finalized_at']" format="date" />
                                @endif
                            </span>
                        @else
                            <span class="text-xs">{{ __('—') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-table-cell-x py-10 text-center text-sm text-muted">
                        {{ __('You have not released any performance reviews.') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
