<?php
/** @var \App\Modules\People\Claim\Livewire\Index $this */
?>

<div>
    <x-slot name="title">{{ $surfaceTitle }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$surfaceTitle" :subtitle="$surfaceSubtitle">
            @if ($surface === 'operations')
                <x-slot name="actions">
                    <x-ui.button as="a" :href="$operationsExportUrl" variant="secondary">
                        <x-icon name="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                        {{ __('Export CSV') }}
                    </x-ui.button>
                    <x-ui.button as="a" :href="$accountingExportUrl" variant="secondary">
                        <x-icon name="heroicon-o-banknotes" class="h-4 w-4" />
                        {{ __('Accounting CSV') }}
                    </x-ui.button>
                    <x-ui.button as="a" :href="$reimbursementStatementUrl" variant="secondary">
                        <x-icon name="heroicon-o-document-text" class="h-4 w-4" />
                        {{ __('Reimbursement Statement') }}
                    </x-ui.button>
                    <x-ui.button as="a" :href="$utilizationReportUrl" variant="secondary">
                        <x-icon name="heroicon-o-chart-bar" class="h-4 w-4" />
                        {{ __('Utilization Report') }}
                    </x-ui.button>
                    <x-ui.button as="a" :href="$approvalAgingUrl" variant="secondary">
                        <x-icon name="heroicon-o-clock" class="h-4 w-4" />
                        {{ __('Approval Aging') }}
                    </x-ui.button>
                </x-slot>
            @endif
            <x-slot name="help">
                {{ __('The Claim module uses a singular code namespace while the user-facing workbench remains Claims. Claim setup is country-neutral and hands approved reimbursement lines to Payroll.') }}
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            @if (in_array($tab, ['categories', 'types', 'policies'], true))
                <div class="mb-4 flex justify-end">
                    <div class="w-full lg:w-80">
                        <x-ui.search-input
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search claim setup...') }}"
                        />
                    </div>
                </div>
            @endif

            @if ($surface === 'settings' && ! $canManage)
                <x-ui.alert variant="warning">{{ __('You can view claim setup, but only claim managers can change it.') }}</x-ui.alert>
            @endif

            @if ($tab === 'submit')
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-sm text-muted">{{ __('Your claims are listed below. Create a new claim only when you need to submit a reimbursement.') }}</div>
                    <x-ui.button type="button" variant="primary" wire:click="$set('showClaimModal', true)" :disabled="$currentEmployeeId === null">
                        <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                        {{ __('New Claim') }}
                    </x-ui.button>
                </div>

                <x-ui.table container="flush" :caption="__('My claim requests')" :row-hover="false">
                    <x-slot name="head">
                    <tr>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Reference') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Employee') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Requested') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Risk') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                    </tr>
                    </x-slot>

                    @forelse ($myRequests as $request)
                        @php
                            $duplicateRisks = $request->metadata['duplicate_risks'] ?? [];
                        @endphp
                        <tr wire:key="claim-request-{{ $request->id }}">
                            <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink">{{ $request->reference_number ?? __('Draft #:id', ['id' => $request->id]) }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-ink">{{ $request->employee?->full_name ?? __('Employee #:id', ['id' => $request->employee_id]) }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $request->currency }} {{ number_format((float) $request->requested_amount, 2) }}</td>
                            <td class="px-table-cell-x py-table-cell-y"><x-ui.badge :variant="$this->statusVariant($request->status)">{{ __(str_replace('_', ' ', ucfirst($request->status))) }}</x-ui.badge></td>
                            <td class="px-table-cell-x py-table-cell-y">
                                @if ($duplicateRisks !== [])
                                    <div class="space-y-1">
                                        <x-ui.badge variant="warning">{{ trans_choice(':count warning|:count warnings', count($duplicateRisks), ['count' => count($duplicateRisks)]) }}</x-ui.badge>
                                        <div class="max-w-xs text-xs text-muted">{{ __($duplicateRisks[0]['message'] ?? 'Duplicate risk detected.') }}</div>
                                    </div>
                                @else
                                    <span class="text-xs text-muted">{{ __('None') }}</span>
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y">
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if ($request->employee_id === $currentEmployeeId && in_array($request->status, [\App\Modules\People\Claim\Models\ClaimRequest::STATUS_DRAFT, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_SUBMITTED, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_NEEDS_MORE_INFO], true))
                                        <x-ui.button type="button" size="sm" variant="secondary" wire:click="withdrawOwnRequest({{ $request->id }})">{{ __('Withdraw') }}</x-ui.button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No claim requests yet.') }}</td>
                        </tr>
                    @endforelse
                </x-ui.table>

                @if ($currentEmployeeId === null)
                    <x-ui.alert variant="warning" class="mt-4">{{ __('Your user account is not linked to an employee record, so claims cannot be submitted from this workbench yet.') }}</x-ui.alert>
                @endif

            @elseif ($tab === 'operations')
                <div class="mb-4 grid gap-4 md:grid-cols-[minmax(0,1fr)_12rem_12rem_14rem] md:items-center">
                    <x-ui.search-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search reference, employee, receipt, or provider...') }}"
                    />
                    <x-ui.select id="claim-operations-status" wire:model.live="operationsStatus">
                        <option value="">{{ __('All statuses') }}</option>
                        @foreach ($claimStatusOptions as $status => $label)
                            <option value="{{ $status }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select id="claim-operations-risk" wire:model.live="operationsRisk">
                        <option value="">{{ __('All risks') }}</option>
                        <option value="duplicate">{{ __('Duplicate risk') }}</option>
                        <option value="clear">{{ __('No risk') }}</option>
                    </x-ui.select>
                    <x-ui.select id="claim-operations-payroll" wire:model.live="operationsPayroll">
                        <option value="">{{ __('All payroll states') }}</option>
                        @foreach ($payrollOperationsOptions as $state => $label)
                            <option value="{{ $state }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.table container="flush" :caption="__('Claim operations requests')" :row-hover="false">
                    <x-slot name="head">
                    <tr>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Reference') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Employee') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Lines') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Requested') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Risk') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Payroll') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                    </tr>
                    </x-slot>

                    @forelse ($operationsRequests as $request)
                        @php
                            $duplicateRisks = $request->metadata['duplicate_risks'] ?? [];
                            $handoff = $request->metadata['payroll_handoff'] ?? null;
                            $eligiblePayrollLines = $request->lines->filter(fn ($line) => (bool) $line->type?->payroll_eligible && $line->payroll_pay_item_code !== null);
                        @endphp
                        <tr wire:key="claim-operations-request-{{ $request->id }}">
                            <td class="px-table-cell-x py-table-cell-y">
                                <div class="font-mono text-xs text-ink">{{ $request->reference_number ?? __('Draft #:id', ['id' => $request->id]) }}</div>
                                <div class="text-xs text-muted tabular-nums">{{ $request->submitted_at?->format('Y-m-d H:i') ?? __('Not submitted') }}</div>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y">
                                <div class="font-medium text-ink">{{ $request->employee?->full_name ?? __('Employee #:id', ['id' => $request->employee_id]) }}</div>
                                <div class="font-mono text-xs text-muted">{{ $request->employee?->employee_number }}</div>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y">
                                <div class="flex flex-wrap gap-2">
                                    @forelse ($request->lines as $line)
                                        <x-ui.badge wire:key="claim-operations-line-{{ $line->id }}">{{ $line->type?->code ?? __('Line #:id', ['id' => $line->id]) }}</x-ui.badge>
                                    @empty
                                        <span class="text-xs text-muted">{{ __('No lines') }}</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $request->currency }} {{ number_format((float) $request->requested_amount, 2) }}</td>
                            <td class="px-table-cell-x py-table-cell-y"><x-ui.badge :variant="$this->statusVariant($request->status)">{{ __(str_replace('_', ' ', ucfirst($request->status))) }}</x-ui.badge></td>
                            <td class="px-table-cell-x py-table-cell-y">
                                @if ($duplicateRisks !== [])
                                    <div class="space-y-1">
                                        <x-ui.badge variant="warning">{{ trans_choice(':count warning|:count warnings', count($duplicateRisks), ['count' => count($duplicateRisks)]) }}</x-ui.badge>
                                        <div class="max-w-xs text-xs text-muted">{{ __($duplicateRisks[0]['message'] ?? 'Duplicate risk detected.') }}</div>
                                    </div>
                                @else
                                    <span class="text-xs text-muted">{{ __('None') }}</span>
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y">
                                @if (is_array($handoff))
                                    <div class="space-y-1 text-xs text-muted">
                                        <x-ui.badge :variant="($handoff['pending'] ?? 0) > 0 ? 'warning' : 'success'">{{ __('Queued :queued/:eligible', ['queued' => $handoff['queued'] ?? 0, 'eligible' => $handoff['eligible'] ?? 0]) }}</x-ui.badge>
                                        @if (($handoff['pending'] ?? 0) > 0)
                                            <div>{{ trans_choice(':count pending open payroll run|:count pending open payroll runs', $handoff['pending'], ['count' => $handoff['pending']]) }}</div>
                                        @endif
                                    </div>
                                @elseif ($eligiblePayrollLines->isNotEmpty())
                                    <x-ui.badge variant="info">{{ trans_choice(':count payroll line|:count payroll lines', $eligiblePayrollLines->count(), ['count' => $eligiblePayrollLines->count()]) }}</x-ui.badge>
                                @else
                                    <span class="text-xs text-muted">{{ __('Not payroll eligible') }}</span>
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-right">
                                <div class="flex flex-wrap justify-end gap-1">
                                    @if (in_array($request->status, [\App\Modules\People\Claim\Models\ClaimRequest::STATUS_APPROVED, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_QUEUED_FOR_PAYROLL], true))
                                        <x-ui.button type="button" size="sm" variant="primary" wire:click="markReimbursed({{ $request->id }})" wire:confirm="{{ __('Mark this claim as reimbursed?') }}">{{ __('Reimburse') }}</x-ui.button>
                                    @endif
                                    @if (in_array($request->status, [\App\Modules\People\Claim\Models\ClaimRequest::STATUS_DRAFT, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_SUBMITTED, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_NEEDS_MORE_INFO, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_RESUBMITTED, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_APPROVED], true))
                                        <x-ui.button type="button" size="sm" variant="ghost" wire:click="cancelRequest({{ $request->id }})" wire:confirm="{{ __('Cancel this claim request?') }}">{{ __('Cancel') }}</x-ui.button>
                                    @endif
                                    @if (! in_array($request->status, [\App\Modules\People\Claim\Models\ClaimRequest::STATUS_DRAFT, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_SUBMITTED, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_NEEDS_MORE_INFO, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_RESUBMITTED, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_APPROVED, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_QUEUED_FOR_PAYROLL], true))
                                        <span class="text-xs text-muted">{{ __('—') }}</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No claim requests match the current filters.') }}</td>
                        </tr>
                    @endforelse
                </x-ui.table>

            @elseif ($tab === 'approvals')
                <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(24rem,0.8fr)]">
                    <x-ui.table container="flush" :caption="__('Pending claim approvals')" :row-hover="false">
                        <x-slot name="head">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Reference') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Employee') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Requested') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Risk') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                        </x-slot>

                        @forelse ($pendingRequests as $request)
                            @php
                                $duplicateRisks = $request->metadata['duplicate_risks'] ?? [];
                            @endphp
                            <tr wire:key="pending-claim-{{ $request->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y">
                                    <button type="button" wire:click="selectRequest({{ $request->id }})" class="text-left">
                                        <div class="font-mono text-xs text-ink">{{ $request->reference_number }}</div>
                                        <div class="text-xs text-muted tabular-nums">{{ $request->submitted_at?->format('Y-m-d H:i') }}</div>
                                    </button>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-ink">{{ $request->employee?->displayName() ?? __('Employee #:id', ['id' => $request->employee_id]) }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $request->currency }} {{ number_format((float) $request->requested_amount, 2) }}</td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    @if ($duplicateRisks !== [])
                                        <x-ui.badge variant="warning">{{ trans_choice(':count warning|:count warnings', count($duplicateRisks), ['count' => count($duplicateRisks)]) }}</x-ui.badge>
                                    @else
                                        <span class="text-xs text-muted">{{ __('None') }}</span>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        @if ($canApprove)
                                            <x-ui.button type="button" size="sm" variant="primary" wire:click="approveRequest({{ $request->id }})">{{ __('Approve') }}</x-ui.button>
                                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="requestMoreInfo({{ $request->id }})">{{ __('More info') }}</x-ui.button>
                                            <x-ui.button type="button" size="sm" variant="ghost" wire:click="rejectRequest({{ $request->id }})">{{ __('Reject') }}</x-ui.button>
                                        @else
                                            <span class="text-xs text-muted">{{ __('View only') }}</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No claim requests are awaiting approval.') }}</td></tr>
                        @endforelse
                    </x-ui.table>

                    <div class="space-y-4">
                        @if ($selectedRequest)
                            <x-ui.card :title="__('Request Details')">
                                <div class="space-y-3 text-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="font-medium text-ink">{{ $selectedRequest->employee?->displayName() }}</div>
                                            <div class="font-mono text-xs text-muted">{{ $selectedRequest->reference_number }}</div>
                                        </div>
                                        <x-ui.badge :variant="$this->statusVariant($selectedRequest->status)">{{ __(str_replace('_', ' ', ucfirst($selectedRequest->status))) }}</x-ui.badge>
                                    </div>
                                    <dl class="grid grid-cols-2 gap-3 text-xs">
                                        <div><dt class="text-muted">{{ __('Amount') }}</dt><dd class="text-ink tabular-nums">{{ $selectedRequest->currency }} {{ number_format((float) $selectedRequest->requested_amount, 2) }}</dd></div>
                                        <div><dt class="text-muted">{{ __('Submitted') }}</dt><dd class="text-ink tabular-nums">{{ $selectedRequest->submitted_at?->format('Y-m-d H:i') ?? '-' }}</dd></div>
                                        <div><dt class="text-muted">{{ __('Assignment') }}</dt><dd class="text-ink">{{ $selectedRequest->assignment?->name ?? '-' }}</dd></div>
                                        <div><dt class="text-muted">{{ __('Context') }}</dt><dd class="text-ink">{{ $selectedRequest->context?->label ?? '-' }}</dd></div>
                                    </dl>
                                    @if ($canApprove && in_array($selectedRequest->status, [\App\Modules\People\Claim\Models\ClaimRequest::STATUS_SUBMITTED, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_RESUBMITTED], true))
                                        <x-ui.textarea id="claim-approval-reason" wire:model="approvalReason" label="{{ __('Reason / Note') }}" rows="2" />
                                        <div class="flex gap-2">
                                            <x-ui.button type="button" size="sm" variant="primary" wire:click="approveRequest({{ $selectedRequest->id }})">{{ __('Approve') }}</x-ui.button>
                                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="requestMoreInfo({{ $selectedRequest->id }})">{{ __('Request More Info') }}</x-ui.button>
                                            <x-ui.button type="button" size="sm" variant="ghost" wire:click="rejectRequest({{ $selectedRequest->id }})">{{ __('Reject') }}</x-ui.button>
                                        </div>
                                    @endif
                                </div>
                            </x-ui.card>

                            <x-ui.card :title="__('Claim Lines')">
                                <div class="space-y-2 text-sm">
                                    @foreach ($selectedRequest->lines as $line)
                                        <div class="rounded-lg border border-border-default p-3" wire:key="selected-claim-line-{{ $line->id }}">
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <div class="font-medium text-ink">{{ $line->type?->name }}</div>
                                                    <div class="text-xs text-muted">{{ $line->description }}</div>
                                                </div>
                                                <div class="text-right text-sm tabular-nums text-ink">{{ $line->currency }} {{ number_format((float) $line->requested_amount, 2) }}</div>
                                            </div>
                                            <div class="mt-2 grid grid-cols-2 gap-2 text-xs text-muted">
                                                <div>{{ __('Receipt: :receipt', ['receipt' => $line->receipt_number ?? '-']) }}</div>
                                                <div>{{ __('Provider: :provider', ['provider' => $line->provider_name ?? '-']) }}</div>
                                                <div>{{ __('Payroll: :code', ['code' => $line->payroll_pay_item_code ?? '-']) }}</div>
                                                <div>{{ __('Attachments: :count', ['count' => $line->attachment_count]) }}</div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </x-ui.card>

                            <x-ui.card :title="__('Audit Trail')">
                                <div class="space-y-2 text-sm">
                                    @forelse ($selectedRequest->auditEvents as $event)
                                        <div class="rounded-lg bg-surface-subtle p-3" wire:key="claim-audit-{{ $event->id }}">
                                            <div class="flex items-center justify-between gap-3">
                                                <span class="font-medium text-ink">{{ $event->from_status }} → {{ $event->to_status }}</span>
                                                <span class="text-xs text-muted tabular-nums">{{ $event->occurred_at?->format('Y-m-d H:i') }}</span>
                                            </div>
                                            @if ($event->reason)
                                                <div class="mt-1 text-xs text-muted">{{ $event->reason }}</div>
                                            @endif
                                        </div>
                                    @empty
                                        <p class="text-sm text-muted">{{ __('No transitions recorded yet.') }}</p>
                                    @endforelse
                                </div>
                            </x-ui.card>
                        @else
                            <x-ui.card>
                                <p class="text-sm text-muted">{{ __('Select a pending claim to inspect lines, risk warnings, and audit trail.') }}</p>
                            </x-ui.card>
                        @endif
                    </div>
                </div>

            @elseif ($tab === 'categories')
                @if ($canManage)
                    <x-ui.card :title="__('Create Claim Category')" class="mb-6">
                        <form wire:submit="createCategory" class="grid gap-4 md:grid-cols-[1fr_2fr_auto] md:items-end">
                            <x-ui.input id="claim-category-code" wire:model="categoryCode" label="{{ __('Code') }}" required :error="$errors->first('categoryCode')" />
                            <x-ui.input id="claim-category-name" wire:model="categoryName" label="{{ __('Name') }}" required :error="$errors->first('categoryName')" />
                            <x-ui.button type="submit" variant="primary">{{ __('Save Category') }}</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif

                <x-ui.table container="flush" :caption="__('Claim categories')" :row-hover="false">
                    <x-slot name="head">
                    <tr>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Code') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Name') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                    </tr>
                    </x-slot>

                    @forelse ($categories as $category)
                        <tr wire:key="claim-category-{{ $category->id }}">
                            <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink">{{ $category->code }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-ink">{{ $category->name }}</td>
                            <td class="px-table-cell-x py-table-cell-y"><x-ui.badge variant="success">{{ __(ucfirst($category->status)) }}</x-ui.badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No claim categories yet.') }}</td></tr>
                    @endforelse
                </x-ui.table>

            @elseif ($tab === 'types')
                @if ($canManage)
                    <x-ui.card :title="__('Create Claim Type')" class="mb-6">
                        <form wire:submit="createClaimType" class="space-y-4">
                            <div class="grid gap-4 md:grid-cols-3">
                                <x-ui.input id="claim-type-code" wire:model="typeCode" label="{{ __('Code') }}" required :error="$errors->first('typeCode')" />
                                <x-ui.input id="claim-type-name" wire:model="typeName" label="{{ __('Name') }}" required :error="$errors->first('typeName')" />
                                <x-ui.select id="claim-type-category" wire:model="typeCategoryId" label="{{ __('Category') }}" :error="$errors->first('typeCategoryId')">
                                    <option value="">{{ __('Uncategorised') }}</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->code }} — {{ $category->name }}</option>
                                    @endforeach
                                </x-ui.select>
                            </div>

                            <div class="grid gap-4 md:grid-cols-4">
                                <x-ui.select id="claim-type-unit" wire:model="typeDefaultUnit" label="{{ __('Unit') }}">
                                    <option value="amount">{{ __('Amount') }}</option>
                                    <option value="distance">{{ __('Distance') }}</option>
                                    <option value="quantity">{{ __('Quantity') }}</option>
                                    <option value="days">{{ __('Days') }}</option>
                                </x-ui.select>
                                <x-ui.select id="claim-type-receipt" wire:model="typeReceiptRequirement" label="{{ __('Receipt Rule') }}">
                                    <option value="always">{{ __('Always') }}</option>
                                    <option value="above_amount">{{ __('Above Amount') }}</option>
                                    <option value="never">{{ __('Never') }}</option>
                                </x-ui.select>
                                <x-ui.input id="claim-type-route" wire:model="typeApprovalRouteKey" label="{{ __('Alternative Route') }}" :error="$errors->first('typeApprovalRouteKey')" />
                            </div>

                            <div class="grid gap-4 md:grid-cols-4">
                                <x-ui.input id="claim-type-dr" wire:model="typeDebitAccountCode" label="{{ __('Account Code (DR)') }}" :error="$errors->first('typeDebitAccountCode')" />
                                <x-ui.input id="claim-type-cr" wire:model="typeCreditAccountCode" label="{{ __('Account Code (CR)') }}" :error="$errors->first('typeCreditAccountCode')" />
                                <div class="space-y-2 pt-6">
                                    <x-ui.checkbox id="claim-type-provider-required" wire:model="typeProviderRequired" label="{{ __('Provider required') }}" />
                                    <x-ui.checkbox id="claim-type-payroll-eligible" wire:model="typePayrollEligible" label="{{ __('Payroll eligible') }}" />
                                </div>
                                <div class="flex items-end"><x-ui.button type="submit" variant="primary">{{ __('Save Type') }}</x-ui.button></div>
                            </div>
                        </form>
                    </x-ui.card>
                @endif

                <x-ui.table container="flush" :caption="__('Claim types')" :row-hover="false">
                    <x-slot name="head">
                    <tr>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Type') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Category') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Payroll / Accounts') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Rules') }}</th>
                    </tr>
                    </x-slot>

                    @forelse ($types as $type)
                        <tr wire:key="claim-type-{{ $type->id }}">
                            <td class="px-table-cell-x py-table-cell-y"><div class="font-medium text-ink">{{ $type->name }}</div><div class="font-mono text-xs text-muted">{{ $type->code }}</div></td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $type->category?->name ?? __('Uncategorised') }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-xs text-muted"><div>{{ $type->payroll_eligible ? __('Mapped in Payroll') : __('No payroll handoff') }}</div><div>{{ __('DR: :dr / CR: :cr', ['dr' => $type->debit_account_code ?? '—', 'cr' => $type->credit_account_code ?? '—']) }}</div></td>
                            <td class="px-table-cell-x py-table-cell-y text-xs text-muted">{{ __(str_replace('_', ' ', ucfirst($type->receipt_requirement))) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No claim types yet.') }}</td></tr>
                    @endforelse
                </x-ui.table>

            @elseif ($tab === 'policies')
                @if ($canManage)
                    <div class="mb-6 grid gap-6 xl:grid-cols-2">
                        <x-ui.card :title="__('Create Claim Policy')">
                            <form wire:submit="createPolicy" class="space-y-4">
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-ui.input id="claim-policy-code" wire:model="policyCode" label="{{ __('Code') }}" required :error="$errors->first('policyCode')" />
                                    <x-ui.input id="claim-policy-name" wire:model="policyName" label="{{ __('Name') }}" required :error="$errors->first('policyName')" />
                                </div>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-ui.select id="claim-policy-mode" wire:model="policyItemMode" label="{{ __('Item Mode') }}">
                                        <option value="single_value">{{ __('Single Value') }}</option>
                                        <option value="range">{{ __('Range') }}</option>
                                        <option value="service_year">{{ __('Service Year') }}</option>
                                    </x-ui.select>
                                    <x-ui.input id="claim-policy-effective-from" type="date" wire:model="policyEffectiveFrom" label="{{ __('Effective From') }}" required />
                                </div>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-ui.input id="claim-policy-rate-type" wire:model="policyRateType" label="{{ __('Rate Type') }}" />
                                    <x-ui.input id="claim-policy-approval-profile" wire:model="policyApprovalProfileKey" label="{{ __('Approval Profile') }}" />
                                </div>
                                <div class="flex flex-wrap items-center gap-4">
                                    <x-ui.checkbox id="claim-policy-auto" wire:model="policyAutoCalculated" label="{{ __('Auto calculated') }}" />
                                    <x-ui.checkbox id="claim-policy-encumber" wire:model="policyEncumberPending" label="{{ __('Encumber pending claims') }}" />
                                    <x-ui.button type="submit" variant="primary">{{ __('Save Policy') }}</x-ui.button>
                                </div>
                            </form>
                        </x-ui.card>

                        <x-ui.card :title="__('Add Policy Band')">
                            <form wire:submit="addPolicyBand" class="space-y-4">
                                <x-ui.select id="claim-band-policy" wire:model="bandPolicyId" label="{{ __('Policy') }}" required :error="$errors->first('bandPolicyId')">
                                    <option value="">{{ __('Select policy') }}</option>
                                    @foreach ($policies as $policy)
                                        <option value="{{ $policy->id }}">{{ $policy->code }} — {{ $policy->name }}</option>
                                    @endforeach
                                </x-ui.select>
                                <div class="grid gap-4 md:grid-cols-3">
                                    <x-ui.input id="claim-band-threshold" wire:model="bandThreshold" label="{{ __('Threshold') }}" />
                                    <x-ui.input id="claim-band-rate" wire:model="bandRate" label="{{ __('Rate') }}" required />
                                    <x-ui.input id="claim-band-per-claim" wire:model="bandPerClaimLimit" label="{{ __('Per Claim Limit') }}" />
                                </div>
                                <div class="grid gap-4 md:grid-cols-3">
                                    <x-ui.input id="claim-band-per-day" wire:model="bandPerDayUnitLimit" label="{{ __('Per Day / Unit Limit') }}" />
                                    <x-ui.input id="claim-band-per-month" wire:model="bandPerMonthLimit" label="{{ __('Per Month Limit') }}" />
                                    <x-ui.input id="claim-band-per-year" wire:model="bandPerYearLimit" label="{{ __('Per Year Limit') }}" />
                                </div>
                                <x-ui.button type="submit" variant="primary">{{ __('Add Band') }}</x-ui.button>
                            </form>
                        </x-ui.card>
                    </div>
                @endif

                <div class="grid gap-4">
                    @forelse ($policies as $policy)
                        <x-ui.card wire:key="claim-policy-{{ $policy->id }}">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div><div class="font-medium text-ink">{{ $policy->name }}</div><div class="font-mono text-xs text-muted">{{ $policy->code }} · {{ __(str_replace('_', ' ', ucfirst($policy->item_mode))) }}</div></div>
                                <x-ui.badge variant="success">{{ __(ucfirst($policy->status)) }}</x-ui.badge>
                            </div>
                            <x-ui.table container="plain" size="xs" class="mt-4" :caption="__('Claim policy bands')" :row-hover="false">
                                <x-slot name="head">
                                    <tr><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Threshold') }}</th><th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Rate') }}</th><th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Per Claim') }}</th><th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Per Month') }}</th><th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Per Year') }}</th></tr>
                                </x-slot>

                                @forelse ($policy->bands as $band)
                                    <tr wire:key="claim-policy-band-{{ $band->id }}"><td class="px-table-cell-x py-table-cell-y text-muted">{{ $band->logical_operator }} {{ $band->threshold_value ?? __('Unlimited') }}</td><td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $band->rate }}</td><td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $band->per_claim_limit ?? '—' }}</td><td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $band->per_month_limit ?? '—' }}</td><td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $band->per_year_limit ?? '—' }}</td></tr>
                                @empty
                                    <tr><td colspan="5" class="px-table-cell-x py-6 text-center text-muted">{{ __('No bands configured.') }}</td></tr>
                                @endforelse
                            </x-ui.table>
                        </x-ui.card>
                    @empty
                        <x-ui.alert variant="info">{{ __('No claim policies yet.') }}</x-ui.alert>
                    @endforelse
                </div>

            @elseif ($tab === 'assignments')
                @if ($canManage)
                    <div class="mb-6 grid gap-6 xl:grid-cols-2">
                        <x-ui.card :title="__('Create Claim Assignment')">
                            <form wire:submit="createAssignment" class="space-y-4">
                                <x-ui.input id="claim-assignment-code" wire:model="assignmentCode" label="{{ __('Code') }}" required />
                                <x-ui.input id="claim-assignment-name" wire:model="assignmentName" label="{{ __('Name') }}" required />
                                <x-ui.input id="claim-assignment-effective-from" type="date" wire:model="assignmentEffectiveFrom" label="{{ __('Effective From') }}" required />
                                <x-ui.button type="submit" variant="primary">{{ __('Save Assignment') }}</x-ui.button>
                            </form>
                        </x-ui.card>

                        <x-ui.card :title="__('Add Assignment Line')">
                            <form wire:submit="addAssignmentLine" class="space-y-4">
                                <x-ui.select id="claim-line-assignment" wire:model="lineAssignmentId" label="{{ __('Assignment') }}" required><option value="">{{ __('Select assignment') }}</option>@foreach ($assignments as $assignment)<option value="{{ $assignment->id }}">{{ $assignment->code }} — {{ $assignment->name }}</option>@endforeach</x-ui.select>
                                <x-ui.select id="claim-line-type" wire:model="lineClaimTypeId" label="{{ __('Claim Type') }}" required><option value="">{{ __('Select type') }}</option>@foreach ($types as $type)<option value="{{ $type->id }}">{{ $type->code }} — {{ $type->name }}</option>@endforeach</x-ui.select>
                                <x-ui.select id="claim-line-policy" wire:model="lineClaimPolicyId" label="{{ __('Claim Policy') }}" required><option value="">{{ __('Select policy') }}</option>@foreach ($policies as $policy)<option value="{{ $policy->id }}">{{ $policy->code }} — {{ $policy->name }}</option>@endforeach</x-ui.select>
                                <x-ui.input id="claim-line-combine-tag" wire:model="lineCombineTag" label="{{ __('Combine Tag') }}" />
                                <div class="flex flex-wrap gap-4"><x-ui.checkbox id="claim-line-combined" wire:model="lineUsesCombinedCap" label="{{ __('Uses combined cap') }}" /><x-ui.checkbox id="claim-line-hidden" wire:model="lineHiddenFromApplication" label="{{ __('Hidden from application') }}" /></div>
                                <x-ui.button type="submit" variant="primary">{{ __('Add Line') }}</x-ui.button>
                            </form>
                        </x-ui.card>
                    </div>
                @endif

                <div class="grid gap-4">
                    @forelse ($assignments as $assignment)
                        <x-ui.card wire:key="claim-assignment-{{ $assignment->id }}">
                            <div class="mb-3"><div class="font-medium text-ink">{{ $assignment->name }}</div><div class="font-mono text-xs text-muted">{{ $assignment->code }}</div></div>
                            <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                @forelse ($assignment->lines as $line)
                                    <div class="rounded-xl border border-border-default p-3" wire:key="claim-assignment-line-{{ $line->id }}"><div class="font-medium text-ink">{{ $line->type?->name }}</div><div class="text-xs text-muted">{{ $line->policy?->name }}</div>@if ($line->hidden_from_application)<x-ui.badge>{{ __('Hidden') }}</x-ui.badge>@endif</div>
                                @empty
                                    <p class="text-sm text-muted">{{ __('No claim types assigned yet.') }}</p>
                                @endforelse
                            </div>
                        </x-ui.card>
                    @empty
                        <x-ui.alert variant="info">{{ __('No claim assignments yet.') }}</x-ui.alert>
                    @endforelse
                </div>

            @elseif ($tab === 'contexts')
                @if ($canManage)
                    <x-ui.card :title="__('Create Claim Context')" class="mb-6">
                        <form wire:submit="createContext" class="grid gap-4 md:grid-cols-[1fr_2fr_1fr_auto] md:items-end">
                            <x-ui.input id="claim-context-code" wire:model="contextCode" label="{{ __('Code') }}" required />
                            <x-ui.input id="claim-context-label" wire:model="contextLabel" label="{{ __('Label') }}" required />
                            <x-ui.input id="claim-context-max" wire:model="contextMaxClaimLimit" label="{{ __('Max Limit') }}" />
                            <x-ui.button type="submit" variant="primary">{{ __('Save Context') }}</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif

                <x-ui.table container="flush" :caption="__('Claim contexts')" :row-hover="false">
                    <x-slot name="head">
                        <tr><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Code') }}</th><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Label') }}</th><th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Max Limit') }}</th></tr>
                    </x-slot>

                    @forelse ($contexts as $context)
                        <tr wire:key="claim-context-{{ $context->id }}"><td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink">{{ $context->code }}</td><td class="px-table-cell-x py-table-cell-y text-ink">{{ $context->label }}</td><td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $context->max_claim_limit ?? '—' }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No claim contexts yet.') }}</td></tr>
                    @endforelse
                </x-ui.table>
            @endif
        </x-ui.card>
    </div>

    <x-ui.modal wire:model="showClaimModal" class="max-w-4xl">
        <form wire:submit="submitClaim" class="p-6 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('New Claim') }}</h3>
                    <p class="mt-1 text-sm text-muted">{{ __('Submit one reimbursement line. Policy snapshots, duplicate-risk warnings, and payroll metadata are recorded at submission.') }}</p>
                </div>
                <button type="button" @click="show = false" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="h-5 w-5" />
                </button>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <x-ui.select id="claim-apply-assignment" wire:model.live="applyAssignmentId" label="{{ __('Assignment') }}" required :error="$errors->first('applyAssignmentId')">
                    <option value="">{{ __('Select assignment') }}</option>
                    @foreach ($myAssignments as $assignment)
                        <option value="{{ $assignment->id }}">{{ $assignment->code }} &mdash; {{ $assignment->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select id="claim-apply-line" wire:model="applyAssignmentLineId" label="{{ __('Claim Type') }}" required :error="$errors->first('applyAssignmentLineId')">
                    <option value="">{{ __('Select claim type') }}</option>
                    @foreach ($availableAssignmentLines as $line)
                        <option value="{{ $line->id }}">{{ $line->type?->code }} &mdash; {{ $line->type?->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <x-ui.select id="claim-apply-context" wire:model="applyContextId" label="{{ __('Context') }}" :error="$errors->first('applyContextId')">
                    <option value="">{{ __('No context') }}</option>
                    @foreach ($contexts as $context)
                        <option value="{{ $context->id }}">{{ $context->code }} &mdash; {{ $context->label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input id="claim-apply-incurred-on" type="date" wire:model="applyIncurredOn" label="{{ __('Incurred On') }}" required :error="$errors->first('applyIncurredOn')" />
                <x-ui.input id="claim-apply-amount" wire:model="applyRequestedAmount" label="{{ __('Amount') }}" required :error="$errors->first('applyRequestedAmount')" />
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <x-ui.input id="claim-apply-provider" wire:model="applyProviderName" label="{{ __('Provider') }}" :error="$errors->first('applyProviderName')" />
                <x-ui.input id="claim-apply-receipt" wire:model="applyReceiptNumber" label="{{ __('Receipt Number') }}" :error="$errors->first('applyReceiptNumber')" />
                <x-ui.input id="claim-apply-attachments" wire:model="applyAttachmentCount" label="{{ __('Attachment Count') }}" required :error="$errors->first('applyAttachmentCount')" />
            </div>

            <x-ui.input id="claim-apply-description" wire:model="applyDescription" label="{{ __('Description') }}" :error="$errors->first('applyDescription')" />

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" variant="ghost" @click="show = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary">{{ __('Submit Claim') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
