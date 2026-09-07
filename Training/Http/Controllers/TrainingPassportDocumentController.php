<?php

namespace App\Domains\People\Training\Http\Controllers;

use App\Core\User\Models\User;
use App\Domains\People\Training\Exceptions\TrainingPassportDenied;
use App\Domains\People\Training\Services\TrainingPassportDocumentStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a generated training passport PDF (0014-a) as a download.
 *
 * The store decides who may fetch which document; this controller only
 * turns its answer into bytes or a 404. A refused id and an unknown id are
 * the same response on purpose, so probing ids reveals nothing.
 */
final class TrainingPassportDocumentController
{
    public function __invoke(Request $request, TrainingPassportDocumentStore $store, int $documentId): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        try {
            $document = $store->authorizeDownload($actor, $documentId);
        } catch (TrainingPassportDenied) {
            abort(404);
        }

        $asset = $document->asset;
        abort_if($asset === null, 404);
        $disk = Storage::disk($asset->disk);
        abort_unless($disk->exists($asset->storage_key), 404);

        return $disk->response(
            $asset->storage_key,
            $asset->original_filename ?? basename($asset->storage_key),
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, max-age=0',
            ],
            'attachment',
        );
    }
}
