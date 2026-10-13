Интервал между бронированиями@extends('layouts.admin')

@section('page-title', 'Настройки')

@section('content')
<div class="p-6 lg:p-8">
    <div class="mb-6 animate-slide">
        <h3 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Настройки клуба</h3>
        <p class="text-gray-600 dark:text-gray-400">Управляйте настройками клуба в одном месте.</p>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-[240px_minmax(0,1fr)] gap-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 h-fit animate-slide">
            <div class="space-y-2">
                <button class="brand-tab w-full text-left px-4 py-3 rounded-lg font-medium">Настройка клуба</button>
                <button class="w-full text-left px-4 py-3 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition">Финансы</button>
                <button class="w-full text-left px-4 py-3 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition">Гости</button>
                <button class="w-full text-left px-4 py-3 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition">API</button>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-6 animate-slide animation-delay-100">
            <div class="space-y-8">
                <div>
                    <h4 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Настройка клуба</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Название клуба</label>
                            <input type="text" value="Zafiro" class="brand-focus w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-4 py-2 text-gray-900 dark:text-gray-100 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Город</label>
                            <input type="text" value="Москва" class="brand-focus w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-4 py-2 text-gray-900 dark:text-gray-100 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Время завершения сессии при бездействии</label>
                            <input type="text" value="15 минут" class="brand-focus w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-4 py-2 text-gray-900 dark:text-gray-100 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Время перезагрузки ПК после завершения сессии</label>
                            <input type="text" value="30 секунд" class="brand-focus w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-4 py-2 text-gray-900 dark:text-gray-100 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Время до автоматического завершения сессии при недоступности ПК</label>
                            <input type="text" value="5 минут" class="brand-focus w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-4 py-2 text-gray-900 dark:text-gray-100 focus:outline-none">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Описание</label>
                            <textarea rows="4" class="brand-focus w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-4 py-2 text-gray-900 dark:text-gray-100 focus:outline-none">Современный клуб с удобным управлением и персонализированными сервисами.</textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-6 animate-slide animation-delay-200">
            <h4 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">API</h4>
            <pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm text-gray-200"><code>{
  "club": {
    "name": "Zafiro",
    "city": "Москва",
    "session_idle_timeout": "15 минут",
    "pc_restart_after_session_end": "30 секунд",
    "session_auto_terminate_when_pc_unavailable": "5 минут"
  }
}</code></pre>
        </div>
    </div>
</div>
@endsection
