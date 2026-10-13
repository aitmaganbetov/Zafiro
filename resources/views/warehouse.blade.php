@extends('layouts.admin')

@section('page-title', 'Склад')


@section('content')
<div class="p-6 lg:p-8">
    @if (session('status'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
            <ul class="list-disc space-y-1 ps-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="warehouse-catalog mb-6">
        <div class="warehouse-catalog-inner">
            <div><h3 class="warehouse-catalog-title">Управление каталогом</h3><p class="warehouse-catalog-meta">Категорий: {{ $categories->count() }} · Товаров: {{ $products->count() }}</p></div>
            <div class="warehouse-catalog-actions">
                <button type="button" data-open-modal="category-modal" class="warehouse-action category"><i class="fas fa-folder-plus"></i>Новая категория</button>
                <button type="button" data-open-modal="product-modal" class="warehouse-action product" {{ $categories->isEmpty() ? 'disabled' : '' }}><i class="fas fa-box-open"></i>Новый товар</button>
            </div>
        </div>
        @if($categories->isNotEmpty())<div class="warehouse-category-list">@foreach($categories as $category)<span class="warehouse-category-pill">{{ $category->name }} · {{ $category->products_count }}</span>@endforeach</div>@endif
    </div>

    <div id="category-modal" class="warehouse-modal fixed inset-0 z-50 hidden items-center justify-center bg-gray-950/60 p-4 backdrop-blur-sm">
        <div class="warehouse-dialog category" role="dialog" aria-modal="true" aria-labelledby="category-modal-title">
            <div class="warehouse-dialog-head"><div class="warehouse-dialog-heading"><span class="warehouse-dialog-icon purple"><i class="fas fa-folder-plus"></i></span><div><h3 id="category-modal-title" class="warehouse-dialog-title">Новая категория</h3><p class="warehouse-dialog-subtitle">Создайте группу для товаров склада</p></div></div><button type="button" data-close-modal class="warehouse-modal-close"><i class="fas fa-times"></i></button></div>
            <form action="{{ route('warehouse.categories.store') }}" method="POST" class="warehouse-form">@csrf<input type="hidden" name="form_type" value="category"><div class="warehouse-field"><label>Название категории</label><input type="text" name="name" value="{{ old('form_type') === 'category' ? old('name') : '' }}" maxlength="100" required autofocus placeholder="Например: Напитки"></div><div class="warehouse-form-actions"><button type="button" data-close-modal class="warehouse-button cancel">Отмена</button><button class="warehouse-button save purple">Создать категорию</button></div></form>
        </div>
    </div>

    <div id="product-modal" class="warehouse-modal fixed inset-0 z-50 hidden items-center justify-center bg-gray-950/60 p-4 backdrop-blur-sm">
        <div class="warehouse-dialog product" role="dialog" aria-modal="true" aria-labelledby="product-modal-title">
            <div class="warehouse-dialog-head"><div class="warehouse-dialog-heading"><span class="warehouse-dialog-icon"><i class="fas fa-box-open"></i></span><div><h3 id="product-modal-title" class="warehouse-dialog-title">Новый товар</h3><p class="warehouse-dialog-subtitle">Заполните данные и начальный остаток</p></div></div><button type="button" data-close-modal class="warehouse-modal-close"><i class="fas fa-times"></i></button></div>
            <form action="{{ route('warehouse.products.store') }}" method="POST" class="warehouse-form">@csrf<input type="hidden" name="form_type" value="product"><div class="warehouse-form-grid"><div class="warehouse-field"><label>SKU / артикул</label><input type="text" name="sku" value="{{ old('sku') }}" maxlength="100" required placeholder="Например: DRK-001"></div><div class="warehouse-field"><label>Название товара</label><input type="text" name="name" value="{{ old('form_type') === 'product' ? old('name') : '' }}" maxlength="150" required placeholder="Введите название"></div><div class="warehouse-field"><label>Категория</label><select name="product_category_id" required><option value="">Выберите категорию</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((int)old('product_category_id') === $category->id)>{{ $category->name }}</option>@endforeach</select></div><div class="warehouse-field"><label>Цена, KZT</label><input type="number" name="price" value="{{ old('price') }}" min="0" step="0.01" required placeholder="0.00"></div><div class="warehouse-field"><label>Начальный остаток</label><input type="number" name="quantity" value="{{ old('quantity',0) }}" min="0" required placeholder="0"></div></div><div class="warehouse-form-actions"><button type="button" data-close-modal class="warehouse-button cancel">Отмена</button><button class="warehouse-button save">Создать товар</button></div></form>
        </div>
    </div>

    <div class="mb-6 animate-slide rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
        <h3 class="mb-4 text-xl font-bold text-gray-900 dark:text-white">Движение товара</h3>

        <form action="{{ route('warehouse.stock-movements.store') }}" method="POST" class="grid grid-cols-1 gap-4 lg:grid-cols-4">
            @csrf
            <div>
                <label for="product_id" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Товар</label>
                <select id="product_id" name="product_id" required class="brand-focus w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2 text-gray-900 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected((int) old('product_id') === $product->id)>
                            {{ $product->name }} (остаток: {{ $product->quantity }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="type" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Операция</label>
                <select id="type" name="type" required class="brand-focus w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2 text-gray-900 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    <option value="receipt" @selected(old('type') === 'receipt')>Оприходование</option>
                    <option value="write_off" @selected(old('type') === 'write_off')>Списание</option>
                </select>
            </div>

            <div>
                <label for="quantity" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Количество</label>
                <input id="quantity" type="number" name="quantity" value="{{ old('quantity', 1) }}" min="1" required class="brand-focus w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2 text-gray-900 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
            </div>

            <div>
                <label for="note" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Комментарий</label>
                <input id="note" type="text" name="note" value="{{ old('note') }}" maxlength="255" class="brand-focus w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2 text-gray-900 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
            </div>

            <div class="lg:col-span-4 flex justify-end">
                <button type="submit" class="brand-button rounded-lg px-5 py-2 text-sm transition">Провести операцию</button>
            </div>
        </form>
    </div>

    <div class="animate-slide rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
        <h3 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">Склад</h3>
        <p class="mb-6 text-gray-600 dark:text-gray-400">Товаров на складе: {{ $products->count() }}</p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                        <th class="px-3 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Изображение</th>
                        <th class="px-3 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">SKU</th>
                        <th class="px-3 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Товар</th>
                        <th class="px-3 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Категория</th>
                        <th class="px-3 py-3 text-right font-semibold text-gray-700 dark:text-gray-300">Цена</th>
                        <th class="px-3 py-3 text-right font-semibold text-gray-700 dark:text-gray-300">Остаток</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($products as $product)
                        <tr class="border-b border-gray-100 transition last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50">
                            <td class="px-3 py-3">
                                <img
                                    src="{{ asset('images/product-placeholder.svg') }}"
                                    alt="{{ $product->name }}"
                                    width="56"
                                    height="56"
                                    class="h-14 w-14 rounded-lg object-cover"
                                >
                            </td>
                            <td class="px-3 py-4 font-mono text-gray-500 dark:text-gray-400">{{ $product->sku }}</td>
                            <td class="px-3 py-4 font-medium text-gray-900 dark:text-gray-100">{{ $product->name }}</td>
                            <td class="px-3 py-4 text-gray-600 dark:text-gray-400">{{ $product->category }}</td>
                            <td class="px-3 py-4 text-right font-semibold text-gray-900 dark:text-gray-100">{{ number_format((float) $product->price, 0, ',', ' ') }} KZT</td>
                            <td class="px-3 py-4 text-right font-semibold {{ $product->quantity <= 10 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">{{ $product->quantity }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6 animate-slide rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
        <h3 class="mb-4 text-xl font-bold text-gray-900 dark:text-white">Последние операции</h3>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                        <th class="px-3 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Дата</th>
                        <th class="px-3 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Товар</th>
                        <th class="px-3 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Операция</th>
                        <th class="px-3 py-3 text-right font-semibold text-gray-700 dark:text-gray-300">Количество</th>
                        <th class="px-3 py-3 text-right font-semibold text-gray-700 dark:text-gray-300">Остаток</th>
                        <th class="px-3 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Комментарий</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($movements as $movement)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="px-3 py-4 text-gray-500 dark:text-gray-400">{{ $movement->created_at->format('d.m.Y H:i') }}</td>
                            <td class="px-3 py-4 font-medium text-gray-900 dark:text-gray-100">{{ $movement->product->name }}</td>
                            @php($isSale = $movement->type === 'write_off' && $movement->note === 'Продажа POS')
                            <td class="px-3 py-4 font-semibold {{ $movement->type === 'receipt' ? 'text-green-600 dark:text-green-400' : ($isSale ? 'text-blue-600 dark:text-blue-400' : 'text-red-600 dark:text-red-400') }}">
                                {{ $movement->type === 'receipt' ? 'Оприходование' : ($isSale ? 'Продажа' : 'Списание') }}
                            </td>
                            <td class="px-3 py-4 text-right font-semibold text-gray-900 dark:text-gray-100">{{ $movement->quantity }}</td>
                            <td class="px-3 py-4 text-right text-gray-700 dark:text-gray-300">{{ $movement->balance_after }}</td>
                            <td class="px-3 py-4 text-gray-500 dark:text-gray-400">{{ $movement->note ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">Операций пока нет.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const modals = document.querySelectorAll('.warehouse-modal');
    const openModal = id => {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
        setTimeout(() => modal.querySelector('input:not([type="hidden"]), select')?.focus(), 50);
    };
    const closeModal = modal => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = '';
    };
    document.querySelectorAll('[data-open-modal]').forEach(button => button.addEventListener('click', () => openModal(button.dataset.openModal)));
    document.querySelectorAll('[data-close-modal]').forEach(button => button.addEventListener('click', () => closeModal(button.closest('.warehouse-modal'))));
    modals.forEach(modal => modal.addEventListener('click', event => { if (event.target === modal) closeModal(modal); }));
    document.addEventListener('keydown', event => { if (event.key === 'Escape') modals.forEach(closeModal); });
    @if($errors->any() && in_array(old('form_type'), ['category', 'product'], true))
        openModal(@json(old('form_type').'-modal'));
    @endif
})();
</script>
@endpush
