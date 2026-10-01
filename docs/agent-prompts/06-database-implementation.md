Продолжаем разработку тестового задания M-Social «Вкусная осень».

Предыдущие этапы завершены:

- анализ требований;
- архитектура и допущения;
- проектирование PostgreSQL-схемы;
- Laravel/Docker bootstrap;
- Docker smoke tests.

Теперь необходимо реализовать в Laravel ранее утверждённую модель данных.

Это этап реализации существующего дизайна, а НЕ повторного проектирования БД.

Перед началом полностью перечитай:

- `AGENTS.md`
- `docs/task.md`
- `docs/architecture.md`
- `docs/assumptions.md`
- `docs/database-schema.md`

`docs/database-schema.md` является основным источником истины для предметной модели текущего этапа.

Если во время реализации обнаружишь техническую невозможность или внутреннее противоречие схемы — не меняй её молча.

Сначала объясни проблему и выбери минимальное изменение, сохраняющее утверждённые инварианты.

---

# Scope текущего этапа

Нужно реализовать:

- PostgreSQL migrations;
- Eloquent models;
- relationships;
- database constraints;
- indexes;
- factories только если они реально нужны для тестов;
- автоматические тесты модели данных и ключевых DB invariants.

Пока НЕ реализуй:

- Telegram API;
- Telegram polling;
- LLM API;
- prompts бота;
- message orchestration;
- ticket application services;
- операторскую панель;
- statistics UI;
- evaluation harness.

Не добавляй бизнес-логику будущих этапов ради удобства тестирования БД.

---

# 1. Перед изменениями

Сначала проверь:

`git status`

`git branch --show-current`

`git log -10 --pretty=format:"%h %s"`

Убедись, что текущая ветка:

`task_mvp-tgbot`

Проверь существующие migrations Laravel и фактическое состояние проекта.

Не удаляй и не включай в свой commit пользовательские staged/unstaged изменения, существовавшие до текущего этапа.

Не выполняй `git push`.

---

# 2. Реализуй ровно пять предметных таблиц

Предметная модель должна соответствовать `docs/database-schema.md`:

1. `telegram_participants`
2. `messages`
3. `bot_decisions`
4. `support_tickets`
5. `users`

Не добавляй предметные таблицы:

- telegram_updates;
- delivery_attempts;
- statistics;
- roles;
- permissions;
- prompt_versions;
- rules;
- ticket_status_history;
- audit_log;

без новой критической причины.

Если Laravel содержит стандартные framework tables, это не считается расширением предметной модели.

---

# 3. `users`

Используй существующую Laravel `users` migration как основу, если это технически разумно.

Предметная модель требует:

- id;
- name;
- email unique;
- password hash;
- is_active;
- timestamps.

Все пользователи панели MVP являются операторами.

Не добавляй:

- role;
- permissions;
- RBAC tables.

Если стандартный Laravel skeleton содержит поля, которые не мешают архитектуре и нужны framework authentication, оцени их отдельно.

Не меняй framework behavior без необходимости.

Так как проект ещё не находится в production и реальных данных нет, допустимо корректировать исходную migration вместо создания искусственной цепочки migrations только ради истории разработки.

После этого обязательно проверяй схему через свежую БД/migrate:fresh.

---

# 4. `telegram_participants`

Реализуй согласно `docs/database-schema.md`.

Минимально:

- internal bigint primary key;
- `telegram_chat_id` BIGINT NOT NULL UNIQUE;
- timestamps.

Не добавляй:

- username;
- Telegram first name;
- Telegram last name;
- phone;
- language;
- telegram_user_id;

поскольку они не нужны утверждённым сценариям MVP.

---

# 5. `messages`

Реализуй единую таблицу истории.

Она должна поддерживать:

- participant messages;
- bot messages;
- system messages;
- operator messages.

Поля и ограничения должны соответствовать `docs/database-schema.md`.

Особенно внимательно реализуй:

