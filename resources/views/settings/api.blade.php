@extends('settings.layout')

@section('settings-section', 'api')

@section('settings-content')
<h4 class="mb-4 text-xl font-semibold text-gray-900 dark:text-white">API</h4>
<p class="mb-2 text-sm text-gray-600 dark:text-gray-400">Каждая настройка доступна через отдельный GET API.</p>
<p class="mb-4 text-sm font-medium text-gray-700 dark:text-gray-300">IP: {{ $ip }}</p>

@if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">{{ session('status') }}</div>@endif

<section class="mb-6 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
    <h5 class="mb-2 font-semibold text-gray-900 dark:text-white">Установка ПО</h5>
    <p class="mb-2 text-sm text-gray-600 dark:text-gray-400">Разрешить ключ и получить идентификатор клуба:</p>
    <p class="mb-3 break-all rounded-lg bg-gray-100 px-3 py-2 font-mono text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-300">POST {{ route('api.clubs.resolve') }}</p>
    <pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm text-gray-200"><code>{{ json_encode(['club_key' => 'КЛЮЧ_КЛУБА'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</code></pre>
    <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">Для входа отправляйте тот же <code>club_key</code> вместе с телефоном и паролем на {{ route('api.auth.login') }}.</p>
</section>

<div class="flex flex-col gap-4">
    <div class="brand-note rounded-xl border p-4">
        <h5 class="mb-2 font-semibold text-gray-900 dark:text-white">
            {{ $productEndpoint['name'] }}
        </h5>
        <p class="mb-2 font-mono text-xs text-gray-500 dark:text-gray-400">products.index</p>
        <p class="mb-3 break-all rounded-lg bg-white px-3 py-2 font-mono text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-300">
            GET {{ $productEndpoint['url'] }}
        </p>
        <pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm text-gray-200"><code>{{ json_encode($productEndpoint['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></pre>
    </div>

    @foreach ($endpoints as $endpoint)
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <h5 class="mb-2 font-semibold text-gray-900 dark:text-white">
                {{ $endpoint['name'] }}
            </h5>
            <p class="mb-2 font-mono text-xs text-gray-500 dark:text-gray-400">
                {{ $endpoint['section'] }}.{{ $endpoint['key'] }}
            </p>
            <p class="mb-3 break-all rounded-lg bg-gray-100 px-3 py-2 font-mono text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                GET {{ $endpoint['url'] }}
            </p>
            <pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm text-gray-200"><code>{{ json_encode([
                'ip' => $ip,
                'setting' => $endpoint['section'].'.'.$endpoint['key'],
                'name' => $endpoint['name'],
                'value' => $endpoint['value'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></pre>
        </div>
    @endforeach
</div>
@endsection

@push('scripts')
@endpush
