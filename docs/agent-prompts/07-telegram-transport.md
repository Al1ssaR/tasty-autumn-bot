# Codex Prompt 07 — Telegram transport и реальный BotFather smoke-test

Продолжаем разработку тестового задания M-Social «Вкусная осень».

Предыдущие этапы завершены:

- анализ требований;
- архитектура и допущения;
- проектирование PostgreSQL-схемы;
- Laravel/Docker bootstrap;
- реализация migrations, Eloquent models и PostgreSQL invariants.

Telegram-бот уже создан вручную через BotFather.

Реальный Telegram bot token уже находится в локальном `.env`.

ВАЖНО:

- токен является секретом;
- НЕ выводи его в terminal output;
- НЕ вставляй его в логи;
- НЕ копируй его в README;
- НЕ сохраняй его в fixtures/tests;
- НЕ добавляй `.env` в Git;
- НЕ показывай полный Telegram API URL, поскольку token является частью URL.

На этом этапе НЕ подключаем LLM.

Цель — получить работающий и протестированный контур:

Telegram Bot API
→ long polling
→ parsing update
→ participant
→ incoming message
→ idempotency
→ проверка открытого support ticket
→ результат для будущего classification layer.

После автоматических fake-тестов необходимо также провести безопасный smoke-test с уже созданным реальным BotFather-ботом.

Перед началом полностью перечитай:

- `AGENTS.md`
- `docs/task.md`
- `docs/architecture.md`
- `docs/assumptions.md`
- `docs/database-schema.md`

Также изучи фактические migrations и Eloquent models.

Не меняй утверждённую модель данных без реальной необходимости.

---

# 1. Scope этапа

Нужно реализовать:

- конфигурацию Telegram;
- transport abstraction;
- реальный Telegram Bot API client;
- получение updates через long polling;
- Artisan command для polling worker;
- parsing входящих Telegram updates;
- создание/reuse `TelegramParticipant`;
- сохранение входящего `Message`;
- защиту от duplicate update;
- ветку существующего открытого support ticket;
- безопасную обработку ошибок Telegram transport;
- unit/feature tests;
- Docker Compose service для bot worker;
- безопасную проверку реального BotFather token;
- реальный Telegram smoke-test в доступном объёме.

Пока НЕ реализовывать:

- LLM API;
- system prompt;
- bot decisions на основании правил;
- содержательные ответы участнику по правилам;
- создание нового support ticket на основании LLM;
- operator panel;
- statistics UI;
- evaluation 25 обращений.

Для нового входящего текстового сообщения без открытого ticket текущий этап должен только подготовить его к будущей классификации.

Не имитируй LLM.

---

# 2. Перед изменениями

Выполни:

`git status`

`git branch --show-current`

`git log -10 --pretty=format:"%h %s"`

Рабочая ветка должна быть:

`task_mvp-tgbot`

Не включай в commit пользовательские staged или unstaged изменения, существовавшие до текущего этапа.

Не выполняй `git push`.

Перед любыми действиями с реальным Telegram API убедись только в факте наличия `TELEGRAM_BOT_TOKEN` в environment.

НЕ выводи его значение.

Допустимо вывести только безопасное сообщение:

`TELEGRAM_BOT_TOKEN is configured`

или аналогичное.

---

# 3. Не использовать Telegram SDK без необходимости

Для данного MVP предпочти стандартный Laravel HTTP client.

Не устанавливай Telegram SDK только ради нескольких методов Bot API.

Telegram transport должен быть изолирован собственным application interface, чтобы остальная система не зависела от конкретного HTTP-клиента.

Нужна небольшая и понятная abstraction.

Например, концептуально:

`TelegramClient`

с транспортными операциями уровня:

- получить updates;
- отправить сообщение;
- получить безопасную информацию о текущем боте для smoke-test, если это оправдано.

Конкретные имена методов и DTO выбери по фактической архитектуре проекта.

Не помещай бизнес-логику поддержки внутрь Telegram client.

