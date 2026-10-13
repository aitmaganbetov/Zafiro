@extends('layouts.auth')
@section('title','Регистрация')
@section('content')
<div class="auth-card-head"><h2>Создать аккаунт</h2><p>Заполните данные для регистрации в системе</p></div>
@if($errors->any())<div class="auth-errors">{{ $errors->first() }}</div>@endif
<form action="{{ route('auth.register') }}" method="POST" class="auth-form">@csrf
<div class="auth-field"><label for="name">Имя</label><div class="auth-input-wrap"><i class="fas fa-user"></i><input type="text" id="name" name="name" value="{{ old('name') }}" required placeholder="Ваше имя"></div>@error('name')<p class="auth-error-text">{{ $message }}</p>@enderror</div>
<div class="auth-field"><label for="club_name">Название клуба</label><div class="auth-input-wrap"><i class="fas fa-building"></i><input type="text" id="club_name" name="club_name" value="{{ old('club_name') }}" required maxlength="255" placeholder="Например, Zafiro Club"></div>@error('club_name')<p class="auth-error-text">{{ $message }}</p>@enderror</div>
<div class="auth-field"><label for="phone">Номер телефона</label><div class="auth-input-wrap"><i class="fas fa-phone"></i><span class="auth-phone-prefix">+7</span><input class="auth-phone-local" type="tel" id="phone" name="phone" value="{{ substr(preg_replace('/\D+/', '', (string) old('phone', '')), -10) }}" required inputmode="numeric" pattern="[0-9]{3} [0-9]{3}-[0-9]{2}-[0-9]{2}" maxlength="13" placeholder="700 123-45-67" autocomplete="tel-national"></div>@error('phone')<p class="auth-error-text">{{ $message }}</p>@enderror</div>
<div class="auth-field"><label for="password">Пароль</label><div class="auth-input-wrap"><i class="fas fa-lock"></i><input type="password" id="password" name="password" required placeholder="Не менее 8 символов"></div>@error('password')<p class="auth-error-text">{{ $message }}</p>@enderror</div>
<div class="auth-field"><label for="password_confirmation">Подтверждение пароля</label><div class="auth-input-wrap"><i class="fas fa-shield-halved"></i><input type="password" id="password_confirmation" name="password_confirmation" required placeholder="Повторите пароль"></div></div>
<button class="auth-submit">Создать аккаунт</button>
</form><p class="auth-footer">Уже есть аккаунт? <a href="{{ route('auth.show-login') }}">Войти</a></p>
@endsection
