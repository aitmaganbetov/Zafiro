@extends('layouts.auth')

@section('title', 'Приглашение в клуб')

@section('content')
<div class="auth-card-head">
    <h2>Приглашение в клуб</h2>
    <p>Вас пригласили в «{{ $invitation->club->name }}» с ролью {{ $invitation->role }}@if($invitation->email) для {{ $invitation->email }}@endif.</p>
</div>

@if($alreadyMember)
    <div class="auth-errors">Ваш аккаунт уже состоит в этом клубе.</div>
@else
    <form action="{{ route('club-invitations.accept', $invitation->getKey()) }}" method="POST" class="auth-form">
        @csrf
        @if(auth()->check())
            <button class="auth-submit">Принять приглашение</button>
        @else
            <div class="auth-field"><label for="name">Имя</label><div class="auth-input-wrap"><i class="fas fa-user"></i><input id="name" name="name" value="{{ old('name') }}" required autocomplete="name" placeholder="Ваше имя"></div></div>
            <div class="auth-field"><label for="phone">Телефон</label><div class="auth-input-wrap"><i class="fas fa-phone"></i><input id="phone" name="phone" value="{{ old('phone') }}" required type="tel" inputmode="numeric" autocomplete="tel" placeholder="+7 700 123-45-67"></div></div>
            <div class="auth-field"><label for="password">Пароль</label><div class="auth-input-wrap"><i class="fas fa-lock"></i><input id="password" name="password" required type="password" minlength="6" autocomplete="new-password" placeholder="Не менее 6 символов"></div></div>
            <div class="auth-field"><label for="password_confirmation">Подтверждение пароля</label><div class="auth-input-wrap"><i class="fas fa-shield-halved"></i><input id="password_confirmation" name="password_confirmation" required type="password" minlength="6" autocomplete="new-password" placeholder="Повторите пароль"></div></div>
            <button class="auth-submit">Создать аккаунт и вступить</button>
        @endif
    </form>
@endif

@if($errors->any())<div class="auth-errors">{{ $errors->first() }}</div>@endif
@endsection