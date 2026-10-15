@extends('layouts.auth')
@section('title', __('Вход на ПК'))
@section('content')
@if($login === null)
<div class="auth-card-head"><h2>{{ __('QR-код устарел') }}</h2><p>{{ __('Код уже использован или прошло больше 5 минут. Обновите QR-код на экране ПК и отсканируйте снова.') }}</p></div>
@else
<div class="auth-card-head"><h2>{{ __('Вход на ПК') }}</h2><p>{{ __('Клуб :club · ПК :computer', ['club' => $login->club?->name, 'computer' => $login->computer?->displayName()]) }}</p></div>
@if($errors->any())<div class="auth-errors">{{ $errors->first() }}</div>@endif
<p class="auth-footer">{{ __('Вы войдёте на этом ПК как :name.', ['name' => auth()->user()->name]) }}</p>
<form action="{{ route('pc-login.approve', $code) }}" method="POST" class="auth-form">@csrf
<button class="auth-submit">{{ __('Войти на ПК') }}</button>
</form>
<form action="{{ route('auth.logout') }}" method="POST" class="auth-form">@csrf
<p class="auth-footer">{{ __('Это не вы?') }} <button type="submit" class="auth-link-button">{{ __('Выйти из аккаунта') }}</button></p>
</form>
@endif
@endsection
