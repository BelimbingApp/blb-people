{{--
    One Effectiveness area, two sections (#436). A tab appears only when the
    signed-in actor already holds that section's capability, so combining the
    two menu entries broadens nothing: an HR actor without the HOD review grant
    sees Summary alone, and a HOD without the aggregate grant sees Review alone.

    These are navigation tabs, not an ARIA tablist: each one is a distinct
    authorized route with its own server-side guard, so a link with
    aria-current="page" is both the honest semantics and the keyboard behaviour
    people expect from the rest of the app. A roving-tabindex tablist would
    imply the panels are already loaded and interchangeable, which they are not.
--}}
@php($effectivenessTabs = array_values(array_filter([
    $canReviewEffectiveness ? [
        'key' => 'review',
        'label' => __('Review'),
        'description' => __('Your department checkpoint questions and follow-ups'),
        'url' => route('people.training.effectiveness.index'),
    ] : null,
    $canSummarizeEffectiveness ? [
        'key' => 'summary',
        'label' => __('Summary'),
        'description' => __('Company answer rates and applied-rating means'),
        'url' => route('people.training.effectiveness.summary'),
    ] : null,
])))

@if (count($effectivenessTabs) > 1)
    <nav aria-label="{{ __('Training effectiveness sections') }}" data-testid="effectiveness-tabs">
        <ul class="flex flex-wrap gap-1 border-b border-edge">
            @foreach ($effectivenessTabs as $tab)
                <li>
                    <a
                        href="{{ $tab['url'] }}"
                        wire:navigate
                        @if ($tab['key'] === $activeEffectivenessTab) aria-current="page" @endif
                        title="{{ $tab['description'] }}"
                        class="-mb-px inline-block border-b-2 px-4 py-2 text-sm font-medium {{ $tab['key'] === $activeEffectivenessTab ? 'border-accent text-ink' : 'border-transparent text-muted hover:text-ink' }}"
                    >{{ $tab['label'] }}</a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
