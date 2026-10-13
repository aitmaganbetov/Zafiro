@extends('layouts.admin')
@section('page-title', 'Касса POS')


@section('content')
<div class="p-4 lg:p-6">
    @if(session('status'))<div class="mb-4 rounded-lg bg-green-50 p-4 text-green-700">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="mb-4 rounded-lg bg-red-50 p-4 text-red-700">{{ $errors->first() }}</div>@endif

    @if(!$shift)
        <div class="pos-open">
            <h3>Открыть смену</h3>
            <form method="POST" action="{{ route('cash-register.shifts.open') }}">
                @csrf
                <div><label>Начальный остаток</label><input type="number" step="0.01" min="0" name="opening_balance" required></div>
                <button>Открыть кассу</button>
            </form>
        </div>
    @else
        @php($categories = $products->pluck('category')->filter()->unique()->values())
        <div class="pos-page">
            <div class="pos-top">
                <div class="pos-status">Смена #{{ $shift->id }} · В кассе: <strong>{{ number_format($totals['balance'], 2, ',', ' ') }} KZT</strong></div>
                <div class="pos-actions"><a href="{{ route('cash-register.x-report') }}">X-отчёт</a><a class="danger" href="{{ route('cash-register.z-report', $shift) }}">Закрыть смену · Z-отчёт</a></div>
            </div>
            <div class="pos-layout">
                <main class="pos-main">
                    <div class="pos-categories">
                        <button class="pos-category active" data-category="all"><span class="pos-category-icon">⌘</span><strong>Все</strong><small>{{ $products->count() }} товаров</small></button>
                        @foreach($categories->take(5) as $category)
                            <button class="pos-category" data-category="{{ $category }}"><span class="pos-category-icon">♨</span><strong>{{ $category }}</strong><small>{{ $products->where('category', $category)->count() }} товаров</small></button>
                        @endforeach
                    </div>
                    <div class="pos-products">
                        @foreach($products as $product)
                            <article class="pos-product" data-product-id="{{ $product->id }}" data-category="{{ $product->category }}">
                                <img src="{{ asset('images/product-placeholder.svg') }}" alt="{{ $product->name }}">
                                <h4>{{ $product->name }}</h4>
                                <p class="pos-product-meta">{{ $product->category ?: 'Без категории' }}</p>
                                <div class="pos-product-price"><span>{{ number_format((float)$product->price, 0, ',', ' ') }} ₸</span><span class="pos-stock {{ $product->quantity < 5 ? 'low' : '' }}">{{ $product->quantity > 0 ? 'В наличии: '.$product->quantity.' шт.' : 'Нет в наличии · 0 шт.' }}</span></div>
                                <button type="button" class="pos-add add-product" data-id="{{ $product->id }}" data-name="{{ $product->name }}" data-price="{{ (float)$product->price }}" data-stock="{{ $product->quantity }}" {{ $product->quantity < 1 ? 'disabled' : '' }}>Добавить в заказ</button>
                            </article>
                        @endforeach
                    </div>
                </main>
                <aside class="pos-order">
                    @php($showBalanceTopUp = session('cash_view') === 'balance' || old('cash_view') === 'balance')
                    <div class="pos-view-tabs" role="tablist" aria-label="Раздел кассы">
                        <button type="button" class="pos-view-tab {{ $showBalanceTopUp ? '' : 'active' }}" data-pos-view="sale" role="tab" aria-selected="{{ $showBalanceTopUp ? 'false' : 'true' }}">Продажа товаров</button>
                        <button type="button" class="pos-view-tab {{ $showBalanceTopUp ? 'active' : '' }}" data-pos-view="balance" role="tab" aria-selected="{{ $showBalanceTopUp ? 'true' : 'false' }}">Пополнение баланса</button>
                    </div>
                    <div id="sale-view" @if($showBalanceTopUp) hidden @endif>
                    <div class="pos-order-head"><h3>Новый заказ</h3><div class="pos-modes"><button type="button" class="pos-mode active">В зале</button><button type="button" class="pos-mode">С собой</button><button type="button" class="pos-mode">Доставка</button></div></div>
                    <div id="cart" class="pos-cart"><div class="pos-empty">Добавьте товары в заказ</div></div>
                    <div class="pos-summary">
                        <div class="pos-summary-row"><span>Подытог</span><span id="subtotal">0 ₸</span></div>
                        <div class="pos-summary-row"><span>НДС 5% (включён)</span><span id="tax">0 ₸</span></div>
                        <div class="pos-summary-total"><span>Итого</span><span id="total">0 ₸</span></div>
                    </div>
                    <div class="pos-payments"><button type="button" class="pos-payment active"><span>▣</span>Наличные</button><button type="button" class="pos-payment"><span>▤</span>Карта</button><button type="button" class="pos-payment"><span>▦</span>QR-код</button></div>
                    <form id="sale-form" method="POST" action="{{ route('cash-register.sales.store') }}">@csrf<div id="sale-items"></div><button id="checkout" disabled class="pos-checkout">Оформить заказ · Оплатить</button></form>
                    </div>
                    <section id="balance-view" class="pos-balance-view" @if(!$showBalanceTopUp) hidden @endif>
                        <div class="pos-order-head"><h3>Пополнение баланса</h3></div>
                        @php($selectedTopUpUser = $users->firstWhere('id', (int) old('user_id')))
                        <form method="POST" action="{{ route('cash-register.balance-top-ups.store') }}" class="pos-balance-form">
                            @csrf
                            <input type="hidden" name="cash_view" value="balance">
                            <div class="pos-user-picker" data-user-picker>
                                <label for="top-up-user-search">Пользователь</label>
                                <input id="top-up-user-id" type="hidden" name="user_id" value="{{ old('user_id') }}" data-user-id>
                                <input id="top-up-user-search" type="search" role="combobox" aria-autocomplete="list" aria-controls="top-up-user-options" aria-expanded="false" autocomplete="off" required value="{{ $selectedTopUpUser ? $selectedTopUpUser->name.' · '.$selectedTopUpUser->phone : '' }}" placeholder="Поиск по имени или телефону" class="pos-balance-input" data-user-search>
                                <div id="top-up-user-options" class="pos-user-options" role="listbox" hidden data-user-options>
                                    @foreach($users as $user)
                                        <button type="button" class="pos-user-option" role="option" aria-selected="{{ (string) old('user_id') === (string) $user->id ? 'true' : 'false' }}" data-user-option data-user-id="{{ $user->id }}" data-label="{{ $user->name }} · {{ $user->phone }}" data-search="{{ mb_strtolower($user->name.' '.$user->phone) }}">
                                            <span><strong>{{ $user->name }}</strong><small>{{ $user->phone }} · Основной {{ number_format((float) $user->currentMembership?->balance?->balance, 0, ',', ' ') }} ₸ · Бонусный {{ number_format((float) $user->currentMembership?->balance?->bonus_balance, 0, ',', ' ') }} ₸</small></span>
                                        </button>
                                    @endforeach
                                    <p class="pos-user-no-results" data-user-no-results hidden>Пользователь не найден</p>
                                </div>
                            </div>
                            <label>Сумма, ₸<input name="amount" type="number" min="1" max="9999999999.99" step="0.01" value="{{ old('amount') }}" required class="pos-balance-input" placeholder="Введите сумму"></label>
                            <label>Зачислить на
                                <select name="balance_type" required class="pos-balance-input">
                                    <option value="balance" @selected(old('balance_type', 'balance') === 'balance')>Основной баланс</option>
                                    <option value="bonus_balance" @selected(old('balance_type') === 'bonus_balance')>Бонусный баланс</option>
                                </select>
                            </label>
                            <label>Способ оплаты
                                <select name="payment_method" required class="pos-balance-input">
                                    <option value="cash" @selected(old('payment_method', 'cash') === 'cash')>Наличные</option>
                                    <option value="card" @selected(old('payment_method') === 'card')>Карта</option>
                                    <option value="qr" @selected(old('payment_method') === 'qr')>QR-код</option>
                                </select>
                            </label>
                            <button type="submit" class="pos-checkout">Пополнить баланс</button>
                        </form>
                    </section>
                </aside>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
