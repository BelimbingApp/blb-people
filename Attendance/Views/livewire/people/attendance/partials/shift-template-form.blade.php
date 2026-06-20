<div class="flex items-center justify-between gap-3">
    <button type="button" wire:click="cancelShiftEdit" class="inline-flex items-center gap-1 text-sm font-medium text-muted transition hover:text-accent">
        <x-icon name="heroicon-o-arrow-left" class="h-4 w-4" />
        {{ __('Back to shifts') }}
    </button>
    <p class="text-sm font-medium text-ink">
        {{ $editingShiftTemplateId === null ? __('New shift') : __('Editing :code', ['code' => $shiftCode ?: '—']) }}
    </p>
</div>

{{-- Templates are a creation affordance only — hide once a saved shift is loaded for edit or duplicate. --}}
@if ($selectedShiftTemplateKey !== 'saved-shift')
    <x-ui.template-picker
        :templates="$shiftTemplatePresets"
        :selected-key="$selectedShiftTemplateKey"
        :show-all="$showAllShiftTemplates"
        select-action="useShiftTemplate"
        upload-action="$set('showShiftTemplateImportModal', true)"
    />
@endif

@if ($showShiftBuilderForm)
    <form wire:submit="saveShiftTemplate" class="space-y-4">
        @if ($errors->any())
            <x-ui.alert variant="danger">
                <p class="font-medium">{{ __('Fix these before saving:') }}</p>
                <ul class="mt-2 list-disc pl-5 text-sm">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        {{-- Identification --}}
        <x-ui.card>
            <div>
                <h2 class="text-base font-semibold text-ink">{{ __('Identification') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('How this shift appears in rosters, policy validation and audit logs.') }}</p>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <x-ui.input id="attendance-shift-code" wire:model="shiftCode" label="{{ __('Shift code') }}" placeholder="{{ __('OFFICE_DAY') }}" required help="{{ __('Short reference supervisors see while building rosters.') }}" :error="$errors->first('shiftCode')" />
                <x-ui.input id="attendance-shift-name" wire:model="shiftName" label="{{ __('Shift name') }}" placeholder="{{ __('Office day') }}" required help="{{ __('Human-readable name for this scheduled work pattern.') }}" :error="$errors->first('shiftName')" />
            </div>
        </x-ui.card>

        {{-- ── Work schedule + punch grace (unified card) ───────────────────── --}}
        <div
            x-data="shiftBuilder"
            style="--sb-dial-day:var(--color-surface-card,#faf9f5);--sb-dial-night:var(--color-surface-pinned,#dfd9cf);--sb-break:#7a7548;--sb-tea:#5c7f99;"
        >
        <div class="bg-surface-card border border-border-default rounded-2xl shadow-sm">

            {{-- Card header --}}
            <div class="px-6 pt-5 pb-4 flex items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold text-ink">{{ __('Shift Builder') }}</h2>
                        <x-ui.help @click="sbHelpOpen = !sbHelpOpen" ::aria-expanded="sbHelpOpen" />
                    </div>
                    <p class="mt-1 text-sm text-muted">{{ __('Scheduled time and breaks for this shift.') }}</p>
                </div>
            </div>

            {{-- Help panel (dismissible, slides in below card title) --}}
            <div
                x-cloak
                x-show="sbHelpOpen"
                x-transition:enter="transition-all ease-out duration-200 motion-reduce:duration-0"
                x-transition:enter-start="max-h-0 opacity-0"
                x-transition:enter-end="max-h-60 opacity-100"
                x-transition:leave="transition-all ease-in duration-150 motion-reduce:duration-0"
                x-transition:leave-start="max-h-60 opacity-100"
                x-transition:leave-end="max-h-0 opacity-0"
                class="mx-6 mb-4 overflow-hidden rounded-xl border border-border-default bg-surface-subtle"
                @click="sbHelpOpen = false"
                role="note"
                aria-label="{{ __('Click to dismiss') }}"
            >
                <div class="px-4 py-3 space-y-1.5">
                    <p class="text-xs font-semibold text-ink">{{ __('About punch grace') }}</p>
                    <p class="text-xs text-muted">{{ __('Punch grace is the window of time around a shift boundary where a clock punch (in or out) is still accepted as belonging to that shift. Without it, a clock-in one minute late would be rejected or flagged.') }}</p>
                    <p class="text-xs text-muted">{{ __('Type each window in the steppers, or drag the striped handles on the dial\'s outer ring. Terracotta = outer (before start, after end). Olive = inner slip (just inside each boundary).') }}</p>
                    <p class="text-xs text-muted border-t border-border-default pt-1.5">{{ __('Policy Builder decides rounding, lateness and overtime — Shift Builder only defines scheduled time and punch expectations.') }} <x-ui.link kind="new-tab" href="{{ route('people.attendance.policy-groups') }}" class="font-medium" :title="__('Open Policy Groups in a new tab')" @click.stop>{{ __('Open Policy Groups') }}</x-ui.link></p>
                </div>
            </div>

            <div class="px-6 pb-6">

                {{-- ── Dial (left) + controls panel (right) ── --}}
                <div class="grid gap-7 items-center lg:grid-cols-[320px_1fr]">

                    {{-- Clock dial --}}
                    <div class="flex justify-center lg:justify-start">
                        <div x-ref="dialContainer" class="w-full max-w-[320px]" wire:ignore></div>
                    </div>

                    {{-- Controls panel --}}
                    <div class="min-w-0 space-y-5">

                        {{-- Time ranges: on the clock + breaks --}}
                        <div class="space-y-2.5">

                        <div class="flex items-center gap-4 bg-surface-card border border-border-default rounded-xl px-4 py-2.5">
                            <div class="flex items-center gap-2.5 w-[150px] shrink-0">
                                <span class="w-2.5 h-2.5 rounded-sm inline-block shrink-0" style="background:var(--color-accent,#b5622f)"></span>
                                <div class="leading-tight">
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-ink">{{ __('On the clock') }}</p>
                                    <p class="text-[11px] text-muted">{{ __('Shift window') }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-ui.time-input field="shiftStart" />
                                <span class="text-muted select-none">→</span>
                                <x-ui.time-input field="shiftEnd" />
                                <template x-if="crossesMidnight">
                                    <span class="text-[10px] font-semibold uppercase tracking-wider text-muted border border-border-default rounded px-1 py-0.5">{{ __('next day') }}</span>
                                </template>
                            </div>
                            <span class="ml-auto text-sm font-semibold text-ink tabular-nums" x-text="shiftDurStr"></span>
                            <span class="w-4 shrink-0"></span>
                        </div>

                        <div class="flex items-center gap-4 bg-surface-card border border-border-default rounded-xl px-4 py-2.5">
                            <div class="flex items-center gap-2.5 w-[150px] shrink-0">
                                <span class="w-2.5 h-2.5 rounded-sm inline-block shrink-0" style="background:var(--sb-break,#7a7548)"></span>
                                <div class="leading-tight">
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-ink">{{ $shiftBreaks[0]['label'] ?? __('Break') }}</p>
                                    <button type="button" class="text-[11px] text-muted hover:text-accent underline decoration-dotted underline-offset-2 transition-colors" wire:click="toggleShiftBreakPaid(0)" title="{{ __('Toggle paid / unpaid') }}">{{ ($shiftBreaks[0]['paid'] ?? false) ? __('Paid') : __('Unpaid') }}</button>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-ui.time-input field="breakStart" />
                                <span class="text-muted select-none">→</span>
                                <x-ui.time-input field="breakEnd" />
                            </div>
                            <span class="ml-auto text-sm font-semibold text-ink tabular-nums" x-text="break1DurStr"></span>
                            <span class="w-4 shrink-0"></span>
                        </div>

                            @if (!empty($shiftBreaks[1]))

                        <div class="flex items-center gap-4 bg-surface-card border border-border-default rounded-xl px-4 py-2.5" x-show="hasBreak2">
                            <div class="flex items-center gap-2.5 w-[150px] shrink-0">
                                <span class="w-2.5 h-2.5 rounded-sm inline-block shrink-0" style="background:var(--sb-tea,#5c7f99)"></span>
                                <div class="leading-tight">
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-ink">{{ $shiftBreaks[1]['label'] ?? __('Break 2') }}</p>
                                    <button type="button" class="text-[11px] text-muted hover:text-accent underline decoration-dotted underline-offset-2 transition-colors" wire:click="toggleShiftBreakPaid(1)" title="{{ __('Toggle paid / unpaid') }}">{{ ($shiftBreaks[1]['paid'] ?? false) ? __('Paid') : __('Unpaid') }}</button>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-ui.time-input field="break2Start" />
                                <span class="text-muted select-none">→</span>
                                <x-ui.time-input field="break2End" />
                            </div>
                            <span class="ml-auto text-sm font-semibold text-ink tabular-nums" x-text="break2DurStr"></span>
                            <button type="button" class="shrink-0 text-muted hover:text-status-danger transition-colors" wire:click="removeShiftBreak(1)" aria-label="{{ __('Remove break') }}" title="{{ __('Remove break') }}">
                                <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                            </button>
                        </div>
                            @endif

                            @if (count($shiftBreaks) < 2)
                            <button type="button" class="text-[11px] font-medium text-muted hover:text-accent transition-colors pl-1" wire:click="addShiftBreak">{{ __('+ Add break') }}</button>
                            @endif
                        </div>

                        {{-- Punch grace --}}
                        <div class="pt-4 border-t border-border-default">
                            <div class="flex items-center justify-between mb-2.5">
                                <p class="text-[10.5px] font-semibold uppercase tracking-widest text-muted">{{ __('Punch grace') }}</p>
                                <span class="text-[10px] font-medium uppercase tracking-wider text-muted/70">{{ __('minutes') }}</span>
                            </div>
                            <div class="grid gap-x-8 gap-y-1 sm:grid-cols-2">

                        <div class="flex items-center justify-between gap-3 py-0.5">
                            <div class="flex items-center gap-2.5 text-sm text-ink shrink-0">
                                <span class="w-2.5 h-2.5 rounded-sm inline-block shrink-0" style="background-image:repeating-linear-gradient(45deg,rgba(181,98,47,.50) 0,rgba(181,98,47,.50) 1.5px,rgba(181,98,47,.12) 1.5px,rgba(181,98,47,.12) 4.5px)"></span>
                                {{ __('Clock-in before') }}
                            </div>
                            <x-ui.integer-input field="graceIn" set="setGrace" step="snap" />
                        </div>

                        <div class="flex items-center justify-between gap-3 py-0.5">
                            <div class="flex items-center gap-2.5 text-sm text-ink shrink-0">
                                <span class="w-2.5 h-2.5 rounded-sm inline-block shrink-0" style="background-image:repeating-linear-gradient(45deg,rgba(122,117,72,.55) 0,rgba(122,117,72,.55) 1.5px,rgba(122,117,72,.12) 1.5px,rgba(122,117,72,.12) 4.5px)"></span>
                                {{ __('Clock-in after') }}
                            </div>
                            <x-ui.integer-input field="inAfter" set="setGrace" step="snap" />
                        </div>

                        <div class="flex items-center justify-between gap-3 py-0.5">
                            <div class="flex items-center gap-2.5 text-sm text-ink shrink-0">
                                <span class="w-2.5 h-2.5 rounded-sm inline-block shrink-0" style="background-image:repeating-linear-gradient(45deg,rgba(122,117,72,.55) 0,rgba(122,117,72,.55) 1.5px,rgba(122,117,72,.12) 1.5px,rgba(122,117,72,.12) 4.5px)"></span>
                                {{ __('Clock-out before') }}
                            </div>
                            <x-ui.integer-input field="outBefore" set="setGrace" step="snap" />
                        </div>

                        <div class="flex items-center justify-between gap-3 py-0.5">
                            <div class="flex items-center gap-2.5 text-sm text-ink shrink-0">
                                <span class="w-2.5 h-2.5 rounded-sm inline-block shrink-0" style="background-image:repeating-linear-gradient(45deg,rgba(181,98,47,.50) 0,rgba(181,98,47,.50) 1.5px,rgba(181,98,47,.12) 1.5px,rgba(181,98,47,.12) 4.5px)"></span>
                                {{ __('Clock-out after') }}
                            </div>
                            <x-ui.integer-input field="graceOut" set="setGrace" step="snap" />
                        </div>
                            </div>
                        </div>

                        {{-- Paid work headline --}}
                        <div class="pt-4 border-t border-border-default flex items-baseline justify-between">
                            <span class="text-sm font-semibold text-ink">{{ __('Paid work') }}</span>
                            <span class="text-xl font-semibold text-accent tabular-nums" x-text="paidStr"></span>
                        </div>
                    </div>
                </div>

            </div>{{-- end px-6 pb-6 --}}
        </div>{{-- end work schedule card --}}
        </div>{{-- end x-data="shiftBuilder" --}}

        {{-- Effective dates & activation + actions --}}
        <x-ui.card>
            <div>
                <h2 class="text-base font-semibold text-ink">{{ __('Effective dates & activation') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('When supervisors can pick this shift, and how overnight payroll dates are attributed.') }}</p>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-4">
                <x-ui.input id="attendance-shift-effective-from" type="date" wire:model="shiftEffectiveFrom" label="{{ __('Effective from') }}" required help="{{ __('First date this shift can be assigned in rosters.') }}" :error="$errors->first('shiftEffectiveFrom')" />
                <x-ui.input id="attendance-shift-effective-to" type="date" wire:model="shiftEffectiveTo" label="{{ __('Effective to') }}" help="{{ __('Optional last date this shift can be assigned.') }}" :error="$errors->first('shiftEffectiveTo')" />
                <x-ui.select id="attendance-shift-payroll-attribution" wire:model="shiftPayrollAttribution" label="{{ __('Payroll date') }}" required help="{{ __('Which date receives attendance and payroll attribution for overnight shifts.') }}" :error="$errors->first('shiftPayrollAttribution')">
                    <option value="shift_start_date">{{ __('Shift start date') }}</option>
                    <option value="shift_end_date">{{ __('Shift end date') }}</option>
                </x-ui.select>
                <x-ui.select id="attendance-shift-status" wire:model="shiftStatus" label="{{ __('Status') }}" required help="{{ __('Active shifts can be used in rosters.') }}" :error="$errors->first('shiftStatus')">
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                </x-ui.select>
            </div>
            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="cancelShiftEdit">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button as="a" variant="secondary" href="{{ route('people.attendance.policy-groups.validator') }}">{{ __('Open Validator') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary" :disabled="! $canManage">
                    {{ $editingShiftTemplateId === null ? __('Create shift') : __('Save shift') }}
                </x-ui.button>
            </div>
        </x-ui.card>
    </form>
@endif

<x-ui.modal wire:model="showShiftTemplateImportModal" class="max-w-2xl">
    <div class="p-6 space-y-4">
        <div>
            <h2 class="text-lg font-semibold text-ink">{{ __('Upload Shift Template') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Choose a JSON file containing one shift template object, or an array of template objects.') }}</p>
        </div>
        <div>
            <label for="attendance-shift-template-upload" class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Template JSON file') }}</label>
            <input id="attendance-shift-template-upload" type="file" accept="application/json,.json" wire:model="shiftTemplateUpload" class="mt-1 block w-full text-sm text-ink file:mr-4 file:rounded file:border-0 file:bg-surface-subtle file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-ink hover:file:bg-surface-subtle/80" />
            @error('shiftTemplateUpload') <p class="mt-1 text-sm text-status-danger">{{ $message }}</p> @enderror
        </div>
        <div class="flex justify-end gap-2">
            <x-ui.button type="button" variant="secondary" wire:click="$set('showShiftTemplateImportModal', false)">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="button" variant="primary" wire:click="importShiftTemplate">{{ __('Upload into builder') }}</x-ui.button>
        </div>
    </div>
</x-ui.modal>