- `telegram_participant_id`;
- nullable `support_ticket_id`;
- nullable `operator_user_id`;
- nullable unique `bot_decision_id`;
- `author_type`;
- `body`;
- nullable unique `telegram_update_id`;
- `telegram_message_id`;
- `delivery_status`;
- `telegram_sent_at`;
- `delivery_error`;
- `delivery_attempt_count`;
- timestamps.

Не заменяй утверждённые состояния boolean-полями.

---

# 6. `bot_decisions`

Реализуй:

- unique `incoming_message_id`;
- `action`;
- nullable `business_reason`;
- `technical_outcome`;
- `rule_references` JSONB;
- provider;
- model;
- prompt_version;
- rules_hash;
- decided_at.

Сохрани принцип:

BUSINESS reason и TECHNICAL outcome — разные понятия.

Допустимые business reasons:

- grounded_in_rules;
- participant_data_required;
- missing_rule;
- insufficient_context;
- unsafe_request.

Допустимые technical outcomes:

- valid;
- malformed_response;
- timeout;
- api_failure;
- application_failure.

Для технического fail-safe должно быть невозможно получить содержательно противоречивую строку вроде:

- malformed_response + action=answer;
- timeout + заполненный business_reason;
- api_failure + rule references.

По возможности обеспечь это PostgreSQL `CHECK` constraints, как описано в design.

---

# 7. `support_tickets`

Реализуй:

- participant FK;
- unique trigger message FK;
- status;
- first_operator_response_at;
- closed_at;
- timestamps.

Поддерживаются только:

- open;
- closed.

Ключевой invariant:

у participant может быть максимум ОДИН ticket со status=`open`.

Реализуй это PostgreSQL partial unique index, как спроектировано в документации.

Не пытайся заменить его простой application-level проверкой.

Не используй:

`UNIQUE (telegram_participant_id, status)`

потому что participant должен иметь любое количество закрытых tickets во времени.

---

# 8. Циклические foreign keys

В утверждённой модели существуют смысловые двусторонние связи:

- messages ↔ bot_decisions;
- messages ↔ support_tickets.

Не ломай модель только ради порядка migrations.

Выбери понятную migration strategy.

Допустимый подход:

1. создать таблицы, которые не зависят от циклических FK;
2. создать остальные таблицы;
3. отдельной migration или последующим `Schema::table` добавить FK, которые нельзя было создать первоначально.

Главные требования:

- fresh migration должна выполняться с нуля;
- rollback должен работать;
- схема после migrations должна соответствовать design;
- не должно быть временно nullable бизнес-полей только ради обхода проблемы после завершения migrations.

Объясни выбранный порядок.

---

# 9. PostgreSQL CHECK constraints

Реализуй важные database-level invariants из `docs/database-schema.md`.

Особенно:

### messages

Author-specific invariants:

- participant message не имеет operator_user_id;
- operator message имеет operator_user_id;
- operator message связан с support ticket;
- participant message использует delivery_status=`not_applicable`;
- исходящие bot/system/operator messages используют только исходящие delivery states;
- bot_decision_id допустим только для bot/system сообщений.

Delivery invariants:

- sent требует telegram_message_id;
- sent требует telegram_sent_at;
- sent не имеет delivery_error;
- failed требует delivery_error;
- failed не имеет telegram_sent_at;
- delivery attempt count неотрицательный;
- sent/failed требуют минимум одну попытку.

### bot_decisions

Для `technical_outcome = valid`:

- business_reason обязателен.

Для любого невалидного technical outcome:

- action = escalate;
- business_reason IS NULL;
- rule_references — пустой JSON array.

### support_tickets

- open → closed_at IS NULL;
- closed → closed_at NOT NULL;
- closed_at >= created_at;
- first_operator_response_at либо NULL, либо >= created_at.

Не используй PostgreSQL trigger, если обычного CHECK достаточно.

Laravel Schema Builder может не выражать все необходимые ограничения напрямую.

Разрешено использовать небольшие и явно документированные:

