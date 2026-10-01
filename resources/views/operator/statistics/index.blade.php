@extends('layouts.operator')

@section('title', 'Статистика')

@section('content')
    <main>
        <h1>Статистика поддержки</h1>
        <p class="subtitle">Показатели за всё время, рассчитанные по сохранённым решениям и обращениям.</p>

        <section class="stats">
            <article class="stat">
                <div class="muted">Решено ботом</div>
                <div class="stat-value">{{ $bot_resolved }}</div>
                <div class="muted">валидных ответов доставлено</div>
            </article>
            <article class="stat">
                <div class="muted">Передано оператору</div>
                <div class="stat-value">{{ $escalated }}</div>
                <div class="muted">создано обращений</div>
            </article>
            <article class="stat">
                <div class="muted">Среднее время первого ответа</div>
                <div class="stat-value">{{ $average_operator_response ?? '—' }}</div>
                <div class="muted">до сохранённого ответа оператора</div>
            </article>
        </section>
    </main>
@endsection
