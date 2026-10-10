@extends('layouts.auth')
@section('title','Вход')
@section('content')
<div class="auth-card-head"><h2>Добро пожаловать</h2><p>Войдите, чтобы продолжить работу в Zafiro</p></div>
@if($errors->any())<div class="auth-errors">{{ $errors->first() }}</div>@endif
<form action="{{ route('auth.login') }}" method="POST" class="auth-form">@csrf
<div class="auth-field"><label for="phone">Номер телефона</label><div class="auth-input-wrap"><i class="fas fa-phone"></i><input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required placeholder="+7 (999) 123-45-67"></div>@error('phone')<p class="auth-error-text">{{ $message }}</p>@enderror</div>
<div class="auth-field"><label for="password">Пароль</label><div class="auth-input-wrap"><i class="fas fa-lock"></i><input type="password" id="password" name="password" required placeholder="Введите пароль"></div></div>
<div class="auth-row"><label class="auth-check"><input type="checkbox" name="remember"> Запомнить меня</label></div>
<button class="auth-submit">Войти в систему</button>
</form><p class="auth-footer">Нет аккаунта? <a href="{{ route('auth.show-register') }}">Зарегистрироваться</a></p>
@endsection
