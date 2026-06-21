<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Livewire\Concerns\ManagesShiftBreaks;
use App\Modules\People\Attendance\Livewire\Concerns\ManagesShiftPunchWindows;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Attendance\Services\ShiftTemplateSerializer;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Shift template list + builder.
 *
 * Renders the shift templates table by default ($mode === 'list'). Clicking
 * "New", "Edit", or "Duplicate" switches into $mode === 'form' which shows
 * the shift builder inline; save and cancel return to list mode without a
 * page navigation.
 */
class ShiftTemplates extends Component
{
    use InteractsWithAttendanceScreen;
    use InteractsWithNotifications;
    use ManagesShiftBreaks;
    use ManagesShiftPunchWindows;
    use WithFileUploads;

    private const DEFAULT_SHIFT_START = '08:00';

    private const DEFAULT_SHIFT_END = '17:00';

    private const DEFAULT_BREAK_START = '12:00';

    private const DEFAULT_BREAK_END = '13:00';

    private const DEFAULT_BREAK_WINDOW = [
        'break_starts_at' => self::DEFAULT_BREAK_START,
        'break_ends_at' => self::DEFAULT_BREAK_END,
    ];

    private const DEFAULT_MEAL_BREAK_WINDOW = [
        'label' => 'Meal break',
        'starts_at' => self::DEFAULT_BREAK_START,
        'ends_at' => self::DEFAULT_BREAK_END,
        'paid' => false,
    ];

    private const NIGHT_SHIFT_END = '08:00';

    #[Url(as: 'mode')]
    public string $mode = 'list';

    // === List state ===

    public string $shiftTemplateExportJson = '';

    // === Builder form state ===

    public bool $showShiftBuilderForm = false;

    public bool $showAllShiftTemplates = true;

    public bool $showShiftTemplateImportModal = false;

    #[Url(as: 'template')]
    public string $selectedShiftTemplateKey = '';

    #[Url(as: 'shift')]
    public ?int $editingShiftTemplateId = null;

    public string $shiftCode = '';

    public string $shiftName = '';

    public string $shiftStartsAt = self::DEFAULT_SHIFT_START;

    public string $shiftEndsAt = self::DEFAULT_SHIFT_END;

    public string $shiftExpectedWorkMinutes = '480';

    /** @var list<array{label: string, starts_at: string, ends_at: string, paid: bool}> */
    public array $shiftBreaks = [];

    public string $shiftInWindowBeforeMinutes = '60';

    public string $shiftInWindowAfterMinutes = '15';

    public string $shiftOutWindowBeforeMinutes = '15';

    public string $shiftOutWindowAfterMinutes = '120';

    public string $shiftPayrollAttribution = 'shift_start_date';

    public string $shiftEffectiveFrom = '';

    public string $shiftEffectiveTo = '';

    public string $shiftStatus = 'active';

    public $shiftTemplateUpload = null;

    public function mount(): void
    {
        $selectedShiftTemplateKey = $this->selectedShiftTemplateKey;
        $this->shiftEffectiveFrom = now()->toDateString();

        if ($this->editingShiftTemplateId !== null) {
            $this->editShiftTemplate($this->editingShiftTemplateId);

            return;
        }

        if ($this->mode === 'form') {
            $this->startNewShift();

            if ($selectedShiftTemplateKey !== '') {
                $this->useShiftTemplate($selectedShiftTemplateKey);
            }
        }
    }

    /** Friendly attribute names so validation errors read like field labels. */
    protected function validationAttributes(): array
    {
        return [
            'shiftCode' => __('Shift code'),
            'shiftName' => __('Shift name'),
            'shiftStartsAt' => __('Shift start'),
            'shiftEndsAt' => __('Shift end'),
            'shiftExpectedWorkMinutes' => __('Expected work'),
            'shiftBreaks.*.label' => __('Break label'),
            'shiftBreaks.*.starts_at' => __('Break start'),
            'shiftBreaks.*.ends_at' => __('Break end'),
            'shiftBreaks.*.paid' => __('Break paid'),
            'shiftInWindowBeforeMinutes' => __('Clock-in before'),
            'shiftInWindowAfterMinutes' => __('Clock-in after'),
            'shiftOutWindowBeforeMinutes' => __('Clock-out before'),
            'shiftOutWindowAfterMinutes' => __('Clock-out after'),
            'shiftPayrollAttribution' => __('Payroll date'),
            'shiftEffectiveFrom' => __('Effective from'),
            'shiftEffectiveTo' => __('Effective to'),
            'shiftStatus' => __('Status'),
            'shiftTemplateUpload' => __('Template file'),
        ];
    }