Telegram client не должен:

- создавать participant;
- писать messages в БД;
- искать support tickets;
- вызывать LLM;
- принимать решение answer/escalate.

---

# 4. Telegram configuration

Создай отдельную конфигурацию Telegram в Laravel.

Используй environment variables.

Реальный token уже присутствует локально в:

`TELEGRAM_BOT_TOKEN`

Минимально нужны:

- bot token;
- API base URL;
- long polling timeout;
- HTTP request timeout.

Не изменяй реальный token автоматически.

Не записывай его в `.env.example`.

В `.env.example` должно оставаться только:

`TELEGRAM_BOT_TOKEN=`

без значения.

Соблюдай invariant:

HTTP timeout должен быть больше Telegram long-poll timeout.

Не допускай конфигурацию, при которой HTTP client регулярно обрывает нормальный long polling раньше Telegram.

При необходимости валидируй это на старте worker.

---

# 5. Безопасность Telegram token

Telegram bot token является секретом и фактически участвует в URL Bot API.

Особенно внимательно исключи его утечку через:

- exception messages;
- Laravel logs;
- HTTP client errors;
- Artisan output;
- debug output;
- Docker logs;
- test output;
- screenshots/snapshots;
- final report.

Не логируй полный request URL Telegram API.

Если exception от HTTP layer потенциально содержит URL с token, обязательно преобразуй ошибку в безопасное transport exception/result.

Для диагностики достаточно:

- операция (`getUpdates`, `sendMessage`, `getMe`);
- HTTP status;
- Telegram error code, если безопасно;
- безопасное description без token.

Добавь автоматический тест, подтверждающий, что token не появляется в публичном/safe error message.

Не вставляй фактическое значение token в тест.

---

# 6. Telegram API client

Реализуй минимальный реальный клиент Telegram Bot API через Laravel HTTP client.

На текущем этапе нужны:

### getUpdates

Поддержать:

- `offset`;
- Telegram long polling timeout;
- ограничение update types, если это помогает избежать ненужных updates.

Для MVP нас интересуют обычные `message` updates.

### sendMessage

Реализовать transport primitive:

- chat id;
- text;
- получение Telegram message id при успехе.

Даже если содержательный bot reply появится на следующем этапе, этот primitive понадобится:

- системным сообщениям;
- bot replies;
- operator replies.

Метод НЕ пишет ничего в БД самостоятельно.

### getMe

Допустимо реализовать минимальный вызов `getMe` для проверки реального BotFather token.

Он используется только как транспортная/diagnostic возможность.

Не сохраняй в БД сведения профиля бота.

Не выводи token при выполнении проверки.

---

# 7. DTO / transport result

Не передавай по приложению необработанные массивы Telegram API там, где это делает код хрупким.

Создай минимальные DTO/value objects только для реально используемых данных.

Для входящего поддерживаемого сообщения достаточно данных вроде:

- update id;
- chat id;
- Telegram message id;
- text.

Для результата отправки:

- success;
- Telegram message id при успехе;
- безопасная transport error при неуспехе.

Не моделируй весь Telegram API.

Не сохраняй username/name/language участника только потому, что они присутствуют в update.

---

# 8. Поддерживаемые входящие сообщения

MVP поддерживает только текстовые сообщения в private chat.

Поддерживаемый update:

- содержит `message`;
- имеет private chat;
- содержит непустой текст.

Не воспринимай:

- callback query;
- edited_message;
- channel post;
- service update;

как обычный пользовательский вопрос.

Не пытайся поддерживать весь Telegram API.

Команды Telegram (`/start` и другие) технически являются текстовыми сообщениями.

Не добавляй специальную бизнес-логику `/start`, если она не нужна текущему transport-этапу.

---

# 9. Неподдерживаемые типы сообщений

В `docs/assumptions.md` зафиксировано:

неподдерживаемый тип сообщения не анализируется LLM и получает нейтральное уведомление о том, что бот принимает текстовые вопросы.

