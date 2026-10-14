<?php

namespace App\Http\Resources;

use App\Models\MemberBalance;
use App\Services\CurrentClub;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $membership = $this->currentMembership()->first();
        $clubId = $membership?->club_id ?? app(CurrentClub::class)->id();
        $balance = $clubId !== null
            ? MemberBalance::query()->withoutGlobalScope('club')
                ->where('user_id', $this->id)
                ->where('club_id', $clubId)
                ->first()
            : null;
        $currency = data_get(app(CurrentClub::class)->settings(), 'finances.currency', 'KZT');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'club_id' => $membership?->club_id,
            'role' => $membership?->role,
            'discount' => (float) ($membership?->discount ?? 0),
            'balance' => (float) ($balance?->balance ?? 0),
            'bonus_balance' => (float) ($balance?->bonus_balance ?? 0),
            'currency' => $currency,
        ];
    }
}
