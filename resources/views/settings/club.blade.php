@extends('settings.layout')

@section('settings-content')
<h4 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Настройки клуба</h4>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    @foreach([
        'session_idle_timeout' => ['Время завершения сессии при бездействии (в минутах)', 15],
        'pc_restart_after_session_end' => ['Время перезагрузки ПК после завершения сессии (в минутах)', 0.5],
        'session_auto_terminate_when_pc_unavailable' => ['Время до автоматического завершения сессии при недоступности ПК (в минутах)', 5],
        'booking_interval' => ['Интервал между бронированиями (в минутах)', 30],
    ] as $field => [$label, $default])
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $label }}</label>
            <input type="number" step="0.01" min="0" max="10080" name="{{ $field }}" value="{{ old($field, $settings['club'][$field] ?? $default) }}" required class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-4 py-2 text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-purple-600">
        </div>
    @endforeach
</div>
@endsection
