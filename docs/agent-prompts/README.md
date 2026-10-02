# Development prompts Codex

В каталоге сохранены реальные пользовательские постановки, которые задавали этапы AI-assisted разработки проекта. Это не runtime prompts Telegram-бота и не искусственно восстановленные transcripts. Runtime prompt, JSON Schema и история версий находятся отдельно в [`prompts/bot/`](../../prompts/bot/README.md).

Основной порядок этапов:

1. [`01-analysis.md`](01-analysis.md) — инженерный анализ исходного задания;
2. [`02-architecture-and-assumptions.md`](02-architecture-and-assumptions.md) — архитектура и допущения;
3. [`03-database-design.md`](03-database-design.md) — проектирование PostgreSQL-схемы;
4. [`04-git-workflow.md`](04-git-workflow.md) — правила работы с Git;
5. [`05-project-bootstrap.md`](05-project-bootstrap.md) — Laravel/Docker bootstrap;
6. [`06-database-implementation.md`](06-database-implementation.md) — migrations, models и DB invariants;
7. [`07-telegram-transport.md`](07-telegram-transport.md) — Telegram transport, long polling и intake;
8. [`07a-telegram-live-e2e-smoke.md`](07a-telegram-live-e2e-smoke.md) — разрешённая живая проверка Telegram-контура;
9. [`08-llm-behavior-and-prompts.md`](08-llm-behavior-and-prompts.md) — LLM-контракт, safety и runtime prompts;
10. [`09-groq-integration-and-baseline.md`](09-groq-integration-and-baseline.md) — Groq adapter и baseline evaluation;
11. [`10-bot-v2-iteration.md`](10-bot-v2-iteration.md) — контролируемая итерация `bot-v2`;
12. [`11-operator-panel-and-stats.md`](11-operator-panel-and-stats.md) — операторская панель и статистика;
13. [`12-final-audit-and-submission.md`](12-final-audit-and-submission.md) — первый финальный аудит и упаковка;
14. [`13-operator-ux-metrics-bot-v3.md`](13-operator-ux-metrics-bot-v3.md) — UX оператора, метрика и routing `bot-v3`.

Файлы сохраняют фактические формулировки процесса и не переписываются задним числом под итоговую реализацию.
