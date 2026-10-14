@extends('layouts.superadmin')
@section('page-title', __('Тарифы платформы'))

@section('content')
<p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Тарифы, за которые клубы платят Zafiro. Тариф не удаляется: выключенный тариф нельзя выбрать для новых подписок, у клубов на нём он остаётся.') }}</p>

<div class="space-y-4">
    @foreach($plans as $plan)
        <form method="POST" action="{{ route('superadmin.plans.update', $plan) }}" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">@csrf @method('PATCH')
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $plan->name }}</h3>
                <span class="text-sm text-gray-500">{{ trans_choice(':count клуб|:count клуба|:count клубов', $plan->clubs_count) }}</span>
                @unless($plan->is_active)<span class="rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-700">{{ __('выключен') }}</span>@endunless
            </div>
            @include('superadmin.plans.fields', ['plan' => $plan])
            <button type="submit" class="mt-3 rounded-lg border border-gray-300 px-4 py-2 text-sm">{{ __('Сохранить') }}</button>
        </form>
    @endforeach
</div>

<form method="POST" action="{{ route('superadmin.plans.store') }}" class="mt-6 rounded-xl border border-dashed border-violet-300 bg-white p-4 dark:border-violet-800 dark:bg-gray-900">@csrf
    <h3 class="mb-3 font-semibold text-gray-900 dark:text-white">{{ __('Новый тариф') }}</h3>
    @include('superadmin.plans.fields', ['plan' => null])
    <button type="submit" class="brand-button mt-3 rounded-lg px-4 py-2 text-sm">{{ __('Создать тариф') }}</button>
</form>
@endsection