`DB::statement(...)`

для PostgreSQL-specific constraints/indexes.

Не превращай migrations в большой raw SQL dump.

---

# 10. Foreign key behavior

Реализуй утверждённую политику:

- никаких предметных cascade deletes;
- история поддержки не должна исчезать из-за удаления parent row;
- foreign keys используют RESTRICT/NO ACTION в соответствии с PostgreSQL/Laravel semantics.

Проверь, что удаление:

- participant с messages;
- user с operator messages;
- ticket с history;
- decision с response;

не может случайно уничтожить историю.

---

# 11. Индексы

Реализуй индексы из `docs/database-schema.md`, которые действительно нужны.

В частности:

- participant history;
- ticket history;
- open ticket partial unique index;
- open queue;
- participant ticket history.

Не дублируй индекс, который PostgreSQL уже создаёт для PK или UNIQUE constraint.

Не добавляй новые индексы «на всякий случай».

---

# 12. Eloquent models

Создай минимальные модели:

- TelegramParticipant;
- Message;
- BotDecision;
- SupportTicket;
- User.

Настрой необходимые relationships.

Например, модель должна позволять естественно получить:

- participant messages;
- participant tickets;
- message decision;
- message ticket;
- decision incoming message;
- decision response message;
- ticket trigger message;
- ticket history через подходящий query/service в будущем;
- operator messages.

Не пытайся выразить сложную ticket history как магическую Eloquent relation, если она состоит из trigger message + сообщений по support_ticket_id.

В таком случае лучше оставить понятный query для будущего application layer.

---

# 13. Casts

Используй Eloquent casts там, где это реально полезно.

Например:

- timestamps;
- `rule_references` → array;
- `is_active` → boolean.

Не создавай PHP enum classes только ради того, чтобы продублировать CHECK constraints на этом этапе, если это существенно увеличивает объём кода без выгоды.

Если считаешь enum классы оправданными — сначала оцени их необходимость для будущего application layer.

Приоритет текущего этапа — простая читаемая модель.

---

# 14. Mass assignment

Не используй бездумно:

`protected $guarded = [];`

на всех моделях.

Выбери явный и безопасный подход к fillable/guarded.

При этом не создавай огромные списки purely ceremonial кода.

---

# 15. Factories

Создавай factories только для тех моделей, где они реально облегчают автоматические тесты.

Factory не должна обходить database constraints или создавать заведомо некорректные состояния по умолчанию.

Не создавай seed demo-data на этом этапе.

---

# 16. Database tests

Это ключевая часть этапа.

Не ограничивайся тестом «migration succeeds».

Добавь automated tests, которые доказывают основные invariants.

Минимально проверь:

1. duplicate `telegram_chat_id` запрещён;
2. duplicate non-null `telegram_update_id` запрещён;
3. несколько messages с `telegram_update_id = NULL` разрешены;
4. один incoming message не может иметь два decisions;
5. один decision не может иметь два автоматических response messages;
6. один trigger message не может создать два tickets;
7. participant может иметь несколько closed tickets;
8. participant НЕ может иметь два open tickets;
9. другой participant может иметь собственный open ticket;
10. invalid technical outcome не может иметь action=`answer`;
11. invalid technical outcome не может иметь business_reason;
12. valid technical outcome требует business_reason;
13. ticket `closed` требует `closed_at`;
14. ticket `open` не может иметь `closed_at`;
15. participant message не может иметь исходящий delivery state;
16. sent outgoing message требует Telegram message id и sent_at;
17. failed outgoing message требует delivery_error;
18. operator message требует operator_user_id;
19. operator message требует support_ticket_id;
20. основные Eloquent relationships работают.

Особенно важно, чтобы PostgreSQL-specific constraints реально проверялись PostgreSQL, а не только SQLite.

---

# 17. PostgreSQL testing

Так как модель использует:

- JSONB;
- partial indexes;
- PostgreSQL CHECK behavior;
- timestamptz;

