<?php

namespace App\Http\Controllers;

use App\Models\WorkEvidence;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WorkEvidenceController extends Controller
{
    public function __invoke(string $token): StreamedResponse
    {
        $evidence = WorkEvidence::query()->where('public_token', $token)->firstOrFail();
        $stream = rescue(fn () => Storage::disk()->readStream($evidence->storage_key), null, false);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $evidence->content_type,
            'Content-Length' => (string) $evidence->size_bytes,
            'Content-Disposition' => 'inline; filename="work-evidence-'.$evidence->id.'.'.$evidence->extension.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
