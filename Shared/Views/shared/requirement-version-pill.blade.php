{{-- Requirement version pill. Links the exact pinned version; the URL arrives from the owning page. --}}
<a href="{{ $url }}" class="inline-flex items-center gap-1.5 rounded-full border border-sky-800/25 bg-sky-50 px-2.5 py-0.5 text-xs font-medium text-sky-900 hover:bg-sky-100">
    <span class="font-semibold">{{ $reference }}</span>
    <span class="tabular-nums">{{ __('v:version', ['version' => $version]) }}</span>
    @if ($status !== null)
        <span class="opacity-80">· {{ $status }}</span>
    @endif
</a>
