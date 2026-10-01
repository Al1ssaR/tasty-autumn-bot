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
| Эскалация | Personal/missing/unsafe/technical cases создают ticket; application добавляет notice | `MessageClassificationService` | DONE |
| PII и prompt injection | Телефоны/PAN redacted до provider; unsafe generated answer отбрасывается; tools у LLM нет | `PiiRedactor`, `StructuredDecisionValidator` | DONE |
| Operator authentication | Login/logout, session auth, inactive operator restriction, interactive account creation | `app/Http/`, `operator:create` | DONE |
| Очередь и история | Только open tickets, trigger и последующие participant/bot/system/operator messages | operator controllers/services/views | DONE |
| Ответ оператора | Сохранение до внешнего вызова, Telegram delivery status, safe failure state | `TicketReplyService`, `TelegramMessageDelivery` | DONE |
| Закрытие обращения | Идемпотентное закрытие; closed ticket исчезает из queue; новый вопрос снова классифицируется | `TicketCloseService`, feature tests | DONE |
| Статистика | Delivered valid answers; число tickets; среднее `created_at → first_operator_response_at` только для отвеченных | `SupportStatistics`, statistics view/tests | DONE |
| Runtime prompt отдельными файлами | `bot-v2`, strict JSON Schema, неизменяемый baseline `bot-v1` | `prompts/bot/` | DONE |
| Прогон 25 обращений | Оригинальный dataset, v1/v2 JSON и Markdown, ручная оценка и технические сбои | `docs/requests.md`, `docs/evaluation-results-v2.md` | DONE |
| Честный итог evaluation | `bot-v2`: 20/25 exact, 17/25 content PASS, 8 FAIL, 2 technical failures, security 2/2 | `docs/evaluation-results-v2.md` | DONE |
| Архитектура, допущения и ERD | Границы, trade-offs, актуальные entities/relations/constraints/indexes | `docs/architecture.md`, `docs/assumptions.md`, `docs/database-schema.md` | DONE |
| First-run README | Environment, APP_KEY, secrets, operator, panel, bot, Groq, tests, evaluation, stop | `README.md`, `.env.example` | DONE |
| Development prompts | Реальные сохранённые постановки и индекс; runtime prompts отделены | `AGENTS.md`, `docs/agent-prompts/` | DONE |
| 2–3 session exports | Fake logs не создавались; требуется пользовательский экспорт из Codex UI, если он доступен | `docs/agent-sessions/README.md` | MISSING |
| `CLAUDE.md` | Claude Code не использовался | `README.md` | NOT REQUIRED |
| Production improvements и ограничения | Отдельно перечислены без заявления об их реализации | `README.md` | DONE |
| Secrets | `.env` игнорируется, placeholders безопасны; high-confidence scan tracked history не нашёл token/key/header | `.gitignore`, `.env.example`, Git audit | DONE |

Обязательных функциональных требований MVP со статусом `MISSING` не обнаружено. Единственный отсутствующий deliverable — настоящие session exports, которые нельзя достоверно сгенерировать из prompt-файлов.