не считай SQLite достаточным доказательством корректности предметной схемы.

Финальный integration/database verification текущего этапа обязательно выполни на PostgreSQL внутри Docker.

Не добавляй вторую постоянную сложную database infrastructure без необходимости.

Для проверки допустимо создать отдельную временную test database или использовать другой безопасный изолированный способ.

Не уничтожай пользовательские данные или Docker volume без необходимости.

Объясни, как была обеспечена изоляция DB tests.

---

# 18. Migration verification

Фактически выполни на PostgreSQL:

- fresh migration с нуля;
- migration rollback;
- повторную migration;
- `migrate:status`.

Убедись, что migrations:

- работают с пустой БД;
- откатываются;
- повторно применяются;
- не зависят от случайного состояния старого volume.

При необходимости используй отдельную временную test database.

---

# 19. Schema verification

После migrations проверь реальную PostgreSQL schema.

Не ограничивайся чтением migration files.

Убедись, что фактически присутствуют:

- expected foreign keys;
- UNIQUE constraints;
- CHECK constraints;
- partial unique open-ticket index;
- остальные утверждённые indexes;
- JSONB;
- timestamptz.

Можно использовать PostgreSQL metadata queries или `psql`.

Не добавляй SQL-файлы в репозиторий только для этой проверки, если они не нужны приложению.

---

# 20. Laravel checks

После реализации выполни:

- Composer validation;
- Laravel test suite;
- database tests;
- Pint;
- `git diff --check`;
- Docker/Compose smoke verification в объёме, необходимом после изменения migrations.

Не утверждай успешность невыполненных команд.

---

# 21. Не реализовывай будущие application services

На текущем этапе НЕ создавай:

- MessageProcessor;
- TicketService;
- LlmClient;
- TelegramClient;
- Telegram worker;
- statistics service.

Даже если модели уже позволяют их написать.

Это следующий этап.

---

# 22. Documentation

Если реализация полностью соответствует `docs/database-schema.md`, не переписывай документ только ради того, чтобы показать изменение.

Если implementation detail потребовал небольшого обоснованного отклонения — обнови документацию точно и минимально.

Не скрывай различия implementation vs design.

---

# 23. Git

После успешного завершения:

1. проверь текущую ветку `task_mvp-tgbot`;
2. просмотри `git status`;
3. просмотри `git diff`;
4. выполни все проверки;
5. выборочно добавь только файлы текущего этапа;
6. проверь `git diff --cached`;
7. создай один осмысленный commit.

Commit message:

- на русском языке;
- в стиле предыдущей истории;
- описывает реализацию модели данных;
- не упоминает Codex/AI/prompt.

Не включай в commit существующие пользовательские staged changes, если они не относятся к текущему этапу.

`git push` не выполняй.

После commit проверь:

`git status`

`git log -1 --stat`

---

# Критерий готовности

Этап завершён только если утверждённая модель из `docs/database-schema.md` реально воспроизводится migrations на PostgreSQL и ключевые invariants подтверждены автоматическими тестами.

Недостаточно того, что:

- PHP-код компилируется;
- models существуют;
- migrations выглядят правильными визуально.

Database constraints должны быть фактически применены и проверены.

---

# Финальный отчёт

В конце сообщи:

1. какие migrations созданы/изменены;
2. какие Eloquent models созданы/изменены;
3. как решён порядок циклических foreign keys;
4. какие PostgreSQL-specific constraints/indexes реализованы через raw statement и почему;
5. как изолировалась test database;
6. какие DB invariants реально протестированы;
7. результаты fresh migration / rollback / migrate;
8. результаты test suite;
9. результаты Pint и остальных проверок;
10. обнаруженные отклонения от `docs/database-schema.md`;
11. hash и сообщение созданного commit;
12. какие файлы вошли в commit;
13. осталось ли что-либо, блокирующее следующий этап Telegram/message processing.

Не переходи к следующему этапу самостоятельно.
