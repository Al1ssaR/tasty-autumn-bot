@extends('layouts.operator')

@section('title', 'Вход')

@section('content')
    <main class="login-shell">
        <div class="login-brand">
            <h1>Вкусная осень</h1>
            <div class="muted">Панель поддержки участников</div>
        </div>

        <section class="panel">
            <div class="panel-body">
                <h2>Вход оператора</h2>

                @if ($errors->any())
                    <div class="flash error">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" data-submit-once>
                    @csrf
                    <div class="field">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
                    </div>
                    <div class="field">
                        <label for="password">Пароль</label>
                        <input id="password" name="password" type="password" autocomplete="current-password" required>
                    </div>
                    <button class="button" type="submit">Войти</button>
                </form>
            </div>
        </section>
    </main>
@endsection
