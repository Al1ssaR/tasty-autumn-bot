<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <style>
        body {
            align-items: center;
            background: #f7f4ed;
            color: #2f2a24;
            display: flex;
            font-family: system-ui, sans-serif;
            justify-content: center;
            margin: 0;
            min-height: 100vh;
        }

        main {
            background: #fff;
            border: 1px solid #e5ded2;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgb(55 43 27 / 8%);
            max-width: 640px;
            padding: 32px;
        }

        h1 {
            margin-top: 0;
        }

        a {
            color: #8a4f24;
        }
    </style>
</head>
<body>
    <main>
        <h1>«Вкусная осень» — AI Support MVP</h1>
        <p>Минимальный Laravel-каркас запущен. Telegram, LLM и операторская панель будут добавлены на следующих этапах.</p>
        <p><a href="{{ url('/up') }}">Проверить состояние приложения</a></p>
    </main>
</body>
</html>
