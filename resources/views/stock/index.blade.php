@extends('layouts.admin')

@section('page-title', __('Склад'))

@section('content')
<div class="p-6 lg:p-8">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h3 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">{{ __('Склад') }}</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Остатки товаров клуба. Привезли товар — оприходуйте его здесь; списание делает менеджер, продажа — через кассу.') }}</p>
        </div>
        <form method="GET" action="{{ route('stock.index') }}" class="flex gap-2">
            <input name="search" value="{{ $search }}" maxlength="100" placeholder="{{ __('Название или артикул') }}" aria-label="{{ __('Поиск товара') }}" class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
            <button type="submit" class="brand-button rounded-lg px-4 py-2 text-sm"><i class="fas fa-search"></i>{{ __('Найти') }}</button>
        </form>
    </header>

    @if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300" role="alert">{{ $errors->first() }}</div>@endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <table class="w-full min-w-[900px] text-left text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                <tr><th class="px-5 py-3 font-semibold">{{ __('Товар') }}</th><th class="px-5 py-3 font-semibold">{{ __('Категория') }}</th><th class="px-5 py-3 text-right font-semibold">{{ __('Цена') }}</th><th class="px-5 py-3 text-right font-semibold">{{ __('Остаток') }}</th><th class="px-5 py-3 font-semibold">{{ __('Оприходовать') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($products as $product)
                    <tr class="text-gray-700 dark:text-gray-300">
                        <td class="px-5 py-3"><span class="flex items-center gap-3"><img src="{{ $images[$product->id] }}" alt="" class="h-10 w-10 rounded-lg object-cover"><span><strong class="block text-gray-900 dark:text-white">{{ $product->name }}</strong><small class="text-gray-500">{{ $product->sku }}</small></span></span></td>
                        <td class="px-5 py-3">{{ $product->category }}</td>
                        <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums">{{ number_format((float) $product->price, 0, ',', ' ') }} ₸</td>
                        <td class="px-5 py-3 text-right font-semibold tabular-nums {{ $product->quantity <= 0 ? 'text-red-600' : ($product->quantity < 5 ? 'text-amber-600' : 'text-gray-900 dark:text-white') }}">{{ $product->quantity }}</td>
                        <td class="px-5 py-3">
                            @if($currentMembership->hasPermission('stock.create'))
                            <form method="POST" action="{{ route('stock.receive') }}" class="flex items-center gap-2" onsubmit="return confirm(@js(__('Оприходовать :product?', ['product' => $product->name])) + ' +' + this.elements.quantity.value)">
                                @csrf
                                <input type="hidden" name="product_id" value="{{ $product->id }}">
                                <input type="number" name="quantity" min="1" max="100000" step="1" required placeholder="{{ __('шт.') }}" aria-label="{{ __('Сколько пришло: :product', ['product' => $product->name]) }}" class="w-20 rounded-lg border border-gray-300 bg-gray-50 px-2 py-1.5 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                                <input name="note" maxlength="255" placeholder="{{ __('Комментарий') }}" aria-label="{{ __('Комментарий') }}" class="w-36 rounded-lg border border-gray-300 bg-gray-50 px-2 py-1.5 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                                <button type="submit" class="brand-button rounded-lg px-3 py-1.5 text-xs"><i class="fas fa-plus"></i>{{ __('Оприходовать') }}</button>
                            </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-gray-500 dark:text-gray-400">{{ __('Товары не найдены.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $products->links() }}</div>
</div>
@endsection
