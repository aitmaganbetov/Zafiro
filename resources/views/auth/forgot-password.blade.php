@extends('layouts.auth')
@section('title',__('Восстановление пароля'))
@section('content')
<div class="auth-card-head"><h2>{{ __('Восстановление пароля') }}</h2><p>{{ __('Пришлём код в Telegram на номер вашего аккаунта.') }}</p></div>
@if($errors->any())<div class="auth-errors">{{ $errors->first() }}</div>@endif
@if($available)
<form action="{{ route('auth.password.send') }}" method="POST" class="auth-form">@csrf
<div class="auth-field"><label for="phone">{{ __('Номер телефона') }}</label><div class="auth-input-wrap"><i class="fas fa-phone"></i><span class="auth-phone-prefix">+7</span><input class="auth-phone-local" type="tel" id="phone" name="phone" value="{{ substr(preg_replace('/\D+/', '', (string) old('phone', '')), -10) }}" required inputmode="numeric" pattern="[0-9]{3} [0-9]{3}-[0-9]{2}-[0-9]{2}" maxlength="13" placeholder="700 123-45-67" autocomplete="tel-national"></div></div>
<button class="auth-submit">{{ __('Получить код в Telegram') }}</button>
</form>
@else
<div class="auth-errors">{{ __('Восстановление пароля через Telegram пока не подключено. Обратитесь к администратору клуба.') }}</div>
@endif
<p class="auth-footer"><a href="{{ route('auth.show-login') }}">{{ __('Вернуться ко входу') }}</a></p>
@endsection
