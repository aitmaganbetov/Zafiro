{{-- A stylised guest app phone with the balance screen. --}}
<div class="relative rounded-[2.5rem] border-[10px] border-gray-900 bg-white p-4 shadow-2xl">
    <div class="mx-auto mb-4 h-1.5 w-16 rounded-full bg-gray-200"></div>
    <p class="text-xs text-gray-500">{{ __('Клуб') }}</p>
    <p class="font-bold">Cyber Arena</p>
    <div class="mt-4 rounded-2xl bg-gradient-to-br from-[#ff0169] to-[#b0004a] p-4 text-white">
        <p class="text-xs text-white/80">{{ __('Основной баланс') }}</p>
        <p class="text-3xl font-extrabold">4 500 ₸</p>
        <div class="mt-3 flex justify-between text-xs"><span>{{ __('Бонусы') }}: 350</span><span>{{ __('Скидка') }}: 5%</span></div>
    </div>
    <div class="mt-4 flex items-center gap-3 rounded-2xl border border-gray-200 p-3">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#ff0169]/15 text-[#e6005e]"><i class="fas fa-qrcode"></i></span>
        <span class="text-sm font-semibold">{{ __('Войти на ПК по QR-коду') }}</span>
    </div>
    <div class="mt-4 grid grid-cols-5 gap-1 border-t border-gray-100 pt-3 text-center text-[10px] text-gray-400">
        <span class="flex flex-col items-center gap-1 text-[#e6005e]"><i class="fas fa-wallet text-base"></i>{{ __('Баланс') }}</span>
        <span class="flex flex-col items-center gap-1"><i class="fas fa-newspaper text-base"></i>{{ __('Новости') }}</span>
        <span class="flex flex-col items-center gap-1"><i class="fas fa-user-group text-base"></i>{{ __('Друзья') }}</span>
        <span class="flex flex-col items-center gap-1"><i class="fas fa-clock-rotate-left text-base"></i>{{ __('История') }}</span>
        <span class="flex flex-col items-center gap-1"><i class="fas fa-user text-base"></i>{{ __('Профиль') }}</span>
    </div>
</div>
