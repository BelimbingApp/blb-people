<?php
/**
 * Printable training passport (0014-a). Rendered inline by the platform PDF
 * renderer, so this is a self-contained document: no layout, no Vite, no
 * request context. The watermark repeats the generation date and employee
 * id on every page.
 *
 * @var \App\Domains\People\Training\Data\TrainingPassport $passport
 * @var list<\App\Domains\People\Training\Data\TrainingPassportSkillLevel> $skillLevels
 * @var int $employeeId
 * @var \Carbon\CarbonImmutable $generatedAt
 * @var string $watermark
 */
?>
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Training passport') }} — {{ __('Employee #:id', ['id' => $employeeId]) }}</title>
    <style>
        @page { size: A4; margin: 18mm 16mm 22mm 16mm; }
        body { font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; font-size: 10.5pt; color: #1f2937; margin: 0; }
        h1 { font-size: 18pt; margin: 0 0 2mm; }
        h2 { font-size: 12.5pt; margin: 8mm 0 2mm; border-bottom: 1px solid #d1d5db; padding-bottom: 1mm; }
        .meta { color: #6b7280; font-size: 9pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 2mm; }
        th, td { text-align: left; padding: 1.5mm 2mm; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { font-size: 9pt; text-transform: uppercase; letter-spacing: .03em; color: #6b7280; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .tag { display: inline-block; padding: .5mm 1.5mm; border-radius: 1mm; font-size: 8.5pt; border: 1px solid #9ca3af; }
        .tag-expired { border-color: #b91c1c; color: #b91c1c; font-weight: bold; }
        .tag-current { border-color: #047857; color: #047857; }
        .empty { color: #6b7280; font-style: italic; }
        .watermark {
            position: fixed; top: 40%; left: 0; right: 0; text-align: center;
            font-size: 22pt; color: rgba(107, 114, 128, .18); transform: rotate(-24deg);
            pointer-events: none; z-index: -1;
        }
        .footer { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 8pt; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <div class="watermark" data-watermark>{{ $watermark }}</div>
    <div class="footer">{{ $watermark }}</div>

    <h1>{{ __('Training passport') }}</h1>
    <p class="meta">
        {{ __('Employee #:id', ['id' => $employeeId]) }}
        · {{ __('Generated :date', ['date' => $generatedAt->format('Y-m-d H:i')]) }}
        · {{ __('Training attendance and certificate validity are recorded separately from competence.') }}
    </p>

    <h2>{{ __('Completed training events') }}</h2>
    @php($completed = collect($passport->events)->filter(fn ($event): bool => $event->status === \App\Domains\People\Training\Enums\TrainingEventStatus::Completed || $event->attended)->values())
    @if ($completed->isEmpty())
        <p class="empty">{{ __('No completed training events recorded.') }}</p>
    @else
        <table>
            <thead><tr><th>{{ __('Training') }}</th><th>{{ __('Date') }}</th><th>{{ __('Status') }}</th><th>{{ __('Attendance') }}</th><th class="num">{{ __('Minutes') }}</th></tr></thead>
            <tbody>
            @foreach ($completed as $event)
                <tr>
                    <td>{{ $event->title }}</td>
                    <td>{{ $event->startsAt->format('Y-m-d') }}</td>
                    <td>{{ $event->statusLabel }}</td>
                    <td>{{ $event->attended ? __('Attended') : __('Attendance not recorded') }}</td>
                    <td class="num">{{ $event->actualMinutes }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ __('Certificates') }}</h2>
    @if ($passport->certificates === [])
        <p class="empty">{{ __('No certificates recorded.') }}</p>
    @else
        <table>
            <thead><tr><th>{{ __('Certificate') }}</th><th>{{ __('Training') }}</th><th>{{ __('Valid from') }}</th><th>{{ __('Valid until') }}</th><th>{{ __('State') }}</th></tr></thead>
            <tbody>
            @foreach ($passport->certificates as $certificate)
                <tr data-certificate-status="{{ $certificate->expired ? 'expired' : 'current' }}">
                    <td>{{ $certificate->reference }}</td>
                    <td>{{ $certificate->eventTitle }}</td>
                    <td>{{ $certificate->validFrom?->format('Y-m-d') ?? '—' }}</td>
                    <td>{{ $certificate->validUntil?->format('Y-m-d') ?? __('No expiry recorded') }}</td>
                    <td><span class="tag {{ $certificate->expired ? 'tag-expired' : 'tag-current' }}">{{ $certificate->expired ? __('EXPIRED') : __('Current') }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ __('Current skill levels') }}</h2>
    @if ($skillLevels === [])
        <p class="empty">{{ __('No finalized skill level recorded.') }}</p>
    @else
        <table>
            <thead><tr><th>{{ __('Skill') }}</th><th>{{ __('Code') }}</th><th class="num">{{ __('Current level') }}</th><th class="num">{{ __('Required level') }}</th><th>{{ __('Assessed') }}</th><th>{{ __('Valid until') }}</th></tr></thead>
            <tbody>
            @foreach ($skillLevels as $level)
                <tr>
                    <td>{{ $level->name }}</td>
                    <td>{{ $level->code }}</td>
                    <td class="num">{{ __('Level :level', ['level' => $level->currentLevel]) }}</td>
                    <td class="num">{{ $level->requiredLevel }}</td>
                    <td>{{ $level->assessedAt?->format('Y-m-d') ?? '—' }}</td>
                    <td>{{ $level->validUntil?->format('Y-m-d') ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ __('Skills covered by training') }}</h2>
    @if ($passport->skills === [])
        <p class="empty">{{ __('No skills are mapped to the recorded training events.') }}</p>
    @else
        <p>
            @foreach ($passport->skills as $skill)
                <span class="tag">{{ $skill->name }} ({{ $skill->code }})</span>
            @endforeach
        </p>
    @endif
</body>
</html>
