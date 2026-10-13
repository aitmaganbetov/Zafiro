<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ClubMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Club customers and staff with masked phone numbers (members:read is a sensitive scope). */
class MemberController extends ApiV1Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ClubMembership::query()->with(['user:id,name,phone', 'balance:id,user_id,club_id,balance,bonus_balance']);

        return $this->paginate($request, $query, Transformers::member(...));
    }
}
