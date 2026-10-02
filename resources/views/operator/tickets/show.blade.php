@extends('layouts.operator')

@section('title', 'Обращение #'.$ticket->id)

@section('content')
    <main>
        @if (session('success'))
            <div class="flash success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="flash error">{{ session('error') }}</div>
        @endif

        <div class="ticket-head">
            <div>
                <h1>Обращение #{{ $ticket->id }}</h1>
                <div class="meta">
                    <span>Статус: <strong>{{ $ticket->status === 'open' ? 'Открыто' : 'Закрыто' }}</strong></span>
                    <span>Создано: {{ $ticket->created_at->timezone('Europe/Moscow')->format('d.m.Y H:i:s') }} МСК</span>
                    @if ($ticket->first_operator_response_at)
                        <span>Первый ответ: {{ $ticket->first_operator_response_at->timezone('Europe/Moscow')->format('d.m.Y H:i:s') }} МСК</span>
                    @endif
                    @if ($ticket->closed_at)
                        <span>Закрыто: {{ $ticket->closed_at->timezone('Europe/Moscow')->format('d.m.Y H:i:s') }} МСК</span>
                    @endif
                </div>
            </div>
            <span class="badge {{ $ticket->status }}">{{ $ticket->status === 'open' ? 'Открыто' : 'Закрыто' }}</span>
        </div>

        <section class="panel">
            <div class="panel-body">
                <h2>История</h2>
                <div class="messages">
                    @foreach ($messages as $message)
                        @php
                            $author = match ($message->author_type) {
                                'participant' => 'Участник',
                                'bot' => 'Бот',
                                'system' => 'Система',
                                'operator' => 'Оператор'.($message->operator ? ' · '.$message->operator->name : ''),
                            };
                        @endphp
                        <article class="message {{ $message->author_type }}">
                            <div class="message-meta">
                                <strong>{{ $author }}</strong>
                                <time>{{ $message->created_at->timezone('Europe/Moscow')->format('d.m.Y H:i:s') }}</time>
                            </div>
                            <div class="message-body">{{ $message->body }}</div>
                            @if ($message->author_type === 'operator' && $message->delivery_status === 'failed')
                                <div class="delivery-failed">Не доставлено в Telegram: {{ $message->delivery_error }}</div>
                            @elseif ($message->author_type === 'operator' && $message->delivery_status === 'sent')
                                <div class="muted" style="margin-top:9px;font-size:.82rem">Доставлено в Telegram</div>
                            @endif
                        </article>
                    @endforeach
                </div>

                @if ($ticket->status === 'open')
                    <div class="reply">
                        <h2>Ответ участнику</h2>
                        <form method="POST" action="{{ route('tickets.replies.store', $ticket) }}" data-submit-once>
                            @csrf
                            <div class="field">
                                <label for="body">Текст ответа</label>
                                <textarea id="body" name="body" maxlength="4000" required>{{ old('body') }}</textarea>
                                @error('body')<div class="validation">{{ $message }}</div>@enderror
                            </div>
                            <div class="actions">
                                <button class="button" type="submit">Отправить в Telegram</button>
                            </div>
                        </form>

                        <form method="POST" action="{{ route('tickets.close', $ticket) }}" data-submit-once style="margin-top:24px">
                            @csrf
                            <button class="button danger" type="submit">Закрыть обращение</button>
                        </form>
                    </div>
                @else
                    <div class="reply muted">Обращение закрыто. Отправка новых ответов недоступна.</div>
                @endif
            </div>
        </section>
    </main>
@endsection
