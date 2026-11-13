@extends('layouts.auth')
@section('title', __('Регистрация реселлера'))
@section('content')
<div class="auth-card-head"><h2>{{ __('Стать реселлером Zafiro') }}</h2><p>{{ __('Выберите свой код: клубы вводят его в настройках, получают скидку на подписку, а вы — процент с их оплат.') }}</p></div>
@if($errors->any())<div class="auth-errors">{{ $errors->first() }}</div>@endif
<form action="{{ route('reseller.register.store') }}" method="POST" class="auth-form">@csrf
<div class="auth-field"><label for="name">{{ __('Имя или компания') }}</label><div class="auth-input-wrap"><i class="fas fa-user"></i><input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="120"></div>@error('name')<p class="auth-error-text">{{ $message }}</p>@enderror</div>
<div class="auth-field"><label for="code">{{ __('Код реселлера') }}</label><div class="auth-input-wrap"><i class="fas fa-hashtag"></i><input type="text" id="code" name="code" value="{{ old('code') }}" required maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9\-]{2,31}" autocapitalize="none" spellcheck="false" placeholder="arman-games"></div><p class="mt-1 text-xs text-gray-500">{{ __('Латинские буквы, цифры и дефис, от 3 до 32 символов.') }}</p>@error('code')<p class="auth-error-text">{{ $message }}</p>@enderror</div>
<div class="auth-field"><label for="phone">{{ __('Номер телефона') }}</label><div class="auth-input-wrap"><i class="fas fa-phone"></i><span class="auth-phone-prefix">+7</span><input class="auth-phone-local" type="tel" id="phone" name="phone" value="{{ substr(preg_replace('/\D+/', '', (string) old('phone', '')), -10) }}" required inputmode="numeric" pattern="[0-9]{3} [0-9]{3}-[0-9]{2}-[0-9]{2}" maxlength="13" placeholder="700 123-45-67" autocomplete="tel-national"></div>@error('phone')<p class="auth-error-text">{{ $message }}</p>@enderror</div>
<div class="auth-field"><label for="password">{{ __('Пароль') }}</label><div class="auth-input-wrap"><i class="fas fa-lock"></i><input type="password" id="password" name="password" required minlength="6" autocomplete="new-password"></div>@error('password')<p class="auth-error-text">{{ $message }}</p>@enderror</div>
<div class="auth-field"><label for="password_confirmation">{{ __('Подтверждение пароля') }}</label><div class="auth-input-wrap"><i class="fas fa-shield-halved"></i><input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password"></div></div>
<button class="auth-submit">{{ __('Стать реселлером') }}</button>
</form><p class="auth-footer">{{ __('Уже есть аккаунт Zafiro?') }} <a href="{{ route('auth.show-login') }}">{{ __('Войти') }}</a></p>
@endsection
