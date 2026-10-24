@extends('layouts.admin')

@section('page-title', __('Пользователи'))

@section('content')
<div class="p-6 lg:p-8">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h3 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('Пользователи') }}</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Всего: :count', ['count' => $users->total()]) }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            @if(in_array($currentMembership->role, ['owner', 'admin'], true))
                <a href="{{ route('workers.index') }}" class="min-h-10 inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-200"><i class="fas fa-user-tie"></i>{{ __('Работники') }}</a>
            @endif
            <button id="open-user-dialog" type="button" class="brand-button min-h-10 px-4"><i class="fas fa-user-plus"></i>{{ __('Добавить клиента') }}</button>
        </div>
    </div>

    @if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">{{ session('status') }}</div>@endif

    <dialog id="user-create-dialog" class="user-create-dialog" @if($errors->any()) data-open-on-load @endif>
        <div class="user-create-dialog-shell">
            <header class="user-create-dialog-head">
                <h4 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Новый клиент') }}</h4>
                <button type="button" data-close-user-dialog class="grid h-9 w-9 place-items-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" aria-label="{{ __('Закрыть') }}"><i class="fas fa-times"></i></button>
            </header>
            <div class="user-create-dialog-body">
                @if($errors->any())<div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">{{ $errors->first() }}</div>@endif
                <form action="{{ route('users.store') }}" method="POST" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @csrf
                    <div><label for="new-user-name" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Имя') }}</label><input id="new-user-name" name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                    <div><label for="new-user-phone" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Телефон') }}</label><div class="flex"><span class="inline-flex items-center rounded-l-lg border border-r-0 border-gray-300 bg-gray-100 px-3 text-sm font-medium text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">+7</span><input id="new-user-phone" name="phone" value="{{ substr(preg_replace('/\D+/', '', (string) old('phone', '')), -10) }}" required type="tel" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" placeholder="7001234567" autocomplete="tel-national" class="w-full min-w-0 rounded-r-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div></div>
                    <div><label for="new-user-email" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Email <span class="font-normal text-gray-500">{{ __('необязательно') }}</span></label><input id="new-user-email" name="email" value="{{ old('email') }}" type="email" maxlength="255" autocomplete="email" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                    <div><label for="new-user-password" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Пароль') }}</label><input id="new-user-password" name="password" type="password" required minlength="6" autocomplete="new-password" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                    <div><label for="new-user-password-confirmation" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Повторите пароль') }}</label><input id="new-user-password-confirmation" name="password_confirmation" type="password" required minlength="6" autocomplete="new-password" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                    {{-- Discount and opening balance: managers only; the front desk tops up at the cash register. --}}
                    @if($canSetMoney)
                    <div><label for="new-user-discount" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Скидка, %') }}</label><input id="new-user-discount" name="discount" type="number" value="{{ old('discount', 0) }}" min="0" max="100" step="0.01" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                    <div><label for="new-user-balance" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Баланс') }}</label><input id="new-user-balance" name="balance" type="number" value="{{ old('balance', 0) }}" min="0" step="0.01" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                    <div><label for="new-user-bonus-balance" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Бонусный баланс') }}</label><input id="new-user-bonus-balance" name="bonus_balance" type="number" value="{{ old('bonus_balance', 0) }}" min="0" step="0.01" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                    @endif
                    <div class="flex items-end justify-end gap-3 md:col-span-2"><button type="button" data-close-user-dialog class="min-h-10 rounded-lg border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">{{ __('Отмена') }}</button><button type="submit" class="brand-button min-h-10 px-5"><i class="fas fa-user-plus"></i>{{ __('Создать') }}</button></div>
                </form>
            </div>
        </div>
    </dialog>

    <dialog id="user-edit-dialog" class="user-create-dialog">
        <div class="user-create-dialog-shell">
            <header class="user-create-dialog-head">
                <h4 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Изменить клиента') }}</h4>
                <button type="button" data-close-edit-dialog class="grid h-9 w-9 place-items-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" aria-label="{{ __('Закрыть') }}"><i class="fas fa-times"></i></button>
            </header>
            <div class="user-create-dialog-body">
                <form method="POST" action="" class="grid grid-cols-1 gap-4 md:grid-cols-2" data-edit-form>
                    @csrf @method('PATCH')
                    <div><label for="edit-user-name" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Имя') }}</label><input id="edit-user-name" name="name" required maxlength="255" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                    <div><label for="edit-user-email" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Email <span class="font-normal text-gray-500">{{ __('необязательно') }}</span></label><input id="edit-user-email" name="email" type="email" maxlength="255" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 md:col-span-2" data-account-locked-note hidden>{{ __('Это сотрудник другого клуба: имя, email и пароль он меняет сам. Здесь меняются только скидка и баланс.') }}</p>
                    @if($canSetMoney)<div><label for="edit-user-discount" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Скидка, %') }}</label><input id="edit-user-discount" name="discount" type="number" min="0" max="100" step="0.01" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>@endif
                    @if($canTopUp)
                    {{-- The owner only: a top-up or a write-off without the cash register (no cash transaction; logged). --}}
                    <div class="md:col-span-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">{{ __('Пополнение и списание без кассы: деньги не проходят через смену и отчёт кассы, операция записывается в журнал от вашего имени.') }}</div>
                    <div class="md:col-span-2"><label for="edit-user-balance-action" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Баланс без кассы') }}</label><select id="edit-user-balance-action" name="balance_action" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"><option value="top_up">{{ __('Пополнить') }}</option><option value="write_off">{{ __('Списать') }}</option></select></div>
                    <div><label for="edit-user-top-up" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Сумма') }} <span class="font-normal text-gray-500">{{ __('необязательно') }}</span></label><input id="edit-user-top-up" name="top_up_amount" type="number" min="0.01" max="10000000" step="0.01" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                    <div><label for="edit-user-top-up-balance" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Какой баланс') }}</label><select id="edit-user-top-up-balance" name="top_up_balance" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"><option value="balance">{{ __('Основной баланс') }}</option><option value="bonus_balance">{{ __('Бонусный баланс') }}</option></select></div>
                    @endif
                    <div class="flex items-end justify-end gap-3 md:col-span-2"><button type="button" data-close-edit-dialog class="min-h-10 rounded-lg border border-gray-300 px-4 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-300">{{ __('Отмена') }}</button><button type="submit" class="brand-button min-h-10 px-5">{{ __('Сохранить') }}</button></div>
                </form>
            </div>
        </div>
    </dialog>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-5 py-3 font-semibold">{{ __('Логин') }}</th>
                        <th scope="col" class="px-5 py-3 font-semibold">{{ __('Номер') }}</th>
                        <th scope="col" class="px-5 py-3 text-right font-semibold">{{ __('Скидка') }}</th>
                        <th scope="col" class="px-5 py-3 text-right font-semibold">{{ __('Потрачено') }}</th>
                        <th scope="col" class="px-5 py-3 text-right font-semibold">{{ __('Баланс') }}</th>
                        <th scope="col" class="px-5 py-3 text-right font-semibold">{{ __('Бонусный баланс') }}</th>
                        <th scope="col" class="px-5 py-3"><span class="sr-only">{{ __('Действия') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($users as $membership)
                        <tr class="text-gray-700 dark:text-gray-300">
                            <td class="px-5 py-4 font-medium text-gray-900 dark:text-white">{{ $membership->user->name }}</td>
                            <td class="px-5 py-4 whitespace-nowrap">{{ $membership->user->phone }}</td>
                            @php($loyaltyPercent = $loyalty->percentFor($membership))
                            <td class="px-5 py-4 text-right tabular-nums">
                                {{ number_format(max((float) $membership->discount, $loyaltyPercent), 2, '.', ' ') }}%
                                @if ($loyaltyPercent > (float) $membership->discount)<span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('накопительная') }}</span>@endif
                            </td>
                            <td class="px-5 py-4 text-right tabular-nums">{{ number_format((float) $membership->total_spent, 2, '.', ' ') }}</td>
                            <td class="px-5 py-4 text-right tabular-nums">{{ number_format((float) $membership->balance?->balance, 2, '.', ' ') }}</td>
                            <td class="px-5 py-4 text-right tabular-nums">{{ number_format((float) $membership->balance?->bonus_balance, 2, '.', ' ') }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-right">
                                <button type="button" class="text-sm font-medium text-green-700 hover:underline dark:text-green-400" data-edit-user data-action="{{ route('users.update', $membership) }}" data-name="{{ $membership->user->name }}" data-email="{{ $membership->user->email }}" data-discount="{{ $membership->discount }}" @if($staffElsewhere->has($membership->user_id)) data-account-locked @endif>{{ __('Изменить') }}</button>
                                @unless($staffElsewhere->has($membership->user_id))
                                <form method="POST" action="{{ route('users.password', $membership) }}" class="ml-3 inline" onsubmit="return confirm(@js(__('Сбросить пароль? Новый пароль покажется на экране один раз.')))">@csrf
                                    <button type="submit" class="text-sm font-medium text-gray-600 hover:underline dark:text-gray-300">{{ __('Сбросить пароль') }}</button>
                                </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center text-gray-500 dark:text-gray-400">{{ __('Пользователей пока нет') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                {{ $users->links() }}
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
(()=>{const dialog=document.getElementById('user-create-dialog');document.getElementById('open-user-dialog').addEventListener('click',()=>dialog.showModal());document.querySelectorAll('[data-close-user-dialog]').forEach(button=>button.addEventListener('click',()=>dialog.close()));dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close()});if(dialog.hasAttribute('data-open-on-load'))dialog.showModal();const edit=document.getElementById('user-edit-dialog'),editForm=edit.querySelector('[data-edit-form]');document.querySelectorAll('[data-edit-user]').forEach(button=>button.addEventListener('click',()=>{editForm.action=button.dataset.action;editForm.elements.name.value=button.dataset.name;editForm.elements.email.value=button.dataset.email;const locked=button.hasAttribute('data-account-locked');editForm.elements.name.readOnly=locked;editForm.elements.email.readOnly=locked;editForm.querySelector('[data-account-locked-note]').hidden=!locked;if(editForm.elements.discount)editForm.elements.discount.value=button.dataset.discount;if(editForm.elements.top_up_amount){editForm.elements.top_up_amount.value='';editForm.elements.balance_action.value='top_up'}edit.showModal()}));edit.querySelectorAll('[data-close-edit-dialog]').forEach(button=>button.addEventListener('click',()=>edit.close()));edit.addEventListener('click',event=>{if(event.target===edit)edit.close()})})();
</script>
@endpush