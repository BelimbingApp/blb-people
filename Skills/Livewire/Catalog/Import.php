<?php

namespace App\Domains\People\Skills\Livewire\Catalog;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Domains\People\Skills\Exceptions\InvalidRequirementProfileException;
use App\Domains\People\Skills\Exceptions\InvalidSkillCatalogException;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\StarterProfileImporter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * HR upload page for the starter-profile workbook (plan 0008, #299). One
 * CSV per submission; the importer validates every row before writing and
 * the page shows either the per-row error table or the written counts.
 *
 * Authorization follows Catalog\Index: the import capability plus the HR
 * audience on mount, and — because companyEntityId is client-writable — a
 * fresh HR-for-this-company check on every action. XLSX is not accepted:
 * the platform ships no spreadsheet reader and the 0006-a workbook reader
 * is bound to the 18-sheet master's layout.
 */
class Import extends Component
{
    use WithFileUploads;

    public const CAPABILITY = 'people.skill.catalog.import';

    public ?int $companyEntityId = null;

    public ?TemporaryUploadedFile $workbook = null;

    /** @var array{errors: list<array{row: int, message: string}>, rows: int, skills: int, requirements: int, profiles: int, file: string}|null */
    public ?array $result = null;

    /** @var array<int, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $this->companyEntityId = $companies === [] ? null : (int) array_key_first($companies);
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
        $this->reset('result', 'workbook');
    }

    public function import(StarterProfileImporter $importer): void
    {
        $companyEntityId = $this->authorizedCompanyForImport();
        $this->validate(['workbook' => ['required', 'file', 'max:4096', 'extensions:csv,txt', 'mimes:csv,txt,plain']]);
        $file = $this->workbook;
        $name = (string) $file->getClientOriginalName();

        try {
            $outcome = $importer->import(
                $companyEntityId,
                $file->getRealPath(),
                $name,
                StarterProfileImporter::SOURCE_KEY,
                (int) Auth::id(),
            );
        } catch (InvalidSkillCatalogException|InvalidRequirementProfileException $exception) {
            $this->addError('workbook', $exception->getMessage());

            return;
        }

        $this->result = $outcome + ['file' => $name];
        $this->reset('workbook');
    }

    public function render(): View
    {
        $companies = $this->allowedCompanies();

        return view('people::livewire.catalog.import', [
            'companies' => $companies,
            'columns' => StarterProfileImporter::HEADER,
        ]);
    }

    /** @return array<int, string> */
    private function allowedCompanies(): array
    {
        return $this->allowedCompanies ??= app(SkillAudience::class)->allowedCompanies(Auth::user(), self::CAPABILITY);
    }

    private function authorizeView(): void
    {
        try {
            $audiences = app(SkillAudience::class)->authorizeAudience(Auth::user(), self::CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        abort_unless(in_array(SkillAudience::HR, $audiences, true), 403);
    }

    /** The single funnel for the mutating action: capability, HR audience, and this company. */
    private function authorizedCompanyForImport(): int
    {
        $this->authorizeView();
        abort_if($this->companyEntityId === null, 404);
        abort_unless(array_key_exists($this->companyEntityId, $this->allowedCompanies()), 404);

        try {
            app(SkillAudience::class)->assertHr(Auth::user(), $this->companyEntityId);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        return $this->companyEntityId;
    }
}
