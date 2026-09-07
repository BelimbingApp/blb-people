<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training evidence')"
        :subtitle="__('Submit a reflection, certificate details, and one supporting document for training you attended. HR will confirm the evidence before it becomes authoritative.')"
    />

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No company is attributed to your employee account.') }}</x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex flex-wrap gap-2 text-sm">
                @foreach ($companies as $entityId => $name)
                    <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">
                        {{ $name }}
                    </x-ui.button>
                @endforeach
            </div>
        @endif

        @if (session('training-evidence-status'))
            <x-ui.alert variant="success">{{ session('training-evidence-status') }}</x-ui.alert>
        @endif
        @error('evidence')
            <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
        @enderror

        @if ($events === [])
            <x-ui.alert variant="info">{{ __('No attended training is ready for evidence submission. Completed attendance will appear here after it is recorded.') }}</x-ui.alert>
        @else
            <section class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-ink">{{ __('Submission details') }}</h2>
                    <p class="mt-1 max-w-prose text-sm text-muted">{{ __('These details apply to the training event you choose below. Your uploaded document is stored as governed evidence.') }}</p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="training-evidence-reflection" class="block text-sm font-medium text-ink">{{ __('Reflection') }}</label>
                        <textarea id="training-evidence-reflection" wire:model="reflection" rows="4" class="mt-1 block w-full rounded border-border bg-surface text-ink shadow-sm focus:border-primary focus:ring-primary" placeholder="{{ __('What did you learn, and how will you apply it?') }}"></textarea>
                        @error('reflection')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <x-ui.input type="text" wire:model="certificateNumber" :label="__('Certificate number (optional)')" />
                        @error('certificateNumber')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <x-ui.input type="date" wire:model="certificateExpiresOn" :label="__('Certificate expiry (optional)')" />
                        @error('certificateExpiresOn')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div class="md:col-span-2">
                        <label for="training-evidence-document" class="block text-sm font-medium text-ink">{{ __('Supporting document') }}</label>
                        <input id="training-evidence-document" type="file" wire:model="document" class="mt-1 block w-full text-sm text-ink file:mr-4 file:rounded file:border-0 file:bg-surface-subtle file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-ink hover:file:bg-surface-subtle/80" />
                        <p class="mt-1 text-sm text-muted">{{ __('One file up to 10 MB. Active web documents are refused for safety.') }}</p>
                        @error('document')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            <section class="space-y-4">
                <h2 class="text-lg font-semibold text-ink">{{ __('Attended training') }}</h2>
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Training') }}</x-ui.th>
                            <x-ui.th>{{ __('Date') }}</x-ui.th>
                            <x-ui.th>{{ __('Status') }}</x-ui.th>
                            <x-ui.th>{{ __('Action') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($events as $event)
                            <tr wire:key="training-evidence-event-{{ $event['event_id'] }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $event['title'] }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $event['starts_at']->format('j M Y') }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                    @if ($event['confirmed'])
                                        {{ __('Confirmed by HR') }}
                                    @elseif ($event['submitted'])
                                        {{ __('Pending HR confirmation') }}
                                    @else
                                        {{ __('Ready for evidence') }}
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm">
                                    @if ($event['confirmed'])
                                        <span class="text-muted">{{ __('Ask HR for a correction') }}</span>
                                    @elseif ($event['submitted'])
                                        <span class="text-muted">{{ __('Submitted') }}</span>
                                    @else
                                        <x-ui.button type="button" variant="primary" wire:click="submit({{ $event['event_id'] }})" wire:loading.attr="disabled" wire:target="submit,document">
                                            {{ __('Submit evidence') }}
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            </section>
        @endif
    @endif
</div>
