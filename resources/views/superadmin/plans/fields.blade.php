{{-- Поля тарифа платформы: $plan (SaasPlan|null для нового). --}}
@php($input = 'w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100')
<div class="grid grid-cols-1 gap-3 md:grid-cols-4">
    <label class="text-sm"><span class="mb-1 block text-gray-600">{{ __('Название') }}</span><input name="name" required maxlength="120" value="{{ $plan?->name }}" class="{{ $input }}"></label>
    <label class="text-sm"><span class="mb-1 block text-gray-600">{{ __('Код (латиница)') }}</span><input name="slug" required maxlength="80" pattern="[A-Za-z0-9_\-]+" value="{{ $plan?->slug }}" class="{{ $input }} font-mono"></label>
    <label class="text-sm"><span class="mb-1 block text-gray-600">{{ __('Цена в месяц, ₸') }}</span><input type="number" name="price_monthly" min="0" step="0.01" value="{{ $plan?->price_monthly }}" class="{{ $input }}"></label>
    <label class="text-sm"><span class="mb-1 block text-gray-600">{{ __('Пробный период, дней') }}</span><input type="number" name="trial_days" min="0" max="365" required value="{{ $plan?->trial_days ?? 0 }}" class="{{ $input }}"></label>
</div>
<label class="mt-3 block text-sm"><span class="mb-1 block text-gray-600">{{ __('Что входит (по одному пункту в строке)') }}</span><textarea name="features" rows="3" maxlength="2000" class="{{ $input }}">{{ implode("\n", $plan?->features ?? []) }}</textarea></label>
<label class="mt-3 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" name="is_active" value="1" @checked($plan?->is_active ?? true)> {{ __('Доступен для подписки') }}</label>
