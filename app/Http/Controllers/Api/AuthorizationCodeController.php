<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiError;
use App\Http\Controllers\Controller;
use App\Models\AuthorizationCode;
use App\Models\User;
use App\Support\CliTokenNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorizationCodeController extends Controller
{
    public function token(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'redirect_uri' => ['required', 'string'],
        ]);

        $code = AuthorizationCode::findByCode($validated['code']);

        if ($code === null) {
            return ApiError::response(400, 'invalid_grant', __('Unknown authorization code.'));
        }

        if ($code->isExpired()) {
            return ApiError::response(400, 'expired_token', __('The authorization code has expired.'));
        }

        if ($code->isConsumed()) {
            return ApiError::response(400, 'invalid_grant', __('Unknown authorization code.'));
        }

        if (! hash_equals($code->redirect_uri, $validated['redirect_uri'])) {
            return ApiError::response(400, 'invalid_grant', __('Unknown authorization code.'));
        }

        if (! $code->consume()) {
            return ApiError::response(400, 'invalid_grant', __('Unknown authorization code.'));
        }

        $user = $code->user;

        if (! $user instanceof User) {
            return ApiError::response(400, 'invalid_grant', __('Unknown authorization code.'));
        }

        $token = $user->createToken(CliTokenNames::forLabel($code->label))->plainTextToken;

        return new JsonResponse(['token' => $token]);
    }
}