    /** Concise message templates that read consistently regardless of the rule. */
    protected function messages(): array
    {
        return [
            'required' => ':attribute is required.',
            'required_with' => ':attribute is required.',
            'string' => ':attribute must be text.',
            'integer' => ':attribute must be a whole number.',
            'min.numeric' => ':attribute must be at least :min.',
            'max.numeric' => ':attribute must be at most :max.',
            'date' => ':attribute must be a date.',
            'date_format' => ':attribute must match :format.',
            'after_or_equal' => ':attribute must be on or after :date.',
            'alpha_dash' => ':attribute may only contain letters, numbers, dashes and underscores.',
            'in' => ':attribute is not a valid option.',
            'unique' => ':attribute is already in use.',
        ];
    }

    // === List-mode actions ===

    public function toggleShiftStatus(int $shiftTemplateId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $shift = $this->shiftTemplate($shiftTemplateId);
        $shift->update([
            'status' => $shift->status === 'active' ? 'inactive' : 'active',
        ]);

        $this->notify(__('Shift status updated.'));
    }

    public function exportShiftTemplate(int $shiftTemplateId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $shift = $this->shiftTemplate($shiftTemplateId);
        $serializer = app(ShiftTemplateSerializer::class);
        $this->shiftTemplateExportJson = $serializer->toJson($serializer->fromShiftTemplate($shift));

        $this->notify(__('Shift template JSON ready to download from :shift.', ['shift' => $shift->code]));
    }

    // === Mode transitions ===

    public function startNewShift(): void
    {
        $this->resetForm();
        $this->showShiftBuilderForm = false;
        $this->showAllShiftTemplates = true;
        $this->mode = 'form';
    }

    public function editShiftTemplate(int $shiftTemplateId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $shift = $this->shiftTemplate($shiftTemplateId);
        $this->editingShiftTemplateId = $shift->id;
        $this->shiftCode = $shift->code;
        $this->shiftName = $shift->name;
        $this->shiftStartsAt = substr((string) $shift->starts_at, 0, 5);
        $this->shiftEndsAt = substr((string) $shift->ends_at, 0, 5);
        $this->shiftExpectedWorkMinutes = (string) $shift->expected_work_minutes;
        $this->shiftPayrollAttribution = $shift->cross_midnight_attribution;
        $this->shiftEffectiveFrom = $shift->effective_from?->toDateString() ?? now()->toDateString();
        $this->shiftEffectiveTo = $shift->effective_to?->toDateString() ?? '';
        $this->shiftStatus = $shift->status;
        $this->loadBreakWindows($shift);
        $this->loadPunchWindows($shift);
        $this->showShiftBuilderForm = true;
        $this->showAllShiftTemplates = false;
        $this->selectedShiftTemplateKey = 'saved-shift';
        $this->mode = 'form';
    }

    public function duplicateShiftTemplate(int $shiftTemplateId): void
    {
        $this->editShiftTemplate($shiftTemplateId);
        $source = $this->shiftTemplate($shiftTemplateId);
        $this->editingShiftTemplateId = null;
        $this->shiftCode = $this->uniqueShiftCode($source->code.'_COPY');
        $this->shiftName = $source->name.' Copy';
        $this->shiftStatus = 'inactive';
    }

    public function cancelShiftEdit(): void
    {
        $this->resetForm();
        $this->showShiftBuilderForm = false;
        $this->showAllShiftTemplates = true;
        $this->mode = 'list';
    }

    // === Builder form actions ===

    public function useShiftTemplate(string $templateKey): void
    {
        if ($this->selectedShiftTemplateKey === $templateKey && ! $this->showAllShiftTemplates) {
            $this->resetForm();
            $this->showShiftBuilderForm = false;
            $this->showAllShiftTemplates = true;

            return;
        }

        $template = collect($this->shiftTemplatePresets())->firstWhere('key', $templateKey);
        if (! is_array($template)) {
            return;
        }

        $this->resetForm();
        $this->applyTemplate($template);
        $this->showShiftBuilderForm = true;
        $this->showAllShiftTemplates = false;
        $this->selectedShiftTemplateKey = $templateKey;
        $this->mode = 'form';
    }