На текущем этапе реализуй это без LLM.

Для private message с:

- photo;
- document;
- voice;
- video;
- sticker;

отправь короткий статический ответ приложения, например по смыслу:

«Пожалуйста, отправьте вопрос текстовым сообщением.»

Формулировку можешь немного улучшить, но не добавляй новых возможностей.

Не пытайся извлекать OCR/transcription.

Не скачивай файлы.

Не создавай attachments table.

Если сохранение unsupported input потребовало бы искусственного `body` и изменения утверждённой модели — не изменяй БД.

Такой update можно обработать transport/application layer без создания domain `Message`.

---

# 10. Incoming message processing service

Создай application service, отвечающий только за intake входящего поддерживаемого Telegram message.

Не называй его слишком общо, если он пока не выполняет полную orchestration будущего бота.

Его обязанности:

1. принять нормализованный Telegram incoming message;
2. обеспечить participant;
3. сохранить incoming message;
4. защититься от duplicate update;
5. проверить наличие открытого support ticket;
6. если ticket открыт — связать новое сообщение с ним;
7. если ticket отсутствует — оставить сообщение готовым для следующего classification этапа;
8. вернуть явный processing result.

Он НЕ должен:

- вызывать LLM;
- создавать BotDecision;
- создавать новый SupportTicket;
- формировать содержательный ответ по правилам;
- вычислять статистику.

---

# 11. Processing result

Результат intake должен явно различать минимум:

- новое сообщение готово к classification;
- сообщение добавлено в существующий open ticket;
- duplicate update;
- update проигнорирован как неподдерживаемый/нерелевантный, если это относится к выбранному слою.

Не кодируй это набором неочевидных boolean flags.

Допустим небольшой enum/value object.

Контракт должен быть понятен следующему classification layer.

---

# 12. Participant creation

Participant идентифицируется только через:

`telegram_chat_id`.

Не добавляй profile fields.

Учти concurrency race, когда два первых сообщения одного chat приходят практически одновременно.

Уникальность `telegram_chat_id` уже защищена PostgreSQL.

Реализация должна корректно переживать unique conflict и получить существующего participant вместо создания дубля.

Не добавляй distributed locking.

Не ловить любые DB exception как duplicate participant.

---

# 13. Incoming message persistence

Для поддерживаемого текста создаётся `messages` со значениями, согласованными с существующими PostgreSQL constraints.

Сохраняются:

- `telegram_participant_id`;
- `author_type = participant`;
- оригинальный `text`;
- `telegram_update_id`;
- `telegram_message_id`;
- `delivery_status = not_applicable`;
- `support_ticket_id`, если уже есть открытый ticket.

Не исправляй:

- опечатки;
- регистр;
- пунктуацию;
- грубый тон пользователя.

Оригинальный текст должен сохраняться как есть.

Не создавай `BotDecision` на этом этапе.

---

# 14. Telegram idempotency

Database UNIQUE по `telegram_update_id` остаётся окончательной гарантией.

Application-level lookup допустим для normal path, но не считается достаточной защитой.

Нужно корректно обработать race:

два процесса одновременно пытаются сохранить один `update_id`.

Ожидаемый результат:

- одна incoming message;
- duplicate корректно распознаётся;
- необработанный DB exception наружу не выходит;
- в будущем duplicate не сможет повторно запустить LLM.

Не ловить все database exceptions как duplicate.

Распознавай relevant PostgreSQL unique violation.

---

# 15. Existing open ticket

Если participant уже имеет открытый support ticket:

- incoming message связывается с существующим ticket;
- новый BotDecision не создаётся;
- новый SupportTicket не создаётся;
- LLM не вызывается;
- processing result говорит, что сообщение добавлено в existing ticket.

Это должно соответствовать утверждённой архитектуре.

На текущем этапе автоматический содержательный ответ на такое сообщение не нужен.

---

# 16. New message without open ticket

Если открытого ticket нет:

