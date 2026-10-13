<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $membership = $this->currentMembership;
        $balance = $membership?->balance;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'club_id' => $membership?->club_id,
            'role' => $membership?->role,
            'discount' => (float) ($membership?->discount ?? 0),
            'balance' => (float) ($balance?->balance ?? 0),
            'bonus_balance' => (float) ($balance?->bonus_balance ?? 0),
        ];
    }
}