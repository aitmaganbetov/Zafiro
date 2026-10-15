<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ChatController;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ClubComputer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The customer's chat with the club staff, in the PC agent. */
class GuestChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $after = (int) ($request->validate(['after' => ['nullable', 'integer', 'min:0']])['after'] ?? 0);
        $customerId = $request->user()->getKey();
        $messages = ChatMessage::query()->where('customer_id', $customerId)
            ->when($after > 0, fn ($query) => $query->where('id', '>', $after))
            ->with('author:id,name')
            ->latest('id')->limit(100)->get()->reverse()->values();

        // Answers of the staff are read once the customer's agent got them.
        ChatMessage::query()->where('customer_id', $customerId)->where('from_staff', true)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['messages' => $messages->map(fn (ChatMessage $message): array => ChatController::present($message))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.ChatMessage::MAX_LENGTH],
            'computer_id' => ['nullable', 'integer'],
        ]);
        $computerId = empty($data['computer_id']) ? null : ClubComputer::query()->whereKey((int) $data['computer_id'])->value('id');

        $message = ChatMessage::query()->create([
            'customer_id' => $request->user()->getKey(),
            'author_id' => $request->user()->getKey(),
            'from_staff' => false,
            'body' => trim($data['body']),
            'club_computer_id' => $computerId,
        ]);

        return response()->json(['message' => ChatController::present($message)], 201);
    }
}
