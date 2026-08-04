<?php

namespace App\Http\Controllers\Cli;

use App\Enums\DeviceCodeStatus;
use App\Http\Controllers\Controller;
use App\Models\DeviceCode;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeviceVerifyController extends Controller
{
    public function show(Request $request): View
    {
        return view('cli.device', [
            'userCode' => (string) $request->query('user_code', ''),
        ]);
    }

    public function store(Request $request): View
    {
        $validated = $request->validate([
            'user_code' => ['required', 'string'],
            'action' => ['required', 'string', 'in:approve,deny'],
        ]);

        $device = DeviceCode::query()
            ->where('user_code', mb_strtoupper(trim($validated['user_code'])))
            ->where('status', DeviceCodeStatus::Pending)
            ->first();

        if ($device === null || $device->isExpired()) {
            return view('cli.device-result', [
                'success' => false,
                'message' => __('That code is invalid, has expired, or was already used.'),
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if ($validated['action'] === 'deny') {
            $device->forceFill(['status' => DeviceCodeStatus::Denied])->save();

            return view('cli.device-result', [
                'success' => false,
                'message' => __('The device authorization was denied. You can close this window.'),
            ]);
        }

        $device->forceFill([
            'user_id' => $user->getKey(),
            'status' => DeviceCodeStatus::Approved,
        ])->save();

        return view('cli.device-result', [
            'success' => true,
            'message' => __('Device authorized. You can return to your terminal.'),
        ]);
    }
}
