@extends('layouts.operator')

@section('title', 'Обращения')

@section('content')
    <main>
        <h1>Открытые обращения</h1>
        <p class="subtitle">Сначала показаны обращения, которые ждут дольше всего.</p>

        <section class="panel">
            @if ($tickets->isEmpty())
                <div class="empty">Открытых обращений сейчас нет.</div>
            @else
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Создано (МСК)</th>
                        <th>Ожидание</th>
                        <th>Исходное обращение</th>
                        <th>Ответ оператора</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($tickets as $ticket)
                        <tr>
                            <td>#{{ $ticket->id }}</td>
                            <td>{{ $ticket->created_at->timezone('Europe/Moscow')->format('d.m.Y H:i') }}</td>
                            <td>{{ $ticket->created_at->diffForHumans(now(), ['parts' => 2, 'short' => true, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($ticket->triggerMessage->body, 110) }}</td>
                            <td>
                                <span class="badge">{{ $ticket->operator_messages_count > 0 ? 'Есть' : 'Ещё нет' }}</span>
                            </td>
                            <td><a class="button secondary" href="{{ route('tickets.show', $ticket) }}">Открыть</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <div style="margin-top:18px">{{ $tickets->links() }}</div>
    </main>
@endsection