- incoming message сохраняется;
- `support_ticket_id = NULL`;
- BotDecision отсутствует;
- processing result обозначает сообщение как готовое для classification.

Не добавляй новое состояние в БД вроде:

`needs_classification`.

Следующий этап выполнит classification сразу после intake.

---

# 17. Long polling Artisan command

Создай отдельную Artisan command для Telegram polling worker.

Например:

`telegram:poll`

Конкретное имя выбери читаемое.

Команда должна:

1. безопасно проверить наличие Telegram configuration;
2. начать long polling;
3. получать updates;
4. передавать каждый update parser/processor;
5. корректно вычислять следующий offset;
6. безопасно обрабатывать transport errors;
7. не завершаться из-за одного malformed/unsupported update;
8. не выводить token.

---

# 18. Telegram offset semantics

Используй Telegram `update_id`.

После update N следующий polling request должен использовать соответствующий offset по Bot API semantics.

Учти сценарий:

1. incoming update сохранён в БД;
2. worker падает до следующего getUpdates;
3. Telegram после рестарта возвращает update повторно.

Это нормальный сценарий.

Database idempotency должна превратить его в безопасный duplicate.

Не создавай таблицу offset/checkpoint только для MVP.

---

# 19. Ошибки обработки

Разделяй:

### Telegram transport error

Например:

- network failure;
- HTTP timeout;
- Telegram API error.

Используй простой ограниченный backoff/delay.

### Malformed/unsupported update

Не должен останавливать worker.

### Domain/database failure

Не должен маскироваться как успешная обработка.

Не помещай `sleep` в application service.

Polling loop отвечает за transport backoff.

---

# 20. Testability polling command

Бесконечный loop неудобен для автоматических тестов.

Сделай core polling iteration тестируемой отдельно.

Допустим вариант:

`--once`

который выполняет один polling batch и завершает command.

Он также будет полезен для безопасного smoke-test.

Не превращай это в отдельный production scheduler.

---

# 21. Docker Compose

Добавь отдельный Compose service:

`bot`

Он должен:

- использовать тот же application image;
- использовать те же Laravel source/config;
- зависеть от PostgreSQL healthcheck;
- запускать polling Artisan command;
- не запускать web server.

Итоговые сервисы:

- `app`;
- `bot`;
- `postgres`.

Не создавай отдельный Dockerfile для worker.

---

# 22. Работа с уже существующим реальным BotFather token

В локальном `.env` token уже установлен.

Поэтому после реализации разрешается использовать его для РЕАЛЬНОГО smoke-test Telegram transport.

При этом:

- НЕ читать token пользователю;
- НЕ показывать его в final report;
- НЕ выводить полный Telegram URL;
- НЕ добавлять `.env` в git;
- НЕ копировать значение в `.env.example`;
- НЕ сохранять значение в PHPUnit configuration.

Сначала выполни автоматические тесты только на fake transport.

Только после того как они проходят, переходи к live smoke-test.

---

# 23. Live Telegram authentication smoke-test

С реальным локальным token выполни безопасный вызов Telegram `getMe`.

Проверь:

- Telegram API принял credentials;
- bot действительно существует;
- transport client корректно разбирает successful response.

В отчёте допустимо указать:

- что `getMe` успешно выполнен;
- username/name бота, если Telegram API вернул их и это не является секретом.

Token не указывать.

Если `getMe` не проходит:

- не маскируй проблему;
- покажи безопасную ошибку без token;
- не переходи к утверждению, что live Telegram integration работает.

---

# 24. Live incoming-message smoke-test

После успешного `getMe`:

1. запусти PostgreSQL и application environment;
2. запусти Telegram worker;
3. используй безопасный single-batch режим либо обычный polling worker;
4. проверь фактическое получение update от реального Telegram.

Если в очереди Telegram уже есть отправленное пользователем сообщение — обработай его.

Если входящих updates сейчас нет, НЕ генерируй сообщение от имени пользователя самостоятельно и НЕ считай это ошибкой реализации.

В таком случае:

