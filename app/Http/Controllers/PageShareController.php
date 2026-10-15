<?php

namespace App\Http\Controllers;

use App\Enums\PageShareKind;
use App\Features\Artifacts;
use App\Models\Page;
use App\Models\PageShare;
use App\Notifications\ExternalPageShareNotification;
use App\Support\ArtifactRenderOrigin;
use App\Support\HumanPageAccess;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Throwable;

final class PageShareController extends Controller
{
    public function __construct(private readonly HumanPageAccess $access) {}

    public function store(Request $request, string $page, ArtifactRenderOrigin $origin): RedirectResponse|Response
    {
        $owner = $this->owner($request, $page, $origin);

        if (! $owner instanceof User) {
            return $this->notFound();
        }

        $ownedPage = Page::query()
            ->whereKey($page)
            ->where('owner_user_id', (string) $owner->getKey())
            ->first();

        if (! $ownedPage instanceof Page) {
            return $this->notFound();
        }

        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::enum(PageShareKind::class)],
            'internal_email' => ['required_if:kind,internal', 'prohibited_unless:kind,internal', 'email:rfc', 'max:255'],
            'recipient_email' => ['required_if:kind,external', 'prohibited_unless:kind,external', 'email:rfc', 'max:255'],
            'expires_at' => ['nullable', 'prohibited_if:kind,internal', 'date'],
        ]);
        $kind = PageShareKind::from((string) $validated['kind']);
        $recipient = null;
        $recipientEmail = null;
        $expiresAt = null;

        if ($kind === PageShareKind::Internal) {
            $email = mb_strtolower(trim((string) $validated['internal_email']));
            $recipient = User::query()->whereRaw('lower(email) = ?', [$email])->where('status', 'active')->first();

            if (! $recipient instanceof User || ! RolePolicy::canUseProduct($recipient->role)) {
                throw ValidationException::withMessages(['internal_email' => ['The recipient is unavailable.']]);
            }
        } else {
            $expiresAt = $this->expiry($validated['expires_at'] ?? null);

            if ($kind === PageShareKind::External) {
                $recipientEmail = mb_strtolower(trim((string) $validated['recipient_email']));
            }
        }

        $share = PageShare::query()->create([
            'page_id' => $ownedPage->id,
            'created_by_user_id' => (string) $owner->getKey(),
            'kind' => $kind,
            'internal_recipient_user_id' => $recipient?->getKey(),
            'recipient_email' => $recipientEmail,
            'expires_at' => $expiresAt,
        ]);

        if ($kind === PageShareKind::External) {
            Notification::route('mail', $recipientEmail)->notify(
                new ExternalPageShareNotification(
                    shareId: $share->id,
                    pageTitle: $ownedPage->title,
                    viewerUrl: $origin->signedPageShareViewerUrl($share),
                ),
            );
        }

        return redirect()->route('pages.show', ['page' => $ownedPage]);
    }

    public function destroy(Request $request, string $page, string $share, ArtifactRenderOrigin $origin): RedirectResponse|Response
    {
        $owner = $this->owner($request, $page, $origin);

        if (! $owner instanceof User || ! Str::isUuid($share)) {
            return $this->notFound();
        }

        $ownedPage = Page::query()
            ->whereKey($page)
            ->where('owner_user_id', (string) $owner->getKey())
            ->first();

        if (! $ownedPage instanceof Page) {
            return $this->notFound();
        }

        $grant = PageShare::query()->whereKey($share)->where('page_id', $ownedPage->id)->first();

        if (! $grant instanceof PageShare) {
            return $this->notFound();
        }

        if ($grant->revoked_at === null) {
            $grant->forceFill(['revoked_at' => now()->startOfSecond()])->save();
        }

        return redirect()->route('pages.show', ['page' => $ownedPage]);
    }

    private function owner(Request $request, string $page, ArtifactRenderOrigin $origin): ?User
    {
        if (! Feature::active(Artifacts::class)
            || ! $origin->isConfigured()
            || $request->getHost() === $origin->renderHost()
            || ! Str::isUuid($page)) {
            return null;
        }

        return $this->access->admittedUser($request);
    }

    private function expiry(mixed $value): CarbonImmutable
    {
        if ($value === null || $value === '') {
            return CarbonImmutable::instance(now()->addDays(30)->startOfSecond());
        }

        try {
            $expiry = CarbonImmutable::parse((string) $value)->utc()->startOfSecond();
        } catch (Throwable) {
            throw ValidationException::withMessages(['expires_at' => ['The expiry must be a valid instant.']]);
        }

        if (! $expiry->isAfter(now()) || $expiry->isAfter(now()->addYear())) {
            throw ValidationException::withMessages(['expires_at' => ['The expiry must be in the future and no more than one year away.']]);
        }

        return $expiry;
    }

    private function notFound(): Response
    {
        return response('', 404);
    }
}