(() => {
    const cart = new Map();
    const box = document.getElementById('cart');
    if (!box) return;
    const saleView = document.getElementById('sale-view');
    const balanceView = document.getElementById('balance-view');
    const userPicker = document.querySelector('[data-user-picker]');
    if (userPicker) {
        const search = userPicker.querySelector('[data-user-search]');
        const userId = userPicker.querySelector('[data-user-id]');
        const optionsList = userPicker.querySelector('[data-user-options]');
        const options = Array.from(userPicker.querySelectorAll('[data-user-option]'));
        const noResults = userPicker.querySelector('[data-user-no-results]');
        const filterUsers = () => {
            const query = search.value.trim().toLocaleLowerCase('ru');
            let visibleCount = 0;
            options.forEach(option => {
                const visible = option.dataset.search.includes(query);
                option.hidden = !visible;
                if (visible) visibleCount++;
            });
            noResults.hidden = visibleCount > 0;
            optionsList.hidden = false;
            search.setAttribute('aria-expanded', 'true');
        };
        search.addEventListener('focus', filterUsers);
        search.addEventListener('input', () => {
            userId.value = '';
            search.setCustomValidity('');
            filterUsers();
        });
        optionsList.addEventListener('click', event => {
            const option = event.target.closest('[data-user-option]');
            if (!option) return;
            userId.value = option.dataset.userId;
            search.value = option.dataset.label;
            search.setCustomValidity('');
            options.forEach(item => item.setAttribute('aria-selected', String(item === option)));
            optionsList.hidden = true;
            search.setAttribute('aria-expanded', 'false');
        });
        document.addEventListener('click', event => {
            if (!userPicker.contains(event.target)) {
                optionsList.hidden = true;
                search.setAttribute('aria-expanded', 'false');
            }
        });
        userPicker.closest('form').addEventListener('submit', event => {
            if (!userId.value) {
                event.preventDefault();
                search.setCustomValidity('Выберите пользователя из списка.');
                search.reportValidity();
            }
        });
        if (userId.value) {
            const selectedOption = options.find(option => option.dataset.userId === userId.value);
            if (selectedOption) selectedOption.setAttribute('aria-selected', 'true');
        }
    }
    document.querySelectorAll('[data-pos-view]').forEach(button => button.addEventListener('click', () => {
        const showBalance = button.dataset.posView === 'balance';
        document.querySelectorAll('[data-pos-view]').forEach(tab => {
            const active = tab === button;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', String(active));
        });
        saleView.hidden = showBalance;
        balanceView.hidden = !showBalance;
    }));
    const placeholder = @json(asset('images/product-placeholder.svg'));
    const money = value => new Intl.NumberFormat('ru-RU').format(value) + ' ₸';
    const escapeHtml = value => String(value).replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));

    function render() {
        box.innerHTML = '';
        let total = 0;
        cart.forEach((item, id) => {
            total += item.price * item.qty;
            box.insertAdjacentHTML('beforeend', `<div class="pos-cart-item"><img src="${placeholder}" alt=""><div><div class="pos-cart-name">${escapeHtml(item.name)}</div><div class="pos-cart-sub">Без модификаторов</div><div class="pos-cart-line"><span class="pos-cart-price">${money(item.price * item.qty)}</span><div class="pos-qty"><button type="button" data-change="-1" data-id="${id}">−</button><span>${item.qty}</span><button type="button" data-change="1" data-id="${id}">+</button></div></div></div></div>`);
        });
        if (!cart.size) box.innerHTML = '<div class="pos-empty">Добавьте товары в заказ</div>';
        document.getElementById('subtotal').textContent = money(total);
        document.getElementById('tax').textContent = money(Math.round(total * 5 / 105));
        document.getElementById('total').textContent = money(total);
        document.getElementById('checkout').disabled = !cart.size;
        const inputs = document.getElementById('sale-items'); inputs.innerHTML = '';
        let index = 0;
        cart.forEach((item, id) => { inputs.insertAdjacentHTML('beforeend', `<input type="hidden" name="items[${index}][product_id]" value="${id}"><input type="hidden" name="items[${index}][quantity]" value="${item.qty}">`); index++; });
        document.querySelectorAll('.pos-product').forEach(card => card.classList.toggle('selected', cart.has(Number(card.dataset.productId))));
    }

    box.addEventListener('click', event => { const button = event.target.closest('[data-change]'); if (!button) return; const id = Number(button.dataset.id); const item = cart.get(id); if (!item) return; item.qty += Number(button.dataset.change); if (item.qty < 1) cart.delete(id); else item.qty = Math.min(item.qty, item.stock); render(); });
    document.querySelectorAll('.add-product').forEach(button => button.addEventListener('click', () => { const id = Number(button.dataset.id); const item = cart.get(id) || {name: button.dataset.name, price: Number(button.dataset.price), stock: Number(button.dataset.stock), qty: 0}; item.qty = Math.min(item.qty + 1, item.stock); cart.set(id, item); render(); }));
    document.querySelectorAll('.pos-category').forEach(button => button.addEventListener('click', () => { document.querySelectorAll('.pos-category').forEach(item => item.classList.remove('active')); button.classList.add('active'); document.querySelectorAll('.pos-product').forEach(card => card.style.display = button.dataset.category === 'all' || card.dataset.category === button.dataset.category ? '' : 'none'); }));
    document.querySelectorAll('.pos-mode').forEach(button => button.addEventListener('click', () => { document.querySelectorAll('.pos-mode').forEach(item => item.classList.remove('active')); button.classList.add('active'); }));
    document.querySelectorAll('.pos-payment').forEach(button => button.addEventListener('click', () => { document.querySelectorAll('.pos-payment').forEach(item => item.classList.remove('active')); button.classList.add('active'); }));
})();
</script>
@endpush