- зафиксируй, что `getMe` подтвердил реальный transport;
- worker успешно выполняет `getUpdates`;
- полноценная проверка incoming update требует сообщения пользователя боту.

Если incoming update доступен, подтвердить:

- update получен;
- participant создан/reused;
- incoming message сохранён;
- Telegram identifiers сохранены;
- исходный text сохранён;
- message без open ticket имеет `support_ticket_id = NULL`;
- BotDecision не создан.

Не выводи полный пользовательский payload в диагностический отчёт.

---

# 25. Live unsupported-message smoke-test

Не требуется специально отправлять реальные файлы/фото только ради проверки.

Этот сценарий должен быть покрыт fake automated tests.

Не создавай лишние Telegram messages пользователю при разработке без необходимости.

---

# 26. Telegram send result

Для `sendMessage` transport result должен предоставлять:

при успехе:

- Telegram message id;

при ошибке:

- безопасный error result.

Не возвращай наружу весь raw Telegram response.

Не сохраняй ничего в БД внутри transport client.

Не отправляй произвольное тестовое сообщение реальному пользователю только для проверки `sendMessage`, если это не нужно.

`sendMessage` достаточно полностью покрыть HTTP fake-тестами на этом этапе.

---

# 27. Tests: Telegram client

Используй Laravel HTTP fake.

Минимально протестируй:

1. `getUpdates` отправляет корректный offset;
2. используется long polling timeout;
3. успешный Telegram response преобразуется в transport result;
4. `sendMessage` передаёт chat id и text;
5. successful `sendMessage` возвращает Telegram message id;
6. API error преобразуется в safe transport error;
7. fake bot token не появляется в safe exception/error message;
8. `getMe` корректно обрабатывает successful response;
9. `getMe` error не раскрывает token.

Автоматические тесты не должны обращаться к реальному Telegram API.

---

# 28. Tests: update parsing

Минимально:

1. обычный private text message распознаётся;
2. update id извлекается;
3. chat id извлекается;
4. Telegram message id извлекается;
5. text сохраняется без исправления;
6. нерелевантный update не превращается в пользовательский вопрос;
7. unsupported attachment не направляется в classification;
8. malformed payload не вызывает необработанный exception.

Не моделируй весь Telegram API.

---

# 29. Tests: intake / persistence

На PostgreSQL проверь минимум:

1. первый text нового chat создаёт participant и message;
2. второй text того же chat повторно использует participant;
3. сообщение без open ticket возвращается как ready for classification;
4. duplicate update создаёт только одну message;
5. duplicate update не создаёт второго participant;
6. participant с open ticket получает новое message в этот ticket;
7. при open ticket BotDecision не создаётся;
8. при open ticket SupportTicket не создаётся;
9. оригинальный text сохраняется без модификации;
10. relevant unique violation update корректно интерпретируется как duplicate.

Concurrency guarantee participant/update должна опираться на DB UNIQUE constraints.

Не создавай фальшивые concurrency tests, которые на самом деле выполняются последовательно и ничего не доказывают.

---

# 30. Tests: polling

С fake Telegram client/HTTP layer проверь:

1. batch updates обрабатывается;
2. updates обрабатываются в корректном порядке;
3. следующий offset основан на Telegram update id;
4. duplicate update проходит безопасно;
5. malformed update не останавливает следующий update;
6. temporary transport error не раскрывает token;
7. single-iteration mode завершается;
8. unsupported update не ломает batch.

Не запускай бесконечный worker внутри PHPUnit.

---

# 31. README

Обнови README минимально.

Укажи:

- бот создаётся пользователем через BotFather;
- локальный bot уже использует `TELEGRAM_BOT_TOKEN`;
- token хранится только в `.env`;
- `.env` не коммитится;
- используется long polling;
- поддерживаются text messages;
- Compose service `bot` выполняет polling;
- как запустить worker;
- как выполнить безопасный one-batch diagnostic запуск;
- как проверить Telegram configuration через предусмотренный безопасный механизм, если он добавлен.

