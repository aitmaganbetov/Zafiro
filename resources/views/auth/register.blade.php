@extends('layouts.auth')
@section('title','Регистрация')
@section('content')
<div class="auth-card-head"><h2>Создать аккаунт</h2><p>Заполните данные для регистрации в системе</p></div>
@if($errors->any())<div class="auth-errors">{{ $errors->first() }}</div>@endif
<form action="{{ route('auth.register') }}" method="POST" class="auth-form">@csrf
<div class="auth-field"><label for="name">Имя</label><div class="auth-input-wrap"><i class="fas fa-user"></i><input type="text" id="name" name="name" value="{{ old('name') }}" required placeholder="Ваше имя"></div>@error('name')<p class="auth-error-text">{{ $message }}</p>@enderror</div>
<div class="auth-field"><label for="phone">Номер телефона</label><div class="auth-input-wrap"><i class="fas fa-phone"></i><input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required placeholder="+7 (999) 123-45-67"></div>@error('phone')<p class="auth-error-text">{{ $message }}</p>@enderror</div>
<div class="auth-field"><label for="password">Пароль</label><div class="auth-input-wrap"><i class="fas fa-lock"></i><input type="password" id="password" name="password" required placeholder="Не менее 8 символов"></div>@error('password')<p class="auth-error-text">{{ $message }}</p>@enderror</div>
<div class="auth-field"><label for="password_confirmation">Подтверждение пароля</label><div class="auth-input-wrap"><i class="fas fa-shield-halved"></i><input type="password" id="password_confirmation" name="password_confirmation" required placeholder="Повторите пароль"></div></div>
<button class="auth-submit">Создать аккаунт</button>
</form><p class="auth-footer">Уже есть аккаунт? <a href="{{ route('auth.show-login') }}">Войти</a></p>
@endsection
