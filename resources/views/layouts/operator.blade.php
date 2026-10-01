<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Панель оператора') — Вкусная осень</title>
    <style>
        :root { color-scheme: light; --ink:#27311f; --muted:#68705f; --brand:#b44b22; --brand-dark:#873514; --paper:#fffdf7; --line:#e5decf; --soft:#f5efe2; --ok:#e8f4df; --bad:#fbe4df; }
        * { box-sizing: border-box; }
        body { margin:0; background:#f2eee4; color:var(--ink); font-family:Inter,Segoe UI,Arial,sans-serif; line-height:1.5; }
        a { color:var(--brand-dark); }
        header { background:#29341f; color:white; box-shadow:0 2px 10px #0002; }
        .bar { max-width:1120px; min-height:68px; margin:auto; padding:0 24px; display:flex; align-items:center; gap:28px; }
        .brand { color:white; text-decoration:none; font-size:1.15rem; font-weight:750; letter-spacing:.01em; }
        nav { display:flex; gap:18px; align-items:center; flex:1; }
        nav a { color:#f7f1e5; text-decoration:none; }
        nav a.active { border-bottom:2px solid #e9a55c; }
        .operator { color:#d7ddcd; font-size:.9rem; }
        .logout { display:inline; }
        .link-button { padding:0; border:0; background:none; color:#f7f1e5; cursor:pointer; font:inherit; }
        main { max-width:1120px; margin:32px auto; padding:0 24px 48px; }
        h1 { margin:0 0 8px; font-size:1.9rem; }
        h2 { margin-top:0; }
        .subtitle, .muted { color:var(--muted); }
        .panel { background:var(--paper); border:1px solid var(--line); border-radius:14px; box-shadow:0 8px 24px #49361a0b; overflow:hidden; }
        .panel-body { padding:24px; }
        .flash { margin:0 0 18px; padding:12px 16px; border-radius:9px; }
        .flash.success { background:var(--ok); border:1px solid #bfd6ae; }
        .flash.error { background:var(--bad); border:1px solid #dfb5aa; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:15px 16px; text-align:left; vertical-align:top; border-bottom:1px solid var(--line); }
        th { color:var(--muted); background:#faf7ef; font-size:.8rem; text-transform:uppercase; letter-spacing:.04em; }
        tbody tr:hover { background:#fff9ed; }
        .badge { display:inline-block; padding:3px 9px; border-radius:999px; background:var(--soft); font-size:.8rem; white-space:nowrap; }
        .badge.open { background:#fff0c9; color:#765000; }
        .badge.closed { background:#e5eadf; color:#40503a; }
        .button { display:inline-block; border:0; border-radius:9px; padding:10px 15px; background:var(--brand); color:white; cursor:pointer; font:inherit; font-weight:650; text-decoration:none; }
        .button:hover { background:var(--brand-dark); }
        .button.secondary { background:#53614a; }
        .button.danger { background:#8d3528; }
        .button[disabled] { opacity:.6; cursor:wait; }
        .ticket-head { display:flex; gap:18px; align-items:flex-start; justify-content:space-between; margin-bottom:22px; }
        .meta { display:flex; flex-wrap:wrap; gap:9px 22px; color:var(--muted); font-size:.92rem; }
        .messages { display:grid; gap:14px; }
        .message { width:min(760px, 92%); padding:14px 16px; border:1px solid var(--line); border-radius:13px; background:white; }
        .message.participant { justify-self:start; border-left:4px solid #d79639; }
        .message.bot, .message.system { justify-self:start; border-left:4px solid #6b8b58; background:#f5f8f1; }
        .message.operator { justify-self:end; border-right:4px solid #b44b22; background:#fff5e8; }
        .message-meta { display:flex; justify-content:space-between; gap:16px; margin-bottom:7px; color:var(--muted); font-size:.78rem; }
        .message-body { white-space:pre-wrap; overflow-wrap:anywhere; }
        .delivery-failed { margin-top:9px; color:#992f24; font-size:.85rem; }
        .reply { margin-top:24px; padding-top:24px; border-top:1px solid var(--line); }
        label { display:block; margin-bottom:7px; font-weight:650; }
        input, textarea { width:100%; border:1px solid #cfc5b3; border-radius:9px; padding:11px 12px; background:white; color:var(--ink); font:inherit; }
        textarea { min-height:130px; resize:vertical; }
        input:focus, textarea:focus { outline:3px solid #e9a55c55; border-color:#b56b35; }
        .field { margin-bottom:17px; }
        .validation { color:#992f24; font-size:.86rem; margin-top:5px; }
        .actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .stats { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; }
        .stat { padding:24px; background:var(--paper); border:1px solid var(--line); border-radius:14px; }
        .stat-value { margin:8px 0 2px; font-size:2rem; font-weight:750; }
        .login-shell { max-width:430px; margin:10vh auto; padding:0 22px; }
        .login-brand { text-align:center; margin-bottom:18px; }
        .empty { padding:46px 24px; text-align:center; color:var(--muted); }
        @media (max-width:760px) { .bar { flex-wrap:wrap; gap:10px 18px; padding:14px 18px; } nav { order:3; flex-basis:100%; } main { margin-top:22px; padding:0 14px 36px; } .stats { grid-template-columns:1fr; } table, thead, tbody, tr, th, td { display:block; } thead { display:none; } td { border-bottom:0; padding:7px 15px; } tr { display:block; padding:9px 0; border-bottom:1px solid var(--line); } .ticket-head { display:block; } }
    </style>
</head>
<body>
@auth
    <header>
        <div class="bar">
            <a class="brand" href="{{ route('tickets.index') }}">Вкусная осень</a>
            <nav aria-label="Основная навигация">
                <a class="{{ request()->routeIs('tickets.*') ? 'active' : '' }}" href="{{ route('tickets.index') }}">Обращения</a>
                <a class="{{ request()->routeIs('statistics.*') ? 'active' : '' }}" href="{{ route('statistics.index') }}">Статистика</a>
            </nav>
            <span class="operator">{{ auth()->user()->name }}</span>
            <form class="logout" method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="link-button" type="submit">Выход</button>
            </form>
        </div>
    </header>
@endauth

@yield('content')

<script>
    document.querySelectorAll('form[data-submit-once]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.disabled = true;
            });
        });
    });
</script>
</body>
</html>
