<?php

namespace App\Modules\People\Settings\Exceptions;

use App\Base\Foundation\Exceptions\BlbDataContractException;

final class PeopleReferenceImportException extends BlbDataContractException
{
    public static function temporaryCsvStreamUnavailable(): self
    {
        return new self('Unable to allocate temporary CSV stream.');
    }

    public static function zipExtensionMissing(): self
    {
        return new self('XLSX imports require the PHP zip extension.');
    }

    public static function temporaryXlsxFileUnavailable(): self
    {
        return new self('Unable to create temporary XLSX file.');
    }

    public static function xlsxOpenFailed(): self
    {
        return new self('Unable to open XLSX import.');
    }

    public static function unreadableFirstSheet(): self
    {
        return new self('XLSX import has no readable first sheet.');
    }
}
