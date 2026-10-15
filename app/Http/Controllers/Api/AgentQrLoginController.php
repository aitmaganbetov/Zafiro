<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentQrLogin;
use App\Services\GuestFeatures;
use App\Services\QrLogins;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The PC's sign-in screen: a QR code for signing in from the phone (device token). */
class AgentQrLoginController extends Controller
{
    public function __construct(private QrLogins $logins, private GuestFeatures $features) {}

    public function store(Request $request): JsonResponse
    {
        if (! $this->features->enabled('qr_login')) {
            return response()->json(['message' => __('Эта возможность отключена в клубе.'), 'code' => 'qr_disabled'], 403);
        }

        [$login, $code] = $this->logins->create($request->attributes->get('agent_computer'));

        return response()->json([
            'id' => $login->id,
            'url' => route('pc-login.show', $code),
            'expires_at' => $login->expires_at->utc()->toIso8601String(),
            'lifetime_seconds' => AgentQrLogin::LIFETIME_MINUTES * 60,
            'poll_interval_seconds' => 3,
        ], 201);
    }

    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        return response()->json($this->logins->poll($request->attributes->get('agent_computer'), $data['id'], $data['device_name']));
    }
}
