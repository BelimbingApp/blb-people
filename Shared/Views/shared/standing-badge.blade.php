{{-- Proficiency badge. Color supplements the band label; it never carries status alone. --}}
<span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $bandTone() === 'tone-met' ? 'border-green-700/30 bg-green-50 text-green-800' : ($bandTone() === 'tone-gap' ? 'border-red-700/30 bg-red-50 text-red-800' : 'border-stone-400/40 bg-stone-100 text-stone-700') }}">
    <span class="font-semibold">{{ $skill }}</span>
    <span aria-label="{{ __('Proficiency standing') }}">
        @if ($resultBand !== null)
            {{ $resultBand }}
        @else
            {{ __('unassessed') }}
        @endif
    </span>
    @if ($assessedLevel !== null && $requiredLevel !== null)
        <span class="tabular-nums">{{ __('Level :assessed of required :required', ['assessed' => $assessedLevel, 'required' => $requiredLevel]) }}</span>
    @endif
    @if ($assessedAt !== null)
        <span class="text-[11px] opacity-80">{{ __('assessed :date', ['date' => $assessedAt]) }}</span>
    @endif
</span>
