@extends('layouts.admin')

@section('page-title', __('Чат с клиентами'))

@section('content')
<div class="p-6 lg:p-8">
    <header class="mb-6">
        <h3 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">{{ __('Чат с клиентами') }}</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $canAnswer ? __('Клиенты пишут из агента на ПК. Ответ сразу придёт клиенту на ПК.') : __('Отвечают кассиры и операторы на смене; вам чат доступен для просмотра.') }}</p>
    </header>

    <div class="grid gap-4 lg:grid-cols-[320px_minmax(0,1fr)]">
        <nav class="max-h-[70vh] overflow-y-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900" aria-label="{{ __('Диалоги') }}">
            @forelse($conversations as $conversation)
                <a href="{{ route('chat.index', ['customer' => $conversation->customer_id]) }}" class="flex items-center gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800 {{ $customer?->id === $conversation->customer_id ? 'bg-green-50 dark:bg-green-900/20' : 'hover:bg-gray-50 dark:hover:bg-gray-800' }}">
                    <span class="min-w-0 flex-1">
                        <strong class="block truncate text-sm text-gray-900 dark:text-white">{{ $conversation->customer?->name ?? '—' }}</strong>
                        <small class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $conversation->last?->from_staff ? __('Вы').': ' : '' }}{{ $conversation->last?->body }}</small>
                    </span>
                    @if($conversation->unread > 0)<span class="rounded-full bg-red-600 px-2 py-0.5 text-xs font-bold text-white">{{ $conversation->unread }}</span>@endif
                </a>
            @empty
                <p class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('Сообщений от клиентов пока нет.') }}</p>
            @endforelse
        </nav>

        <section class="flex min-h-[50vh] flex-col rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900" aria-label="{{ __('Переписка') }}">
            @if($customer)
                <header class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><strong class="text-gray-900 dark:text-white">{{ $customer->name }}</strong> <span class="text-sm text-gray-500">{{ $customer->phone }}</span></header>
                <ol class="flex-1 space-y-2 overflow-y-auto p-4" data-chat-thread data-poll-url="{{ route('chat.poll', ['customer' => $customer->id]) }}" data-after="{{ $messages->last()?->id ?? 0 }}">
                    @foreach($messages as $message)
                        @php($item = \App\Http\Controllers\ChatController::present($message))
                        <li class="flex {{ $item['from_staff'] ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[75%] rounded-xl px-3 py-2 text-sm {{ $item['from_staff'] ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-white' }}">
                                <p class="whitespace-pre-line break-words">{{ $item['body'] }}</p>
                                <small class="mt-1 block text-xs opacity-75">{{ $item['at'] }}{{ $item['author'] ? ' · '.$item['author'] : '' }}{{ $item['computer'] ? ' · '.$item['computer'] : '' }}</small>
                            </div>
                        </li>
                    @endforeach
                </ol>
                @if($canAnswer)
                    <form method="POST" action="{{ route('chat.reply', $customer) }}" class="flex gap-2 border-t border-gray-100 p-3 dark:border-gray-800" data-chat-form>
                        @csrf
                        <textarea name="body" rows="2" maxlength="{{ \App\Models\ChatMessage::MAX_LENGTH }}" required placeholder="{{ __('Ответ клиенту…') }}" aria-label="{{ __('Ответ клиенту') }}" class="min-w-0 flex-1 rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></textarea>
                        <button type="submit" class="brand-button rounded-lg px-4 text-sm"><i class="fas fa-paper-plane"></i>{{ __('Отправить') }}</button>
                    </form>
                @endif
            @else
                <p class="m-auto p-10 text-sm text-gray-500 dark:text-gray-400">{{ __('Выберите диалог слева.') }}</p>
            @endif
        </section>
    </div>
</div>
@endsection

@push('scripts')
<script>
// New messages every 5 s; the conversation list reloads when a new unread message arrives.
(() => {
    const thread = document.querySelector('[data-chat-thread]');
    const form = document.querySelector('[data-chat-form]');
    const pollUrl = thread?.dataset.pollUrl ?? @json(route('chat.poll'));
    let after = Number(thread?.dataset.after ?? 0);
    let unread = null;
    const bottom = () => { if (thread) thread.scrollTop = thread.scrollHeight; };
    const append = (message) => {
        if (!thread || message.id <= after) return;
        after = message.id;
        const item = document.createElement('li');
        item.className = `flex ${message.from_staff ? 'justify-end' : 'justify-start'}`;
        const bubble = document.createElement('div');
        bubble.className = `max-w-[75%] rounded-xl px-3 py-2 text-sm ${message.from_staff ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-white'}`;
        const text = document.createElement('p');
        text.className = 'whitespace-pre-line break-words';
        text.textContent = message.body;
        const meta = document.createElement('small');
        meta.className = 'mt-1 block text-xs opacity-75';
        meta.textContent = [message.at, message.author, message.computer].filter(Boolean).join(' · ');
        bubble.append(text, meta);
        item.append(bubble);
        thread.append(item);
        bottom();
    };
    bottom();

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const body = form.elements.body.value.trim();
        if (!body) return;
        const button = form.querySelector('button');
        button.disabled = true;
        try {
            const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': form.elements._token.value }, body: new FormData(form) });
            if (response.ok) {
                append((await response.json()).message);
                form.reset();
            }
        } finally {
            button.disabled = false;
        }
    });
    form?.elements.body.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    setInterval(async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(`${pollUrl}${pollUrl.includes('?') ? '&' : '?'}after=${after}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            data.messages.forEach(append);
            if (unread !== null && data.unread > unread && !form?.elements.body.value) location.reload();
            unread = data.unread;
        } catch {
            // Offline: next tick.
        }
    }, 5000);
})();
</script>
@endpush
