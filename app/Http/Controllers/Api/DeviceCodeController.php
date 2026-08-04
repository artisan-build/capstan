<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceCodeStatus;
use App\Http\ApiError;
use App\Http\Controllers\Controller;
use App\Models\DeviceCode;
use App\Models\User;
use App\Support\CliTokenNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceCodeController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:64'],
        ]);

        ['device_code' => $deviceCode, 'model' => $model] = DeviceCode::issue(
            CliTokenNames::sanitizeLabel($validated['label'] ?? null),
        );

        return new JsonResponse([
            'device_code' => $deviceCode,
            'user_code' => $model->user_code,
            'verification_uri' => route('cli.device.verify'),
            'verification_uri_complete' => route('cli.device.verify', ['user_code' => $model->user_code]),
            'interval' => $model->interval,
            'expires_in' => DeviceCode::LIFETIME_SECONDS,
        ]);
    }

    public function token(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_code' => ['required', 'string'],
        ]);

        $device = DeviceCode::findByDeviceCode($validated['device_code']);

        if ($device === null) {
            return ApiError::response(400, 'invalid_grant', __('Unknown device code.'));
        }

        if ($device->isExpired()) {
            return ApiError::response(400, 'expired_token', __('The device code has expired.'));
        }

        $lastPolled = $device->last_polled_at;
        $device->forceFill(['last_polled_at' => now()])->save();

        if ($lastPolled !== null && now()->lt($lastPolled->addSeconds($device->interval))) {
            return ApiError::response(400, 'slow_down', __('Polling too frequently; slow down.'));
        }

        if ($device->isPending()) {
            return ApiError::response(400, 'authorization_pending', __('Authorization is still pending.'));
        }

        if ($device->isDenied()) {
            return ApiError::response(400, 'access_denied', __('The authorization request was denied.'));
        }

        $consumed = DeviceCode::query()
            ->whereKey($device->getKey())
            ->where('status', DeviceCodeStatus::Approved->value)
            ->delete();

        if ($consumed !== 1) {
            return ApiError::response(400, 'invalid_grant', __('Unknown device code.'));
        }

        $user = $device->user;

        if (! $user instanceof User) {
            return ApiError::response(400, 'invalid_grant', __('Unknown device code.'));
        }

        $token = $user->createToken(CliTokenNames::forLabel($device->label))->plainTextToken;

        return new JsonResponse(['token' => $token]);
    }
}
