<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /cli/{path} — PUBLIC, unauthenticated serving of `capstan-cli` release
 * artifacts out of the private `downloads` bucket.
 *
 * The release pipeline (GoReleaser) publishes signed binaries under
 * `cli/<version>/<file>`. capstan-cli is a PRIVATE repo, so there are no public
 * GitHub Release URLs — this route is the download host. The bucket stays
 * private and this app is the gatekeeper: the object is STREAMED through here,
 * which needs only server-side read access (no public endpoint, no signed URLs).
 *
 * The key is ALWAYS exactly `cli/{path}` on the `downloads` disk. No filesystem
 * path is ever constructed, the path is allowlist-validated before it is used,
 * and anything that is not a readable object is an indistinguishable 404 — a
 * missing file, a bad prefix, and a directory all look the same from outside.
 */
class CliDownloadController extends Controller
{
    /**
     * The only shape a download path may take: it must begin with an
     * alphanumeric and thereafter allows letters, digits, dot, underscore,
     * hyphen and the `/` separator. This rejects a leading slash, backslashes,
     * null bytes, control characters and every other escape vector outright —
     * the route constraint enforces the same shape, and this is the second gate.
     */
    private const ALLOWED_PATH = '#\A[A-Za-z0-9][A-Za-z0-9._/\-]*\z#';

    /**
     * A versioned prefix (`cli/v1.2.3/...`) is immutable content and can be
     * cached forever; anything else (a moving manifest) must not be.
     */
    private const VERSIONED_PREFIX = '#\A[vV]?[0-9][A-Za-z0-9.\-+]*/#';

    public function __invoke(string $path): StreamedResponse
    {
        // Fail closed. A fork that has not provisioned a downloads bucket has no
        // CLI download host at all, so the route simply does not exist for it —
        // far better than a 500 out of an unconfigured S3 adapter on a public,
        // unauthenticated URL.
        abort_if(blank(config('filesystems.disks.downloads.bucket')), 404);

        // Defense in depth: the key is pinned to the cli/ prefix, so traversal
        // must be refused before the path can reach the disk. Note `..` is
        // separately excluded because `.` is otherwise a legal filename char.
        if (preg_match(self::ALLOWED_PATH, $path) !== 1 || str_contains($path, '..')) {
            abort(404);
        }

        $key = 'cli/'.$path;

        $disk = Storage::disk('downloads');

        // The key must resolve to an actual FILE. This is not redundant with the
        // readStream() check below: on BSD/macOS `fopen()` on a directory hands
        // back a usable resource, which would otherwise serve an empty 200 and
        // confirm the directory's existence. Missing object, unreadable store
        // and directory all collapse to the same 404 — never a 403, never a
        // listing, nothing about the bucket's shape leaks to the caller.
        abort_unless($disk->fileExists($key), 404);

        $stream = $disk->readStream($key);
        abort_unless(is_resource($stream), 404);

        $contentLength = rescue(fn (): int => $disk->size($key), null, false);

        $headers = $this->headers($path);

        if ($contentLength !== null) {
            $headers['Content-Length'] = (string) $contentLength;
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $path): array
    {
        return [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.basename($path).'"',
            'X-Content-Type-Options' => 'nosniff',
            // no-transform keeps intermediaries (e.g. Cloudflare's edge) from
            // rewriting the served bytes — a CLI binary must arrive byte-exact
            // or its checksum/signature no longer verifies.
            'Cache-Control' => preg_match(self::VERSIONED_PREFIX, $path) === 1
                ? 'public, max-age=31536000, immutable, no-transform'
                : 'no-transform',
        ];
    }
}
