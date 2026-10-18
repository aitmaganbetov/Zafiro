@extends('layouts.auth')
@section('title',__('Восстановление пароля'))
@section('content')
<div class="auth-card-head"><h2>{{ __('Новый пароль') }}</h2><p>{{ __('Номер: :phone', ['phone' => $phone]) }}</p></div>
@if(session('status'))<div class="auth-status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="auth-errors">{{ $errors->first() }}</div>@endif
<form action="{{ route('auth.password.update') }}" method="POST" class="auth-form">@csrf
<div class="auth-field"><label for="code">{{ __('Код из Telegram') }}</label><div class="auth-input-wrap"><i class="fab fa-telegram"></i><input type="text" id="code" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="123456"></div></div>
<div class="auth-field"><label for="password">{{ __('Новый пароль') }}</label><div class="auth-input-wrap"><i class="fas fa-lock"></i><input type="password" id="password" name="password" required minlength="6" autocomplete="new-password" placeholder="{{ __('Не менее 6 символов') }}"></div></div>
<div class="auth-field"><label for="password_confirmation">{{ __('Подтверждение пароля') }}</label><div class="auth-input-wrap"><i class="fas fa-shield-halved"></i><input type="password" id="password_confirmation" name="password_confirmation" required minlength="6" autocomplete="new-password"></div></div>
<button class="auth-submit">{{ __('Сменить пароль') }}</button>
</form>
<p class="auth-footer"><a href="{{ route('auth.password.forgot') }}">{{ __('Запросить новый код') }}</a></p>
@endsection
