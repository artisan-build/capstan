<?php

namespace App\Http\Controllers\Api;

use App\Auth\CapstanCredentialDeclaration;
use App\Features\Postmaster;
use App\Http\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateBoundCredential;
use App\Postmaster\ReplaceSpokeCredential;
use ArtisanBuild\BuiltForCloud\BoundBearerCredentialAuthenticator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use RuntimeException;

final class ReplacePostmasterSpokeController extends Controller
{
    public const string PREVIOUS_CREDENTIAL_HEADER = 'X-Capstan-Previous-Credential';

    public function __invoke(
        Request $request,
        BoundBearerCredentialAuthenticator $credentials,
        ReplaceSpokeCredential $replace,
    ): JsonResponse {
        if (! Feature::active(Postmaster::class)) {
            return ApiError::notFound();
        }

        $current = AuthenticateBoundCredential::credential($request);
        $actorId = AuthenticateBoundCredential::actorId($request);
        $oldToken = $request->header(self::PREVIOUS_CREDENTIAL_HEADER);
        $oldCredential = null;

        if (is_string($oldToken) && $oldToken !== '' && strlen($oldToken) <= 4096
            && preg_match('/[\x00-\x1F\x7F]/', $oldToken) !== 1) {
            $oldRequest = clone $request;
            $oldRequest->headers->set('Authorization', 'Bearer '.$oldToken);
            $oldCredential = $credentials->authenticate($oldRequest, CapstanCredentialDeclaration::MACHINE);
        }

        try {
            $result = $replace->handle(
                $actorId,
                $current->id,
                $oldCredential,
                CarbonImmutable::now(),
                is_string($oldToken) ? $oldToken : null,
                AuthenticateBoundCredential::appPurpose($request),
            );
        } catch (RuntimeException $exception) {
            return ApiError::response(409, 'replacement_conflict', $exception->getMessage());
        }

        if ($result['conflict'] || $result['spoke'] === null) {
            return ApiError::response(409, 'replacement_conflict', 'Another reinstall already replaced this Postmaster spoke.');
        }

        return new JsonResponse([
            'spoke_id' => $result['spoke']->id,
            'replaced' => $result['replaced'],
        ]);
    }
}
