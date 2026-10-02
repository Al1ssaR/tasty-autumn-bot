# Checklist готовности к сдаче

Статусы основаны на фактическом коде и артефактах репозитория, а не только на описании в README.

| Требование | Реализация | Где смотреть | Статус |
|---|---|---|---|
| PHP/Laravel MVP | Laravel 13, типизированные application services и thin controllers | `composer.json`, `app/` | DONE |
| PostgreSQL | Production-конфигурация `pgsql`, migrations, FK, CHECK, JSONB, `timestamptz`, partial unique index и индексы запросов | `compose.yaml`, `database/migrations/`, `docs/database-schema.md` | DONE |
| Запуск через Docker Compose | `app`, `bot`, `postgres`; healthchecks и автоматические migrations | `compose.yaml`, `Dockerfile`, `README.md` | DONE |
| Реальный Telegram bot | BotFather bot и настоящий incoming update были проверены на предыдущем этапе; в final audit новые live send не выполнялись | `README.md`, Git history | DONE |
| Telegram Bot API | Собственный HTTP client, `getUpdates`, `sendMessage`, `getMe` | `app/Telegram/TelegramHttpClient.php` | DONE |
| Long polling и text intake | Worker, parser, private text messages, static response для unsupported attachments | `app/Telegram/`, `app/Console/Commands/TelegramPollCommand.php` | DONE |
| Идемпотентность updates | UNIQUE `messages.telegram_update_id` и безопасная обработка duplicate | migration, `PostgresIncomingMessageIntake` | DONE |
| Ответ по правилам | Полные правила и МСК-время передаются LLM, structured result валидируется | `app/Llm/`, `prompts/bot/` | DONE |
| Routing | `answer`; ticket только для personal/missing и technical fail-safe; static response для unclear/out-of-scope/unsafe | `MessageClassificationService`, routing dataset/tests | DONE |
| PII и prompt injection | Телефоны/PAN redacted до provider; unsafe generated answer отбрасывается; tools у LLM нет | `PiiRedactor`, `StructuredDecisionValidator` | DONE |
| Operator authentication | Login/logout, session auth, inactive operator restriction, interactive account creation | `app/Http/`, `operator:create` | DONE |
| Очередь и история | Только open tickets, trigger и последующие participant/bot/system/operator messages | operator controllers/services/views | DONE |
| Ответ оператора | Сохранение исходного body до внешнего вызова, delivery-prefix `Оператор:`, Telegram delivery status, safe failure state | `TicketReplyService`, `TelegramMessageDelivery` | DONE |
| Закрытие обращения | Transactional pending notification, доставка после commit, failed state без rollback, идемпотентный repeat close; новый вопрос снова классифицируется | `TicketCloseService`, feature tests | DONE |
| Статистика | Delivered valid answers; число tickets; средняя duration `created_at → first_operator_response_at` только для отвеченных, без timezone offset | `SupportStatistics`, duration formatter, statistics view/tests | DONE |
| Runtime prompt отдельными файлами | `bot-v3`, strict JSON Schema, неизменяемые `bot-v1` и `bot-v2` | `prompts/bot/` | DONE |
| Прогон 25 обращений | Оригинальный dataset, отдельные v1/v2/v3 JSON и Markdown, ручная оценка и технические сбои | `docs/requests.md`, `docs/evaluation-results-v3.md` | DONE |
| Честный итог evaluation | `bot-v3`: 21/25 exact, 19/25 content PASS, 6 FAIL, 1 technical failure; №23–25 помечены как изменение product semantics, routing dataset — 8/10 | `docs/evaluation-results-v3.md`, `docs/routing-evaluation-v3.md` | DONE |
| Архитектура, допущения и ERD | Границы, trade-offs, актуальные entities/relations/constraints/indexes | `docs/architecture.md`, `docs/assumptions.md`, `docs/database-schema.md` | DONE |
| First-run README | Быстрый запуск: environment, APP_KEY, secrets, health, operator, panel, bot, Groq, tests, evaluation и stop | `README.md`, `.env.example` | DONE |
| Development prompts | Реальные сохранённые постановки и индекс; runtime prompts отделены | `AGENTS.md`, `docs/agent-prompts/` | DONE |
| 3 session links | Подготовлена честная структура; реальные Codex share links пользователь ещё не предоставил | `docs/agent-sessions/README.md` | MISSING |
| `CLAUDE.md` | Claude Code не использовался | `README.md` | NOT REQUIRED |
| Production improvements и ограничения | Отдельно перечислены без заявления об их реализации | `README.md` | DONE |
| Secrets | `.env` игнорируется, placeholders безопасны; high-confidence scan tracked history не нашёл token/key/header | `.gitignore`, `.env.example`, Git audit | DONE |

Обязательных функциональных требований MVP со статусом `MISSING` не обнаружено. Единственный отсутствующий deliverable — три настоящих Codex session links, которые нельзя достоверно сгенерировать из prompt-файлов.
