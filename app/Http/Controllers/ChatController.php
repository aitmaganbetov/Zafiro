<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Chat with customers in the panel. Cashiers and operators answer; the owner, admin and
 * manager read. A cashier or operator opening a conversation marks the customer's messages read.
 */
class ChatController extends Controller
{
    public function __construct(private CurrentClub $currentClub) {}

    public function index(Request $request): View
    {
        $customerId = $request->integer('customer') ?: null;
        $conversations = $this->conversations();
        $customerId ??= $conversations->first()?->customer_id;
        $customer = $customerId === null ? null : User::query()->find($customerId, ['id', 'name', 'phone']);
        $messages = collect();

        if ($customer !== null) {
            if ($this->answers()) {
                $this->markRead($customer->id);
            }
            $messages = ChatMessage::query()->where('customer_id', $customer->id)
                ->with(['author:id,name', 'computer:id,number,display_name,device_name'])
                ->latest('id')->limit(100)->get()->reverse()->values();
        }

        return view('chat.index', [
            'conversations' => $this->conversations(),
            'customer' => $customer,
            'messages' => $messages,
            'canAnswer' => $this->answers(),
        ]);
    }

    /** New messages of the open conversation and the unread total, for the page to follow. */
    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate(['customer' => ['nullable', 'integer'], 'after' => ['nullable', 'integer']]);
        $messages = collect();
        if (! empty($data['customer'])) {
            if ($this->answers()) {
                $this->markRead((int) $data['customer']);
            }
            $messages = ChatMessage::query()->where('customer_id', (int) $data['customer'])
                ->where('id', '>', (int) ($data['after'] ?? 0))
                ->with(['author:id,name', 'computer:id,number,display_name,device_name'])
                ->orderBy('id')->limit(100)->get()
                ->map(fn (ChatMessage $message): array => self::present($message));
        }

        return response()->json(['messages' => $messages->values(), 'unread' => self::unreadTotal()]);
    }

    public function reply(Request $request, User $customer): RedirectResponse|JsonResponse
    {
        abort_unless($this->answers(), 403, __('Отвечают в чате кассиры и операторы.'));
        abort_unless(ChatMessage::query()->where('customer_id', $customer->id)->exists(), 404);
        $body = trim((string) $request->validate(['body' => ['required', 'string', 'max:'.ChatMessage::MAX_LENGTH]])['body']);

        $message = DB::transaction(function () use ($customer, $body, $request): ChatMessage {
            $this->markRead($customer->id);

            return ChatMessage::query()->create([
                'customer_id' => $customer->id,
                'author_id' => $request->user()->getKey(),
                'from_staff' => true,
                'body' => $body,
            ]);
        });

        return $request->expectsJson()
            ? response()->json(['message' => self::present($message->load('author:id,name'))], 201)
            : redirect()->route('chat.index', ['customer' => $customer->id]);
    }

    public static function unreadTotal(): int
    {
        return ChatMessage::query()->where('from_staff', false)->whereNull('read_at')->count();
    }

    /** @return array<string, mixed> */
    public static function present(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'from_staff' => $message->from_staff,
            'author' => $message->from_staff ? $message->author?->name : null,
            'computer' => $message->computer?->displayName(),
            'body' => $message->body,
            'at' => $message->created_at?->format('d.m H:i'),
        ];
    }

    private function answers(): bool
    {
        return in_array($this->currentClub->membership()?->role, ChatMessage::ANSWERING_ROLES, true);
    }

    private function markRead(int $customerId): void
    {
        ChatMessage::query()->where('customer_id', $customerId)->where('from_staff', false)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** Conversations by the last message, with the unread count (one grouped query). */
    private function conversations()
    {
        return ChatMessage::query()
            ->selectRaw('customer_id, MAX(id) AS last_id, SUM(CASE WHEN from_staff = 0 AND read_at IS NULL THEN 1 ELSE 0 END) AS unread')
            ->groupBy('customer_id')
            ->orderByDesc('last_id')
            ->limit(100)
            ->get()
            ->tap(function ($rows): void {
                $users = User::query()->whereKey($rows->pluck('customer_id'))->get(['id', 'name', 'phone'])->keyBy('id');
                $last = ChatMessage::query()->whereKey($rows->pluck('last_id'))->get(['id', 'body', 'from_staff', 'created_at'])->keyBy('id');
                $rows->each(function ($row) use ($users, $last): void {
                    $row->setAttribute('customer', $users->get($row->customer_id));
                    $row->setAttribute('last', $last->get($row->last_id));
                    $row->setAttribute('unread', (int) $row->unread);
                });
            });
    }
}
