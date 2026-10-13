@extends('layouts.auth')

@section('title', __('Приглашение в клуб'))

@section('content')
<div class="auth-card-head">
    <h2>{{ __('Приглашение в клуб') }}</h2>
    <p>@if($invitation->email){{ __('Вас пригласили в «:club» с ролью :role для :email.', ['club' => $invitation->club->name, 'role' => $invitation->role, 'email' => $invitation->email]) }}@else{{ __('Вас пригласили в «:club» с ролью :role.', ['club' => $invitation->club->name, 'role' => $invitation->role]) }}@endif</p>
</div>

@if($alreadyMember)
    <div class="auth-errors">{{ __('Ваш аккаунт уже состоит в этом клубе.') }}</div>
@else
    <form action="{{ route('club-invitations.accept', $invitation->getKey()) }}" method="POST" class="auth-form">
        @csrf
        @if(auth()->check())
            <button class="auth-submit">{{ __('Принять приглашение') }}</button>
        @else
            <div class="auth-field"><label for="name">{{ __('Имя') }}</label><div class="auth-input-wrap"><i class="fas fa-user"></i><input id="name" name="name" value="{{ old('name') }}" required autocomplete="name" placeholder="{{ __('Ваше имя') }}"></div></div>
            <div class="auth-field"><label for="phone">{{ __('Телефон') }}</label><div class="auth-input-wrap"><i class="fas fa-phone"></i><input id="phone" name="phone" value="{{ old('phone') }}" required type="tel" inputmode="numeric" autocomplete="tel" placeholder="+7 700 123-45-67"></div></div>
            <div class="auth-field"><label for="password">{{ __('Пароль') }}</label><div class="auth-input-wrap"><i class="fas fa-lock"></i><input id="password" name="password" required type="password" minlength="6" autocomplete="new-password" placeholder="{{ __('Не менее 6 символов') }}"></div></div>
            <div class="auth-field"><label for="password_confirmation">{{ __('Подтверждение пароля') }}</label><div class="auth-input-wrap"><i class="fas fa-shield-halved"></i><input id="password_confirmation" name="password_confirmation" required type="password" minlength="6" autocomplete="new-password" placeholder="{{ __('Повторите пароль') }}"></div></div>
            <button class="auth-submit">{{ __('Создать аккаунт и вступить') }}</button>
        @endif
    </form>
@endif

@if($errors->any())<div class="auth-errors">{{ $errors->first() }}</div>@endif
@endsection