Не публикуй фактический username/token, если в этом нет необходимости.

Не описывай LLM как уже работающую.

---

# 32. Docker smoke-test

После реализации реально выполни:

1. `docker compose config --quiet`;
2. build;
3. запуск PostgreSQL;
4. проверку `healthy`;
5. `/up`;
6. загрузку Telegram configuration в bot container;
7. автоматические tests внутри container;
8. безопасный live `getMe`;
9. запуск polling worker / single iteration;
10. если доступен реальный incoming update — проверку записи в PostgreSQL.

При проверке environment нельзя выводить значение `TELEGRAM_BOT_TOKEN`.

Например, вместо:

`env | grep TELEGRAM`

используй проверку факта наличия переменной без вывода значения.

После smoke-test корректно останови контейнеры.

Не удаляй PostgreSQL volume без необходимости.

---

# 33. Code quality

Не создавай:

- God service;
- Telegram-specific бизнес-логику в Artisan command;
- database queries внутри HTTP transport;
- static global Telegram helper;
- Service Locator;
- unnecessary repository layer поверх Eloquent.

Предпочитай небольшие явные классы.

Архитектура должна быть понятна на техническом интервью.

---

# 34. Проверки

После реализации выполни:

- `composer validate --strict`;
- полный Laravel test suite;
- PostgreSQL integration tests;
- Pint;
- `git diff --check`;
- Docker Compose config validation;
- Docker smoke-test;
- Telegram `getMe` с реальным локальным token;
- polling smoke-test.

Сообщай только реально выполненные результаты.

---

# 35. Git

После успешного завершения:

1. убедись, что ветка `task_mvp-tgbot`;
2. просмотри `git status`;
3. просмотри итоговый diff;
4. не включай pre-existing пользовательские changes;
5. выборочно stage только файлы текущего этапа;
6. просмотри `git diff --cached`;
7. создай один логически цельный commit.

Commit message:

- на русском;
- в стиле предыдущих commit;
- описывает Telegram transport/intake;
- без упоминаний Codex/AI/prompt.

Реальный `.env` никогда не добавляй в commit.

`git push` не выполняй.

После commit:

`git status`

`git log -1 --stat`

---

# Критерий готовности

Этап считается завершённым, если:

- Telegram transport изолирован;
- реальный BotFather token подтверждён через безопасный `getMe`;
- long polling command существует;
- Telegram updates разбираются;
- text private message сохраняется в БД;
- participant reuse работает;
- duplicate update безопасен;
- сообщение присоединяется к existing open ticket;
- сообщение без ticket готово к classification;
- bot worker присутствует в Compose;
- secrets не раскрываются;
- Telegram API покрыт fake tests;
- PostgreSQL persistence покрыта integration tests;
- Docker/Laravel проверки зелёные.

Если во время live smoke-test входящих messages нет, это само по себе не блокирует этап при условии, что:

- `getMe` успешен;
- реальный `getUpdates` успешен;
- parsing/intake полностью покрыты automated tests.

Но в финальном отчёте честно укажи, выполнялся ли настоящий end-to-end incoming update.

LLM на этом этапе всё ещё НЕ подключается.

---

# Финальный отчёт

В конце сообщи:

1. какие классы/файлы созданы;
2. как устроен Telegram client abstraction;
3. как работает parsing update;
4. как устроена idempotency;
5. как обрабатывается open ticket;
6. как работает Telegram offset;
7. как защищён token;
8. результат реального `getMe`;
9. был ли фактически получен incoming update реального бота;
10. если был — подтверждено ли сохранение participant/message в PostgreSQL;
11. какие automated tests добавлены;
12. результаты полного test suite;
13. результаты Docker smoke checks;
14. были ли отклонения от architecture/assumptions;
15. hash и сообщение commit;
16. какие файлы вошли в commit;
17. осталось ли что-либо, блокирующее следующий этап LLM classification.

Не переходи к LLM самостоятельно.
