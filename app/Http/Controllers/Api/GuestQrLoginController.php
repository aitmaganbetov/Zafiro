<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentQrLogin;
use App\Services\CurrentClub;
use App\Services\QrLogins;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sign-in on a PC from the guest mobile app: the app scans the PC's QR code and confirms
 * with its own token, as the site page does with the session. Only the club of the token.
 */
class GuestQrLoginController extends Controller
{
    public function __construct(private QrLogins $logins, private CurrentClub $currentClub) {}

    public function show(string $code): JsonResponse
    {
        $login = $this->logins->findOpen($code);
        if (($rejected = $this->rejection($login)) !== null) {
            return $rejected;
        }

        return response()->json([
            'computer' => $login->computer?->displayName(),
            'club_name' => $login->club?->name,
        ]);
    }

    public function approve(Request $request, string $code): JsonResponse
    {
        $login = $this->logins->findOpen($code);
        if (($rejected = $this->rejection($login)) !== null) {
            return $rejected;
        }

        $this->logins->approve($login, $request->user());

        return response()->json([
            'message' => __('Вход подтверждён. Через несколько секунд ПК откроется под вашей учётной записью.'),
            'computer' => $login->computer?->displayName(),
        ]);
    }

    private function rejection(?AgentQrLogin $login): ?JsonResponse
    {
        if ($login === null) {
            return response()->json(['message' => __('QR-код устарел. Обновите его на ПК и отсканируйте снова.'), 'code' => 'qr_expired'], 410);
        }
        // The app's token belongs to one club: a code of another club is not confirmed with it.
        if ($login->club_id !== $this->currentClub->id()) {
            return response()->json(['message' => __('QR-код относится к другому клубу.'), 'code' => 'other_club'], 403);
        }

        return null;
    }
}