    public function saveShiftTemplate(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $companyId = $this->companyId();
        $validated = $this->validate([
            'shiftCode' => [
                'required', 'string', 'max:60', 'alpha_dash',
                Rule::unique('people_attendance_shift_templates', 'code')->where('company_id', $companyId)->ignore($this->editingShiftTemplateId),
            ],
            'shiftName' => ['required', 'string', 'max:120'],
            'shiftStartsAt' => ['required', 'date_format:H:i'],
            'shiftEndsAt' => ['required', 'date_format:H:i'],
            'shiftExpectedWorkMinutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'shiftBreaks' => ['array', 'max:2'],
            'shiftBreaks.*.label' => ['nullable', 'string', 'max:60'],
            'shiftBreaks.*.starts_at' => ['nullable', 'required_with:shiftBreaks.*.ends_at', 'date_format:H:i'],
            'shiftBreaks.*.ends_at' => ['nullable', 'required_with:shiftBreaks.*.starts_at', 'date_format:H:i'],
            'shiftBreaks.*.paid' => ['nullable', 'boolean'],
            'shiftInWindowBeforeMinutes' => ['required', 'integer', 'min:0', 'max:720'],
            'shiftInWindowAfterMinutes' => ['required', 'integer', 'min:0', 'max:720'],
            'shiftOutWindowBeforeMinutes' => ['required', 'integer', 'min:0', 'max:720'],
            'shiftOutWindowAfterMinutes' => ['required', 'integer', 'min:0', 'max:720'],
            'shiftPayrollAttribution' => ['required', Rule::in(['shift_start_date', 'shift_end_date'])],
            'shiftEffectiveFrom' => ['required', 'date'],
            'shiftEffectiveTo' => ['nullable', 'date', 'after_or_equal:shiftEffectiveFrom'],
            'shiftStatus' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $shift = $this->editingShiftTemplateId === null
            ? AttendanceShiftTemplate::query()->create($this->shiftAttributes($validated, $companyId))
            : tap($this->shiftTemplate($this->editingShiftTemplateId))->update($this->shiftAttributes($validated, $companyId));

        $this->syncPunchWindows($shift->refresh(), $validated);

        $this->resetForm();
        $this->showShiftBuilderForm = false;
        $this->showAllShiftTemplates = true;
        $this->mode = 'list';
        $this->notify(__('Shift template saved.'));
    }

    public function importShiftTemplate(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $this->validate([
            'shiftTemplateUpload' => ['required', 'file', 'max:256', 'extensions:json'],
        ]);

        $payload = json_decode($this->templateUploadContents($this->shiftTemplateUpload), true);
        if (! is_array($payload)) {
            $this->addError('shiftTemplateUpload', __('Upload a valid JSON shift template.'));

            return;
        }

        $template = array_is_list($payload) ? ($payload[0] ?? null) : $payload;
        if (! is_array($template)) {
            $this->addError('shiftTemplateUpload', __('The JSON must be a template object or an array of template objects.'));

            return;
        }

        $this->resetForm();
        $this->applyTemplate($template);
        $this->showShiftBuilderForm = true;
        $this->showShiftTemplateImportModal = false;
        $this->showAllShiftTemplates = false;
        $this->selectedShiftTemplateKey = 'imported-template';
        $this->shiftTemplateUpload = null;
        $this->mode = 'form';

        $this->notify(__('Shift template loaded. Review and save it as a reusable shift.'));
    }

    public function render(): View
    {
        $schemaReady = $this->schemaReady();

        return view('people-attendance::livewire.people.attendance.shift-templates', [
            'schemaReady' => $schemaReady,
            'canManage' => $this->canAttendance('people.attendance.manage'),
            'shiftTemplates' => $schemaReady
                ? AttendanceShiftTemplate::query()
                    ->where('company_id', $this->companyId())
                    ->with('punchWindows')
                    ->orderBy('code')
                    ->get()
                : collect(),
            'shiftTemplatePresets' => $this->shiftTemplatePresets(),
        ]);
    }

    private function shiftTemplate(int $shiftTemplateId): AttendanceShiftTemplate
    {
        return AttendanceShiftTemplate::query()
            ->where('company_id', $this->companyId())
            ->with('punchWindows')
            ->findOrFail($shiftTemplateId);
    }

    /** @return list<array<string, mixed>> */
    private function shiftTemplatePresets(): array
    {
        return [
            [
                'key' => 'office-day',
                'code' => 'OFFICE_DAY',
                'name' => __('Office day'),
                'summary' => __('08:00 to 17:00 with a one-hour lunch break.'),
                'best_for' => __('Office teams and simple fixed-day rosters.'),
                'starts_at' => self::DEFAULT_SHIFT_START,
                'ends_at' => self::DEFAULT_SHIFT_END,
                'expected_work_minutes' => 480,
                ...self::DEFAULT_BREAK_WINDOW,
                'in_before' => 60,
                'in_after' => 15,
                'out_before' => 15,
                'out_after' => 120,
                'cross_midnight_attribution' => 'shift_start_date',
            ],
            [
                'key' => 'office-day-flexi',
                'code' => 'FLEXI',
                'name' => __('Office flexi'),
                'summary' => __('Reference 09:00–18:00 with a one-hour lunch break; wide grace windows approximate the flexi band. Pair with a Policy Group that validates paid-work hours, not boundary times.'),
                'best_for' => __('Knowledge workers and teams with flexible attendance contracts.'),
                'starts_at' => '09:00',
                'ends_at' => '18:00',
                'expected_work_minutes' => 480,
                ...self::DEFAULT_BREAK_WINDOW,
                'in_before' => 120,
                'in_after' => 120,
                'out_before' => 120,
                'out_after' => 180,
                'cross_midnight_attribution' => 'shift_start_date',
            ],
            [
                'key' => 'production-day',
                'code' => 'PROD_DAY',
                'name' => __('Production day'),
                'summary' => __('07:00 to 19:00 with tighter punch windows for production floors.'),
                'best_for' => __('Factories, warehouses and line-based work.'),
                'starts_at' => '07:00',
                'ends_at' => '19:00',
                'expected_work_minutes' => 660,
                ...self::DEFAULT_BREAK_WINDOW,
                'in_before' => 45,
                'in_after' => 10,
                'out_before' => 10,
                'out_after' => 180,
                'cross_midnight_attribution' => 'shift_start_date',
            ],
            [
                'key' => 'production-breaks',
                'code' => 'PROD_BREAKS',
                'name' => __('Production (meal + tea)'),
                'summary' => __('07:00 to 19:00 with a one-hour meal break (on the dial) and a half-hour tea break (in the readout panel).'),
                'best_for' => __('Production floors with scheduled meal and tea breaks.'),
                'starts_at' => '07:00',
                'ends_at' => '19:00',
                'expected_work_minutes' => 630,
                'break_windows' => [
                    self::DEFAULT_MEAL_BREAK_WINDOW,
                    ['label' => 'Tea break',  'starts_at' => '15:00', 'ends_at' => '15:30', 'paid' => false],
                ],
                'in_before' => 45,
                'in_after' => 10,
                'out_before' => 10,
                'out_after' => 180,
                'cross_midnight_attribution' => 'shift_start_date',
            ],
            [
                'key' => 'night-shift',
                'code' => 'NIGHT_SHIFT',
                'name' => __('Night shift'),
                'summary' => __('20:00 to 08:00, crossing midnight with payroll attributed to shift start.'),
                'best_for' => __('Security, operations and overnight production teams.'),
                'starts_at' => '20:00',
                'ends_at' => self::NIGHT_SHIFT_END,
                'expected_work_minutes' => 660,
                'break_starts_at' => '00:00',
                'break_ends_at' => '01:00',
                'in_before' => 60,
                'in_after' => 15,
                'out_before' => 15,
                'out_after' => 180,
                'cross_midnight_attribution' => 'shift_start_date',
            ],
        ];
    }

    /** @param array<string, mixed> $template */
    private function applyTemplate(array $template): void
    {
        $templateBreaks = is_array($template['break_windows'] ?? null) ? $template['break_windows'] : [];
        $firstBreak = is_array($templateBreaks[0] ?? null) ? $templateBreaks[0] : [];
        $punch = is_array($template['punch_windows'] ?? null) ? $template['punch_windows'] : [];
        $this->shiftCode = $this->uniqueShiftCode((string) ($template['code'] ?? 'SHIFT'));
        $this->shiftName = (string) ($template['name'] ?? __('Imported shift'));
        $this->shiftStartsAt = (string) ($template['starts_at'] ?? $this->shiftStartsAt);
        $this->shiftEndsAt = (string) ($template['ends_at'] ?? $this->shiftEndsAt);
        $this->shiftExpectedWorkMinutes = (string) ($template['expected_work_minutes'] ?? $this->shiftExpectedWorkMinutes);
        $this->shiftBreaks = $this->normalizeBreaksForState($templateBreaks, [
            'starts_at' => (string) ($template['break_starts_at'] ?? $firstBreak['starts_at'] ?? ''),
            'ends_at' => (string) ($template['break_ends_at'] ?? $firstBreak['ends_at'] ?? ''),
        ]);
        $this->shiftInWindowBeforeMinutes = (string) ($template['in_before'] ?? $punch['in']['before_minutes'] ?? $this->shiftInWindowBeforeMinutes);
        $this->shiftInWindowAfterMinutes = (string) ($template['in_after'] ?? $punch['in']['after_minutes'] ?? $this->shiftInWindowAfterMinutes);
        $this->shiftOutWindowBeforeMinutes = (string) ($template['out_before'] ?? $punch['out']['before_minutes'] ?? $this->shiftOutWindowBeforeMinutes);
        $this->shiftOutWindowAfterMinutes = (string) ($template['out_after'] ?? $punch['out']['after_minutes'] ?? $this->shiftOutWindowAfterMinutes);
        $this->shiftPayrollAttribution = (string) ($template['cross_midnight_attribution'] ?? $this->shiftPayrollAttribution);
    }

    private function resetForm(): void
    {
        $this->editingShiftTemplateId = null;
        $this->selectedShiftTemplateKey = '';
        $this->shiftCode = '';
        $this->shiftName = '';
        $this->shiftStartsAt = self::DEFAULT_SHIFT_START;
        $this->shiftEndsAt = self::DEFAULT_SHIFT_END;
        $this->shiftExpectedWorkMinutes = '480';
        $this->shiftBreaks = [];
        $this->shiftInWindowBeforeMinutes = '60';
        $this->shiftInWindowAfterMinutes = '15';
        $this->shiftOutWindowBeforeMinutes = '15';
        $this->shiftOutWindowAfterMinutes = '120';
        $this->shiftPayrollAttribution = 'shift_start_date';
        $this->shiftEffectiveFrom = now()->toDateString();
        $this->shiftEffectiveTo = '';
        $this->shiftStatus = 'active';
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function shiftAttributes(array $validated, int $companyId): array
    {
        return [
            'company_id' => $companyId,
            'code' => str($validated['shiftCode'])->upper()->toString(),
            'name' => $validated['shiftName'],
            'starts_at' => $validated['shiftStartsAt'],
            'ends_at' => $validated['shiftEndsAt'],
            'crosses_midnight' => $validated['shiftEndsAt'] <= $validated['shiftStartsAt'],
            'expected_work_minutes' => (int) $validated['shiftExpectedWorkMinutes'],
            'break_windows' => $this->breakWindows($validated),
            'cross_midnight_attribution' => $validated['shiftPayrollAttribution'],
            'effective_from' => $validated['shiftEffectiveFrom'],
            'effective_to' => $this->blankToNull($validated['shiftEffectiveTo'] ?? null),
            'status' => $validated['shiftStatus'],
            'source_system' => 'blb-ui',
            'metadata' => ['created_from' => 'attendance_shift_builder'],
        ];
    }

    private function uniqueShiftCode(string $baseCode): string
    {
        $baseCode = str($baseCode)->upper()->replaceMatches('/[^A-Z0-9_]+/', '_')->trim('_')->toString() ?: 'SHIFT';
        $candidate = $baseCode;
        $suffix = 2;

        while (AttendanceShiftTemplate::query()->where('company_id', $this->companyId())->where('code', $candidate)->exists()) {
            $candidate = $baseCode.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
