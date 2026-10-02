# Codex Prompt 11 — операторская панель, ответы и статистика

Продолжаем разработку тестового задания M-Social «Вкусная осень».

Это НОВАЯ сессия разработки. Не полагайся на контекст предыдущих чатов — источником истины является текущее состояние репозитория и документация проекта.

Перед любыми изменениями полностью изучи:

- `AGENTS.md`;
- `README.md`;
- `docs/task.md`;
- `docs/architecture.md`;
- `docs/assumptions.md`;
- `docs/database-schema.md`;
- `docs/promo-rules.md`;
- `docs/evaluation-results.md`;
- `docs/evaluation-results-v2.md`;
- `docs/prompt-iterations.md`;
- `prompts/bot/README.md`.

Также изучи фактическую реализацию:

- Eloquent models;
- migrations;
- Telegram transport;
- Telegram intake;
- LLM classification;
- Telegram delivery;
- existing tests.

Текущая рабочая LLM-версия:

`bot-v2`

На этом этапе НЕ создавай `bot-v3` и НЕ меняй LLM prompt.

Результаты `bot-v2` уже зафиксированы отдельно.

---

# 1. Цель этапа

Реализовать обязательную операторскую часть MVP из тестового задания:

1. web-панель оператора;
2. очередь обращений;
3. просмотр истории обращения;
4. ответ оператора участнику;
5. доставка ответа в Telegram;
6. закрытие обращения;
7. статистика:
   - решено ботом;
   - передано оператору;
   - среднее время ответа оператора.

После этапа должен существовать полный рабочий сценарий:

participant
→ Telegram
→ bot
→ escalation
→ support ticket
→ operator panel
→ operator reply
→ Telegram participant
→ close ticket.

Не добавляй функции, которых нет в MVP.

---

# 2. Перед изменениями

Выполни:

`git status`

`git branch --show-current`

`git log -10 --pretty=format:"%h %s"`

Рабочая ветка:

`task_mvp-tgbot`

Не включай существующие пользовательские изменения `docs/agent-prompts/*` в commit текущего этапа.

Не выполняй `git push`.

---

# 3. UI подход

Используй простой server-rendered Laravel UI.

Предпочтительно:

- Blade;
- обычные Laravel routes/controllers;
- минимальный CSS;
- минимум JavaScript.

НЕ устанавливай без необходимости:

- React;
- Vue;
- Livewire;
- Inertia;
- Tailwind build pipeline;
- Bootstrap npm stack;
- admin panel package.

Задача — рабочая операторская панель, а не frontend-проект.

Интерфейс должен выглядеть аккуратно и быть удобен для демонстрации работодателю.

---

# 4. Authentication

Используй существующую таблицу:

`users`.

Все активные пользователи панели считаются операторами.

Не добавляй:

- roles;
- permissions;
- RBAC;
- публичную регистрацию.

Нужны:

- login;
- logout;
- session authentication.

Не использовать внешний auth starter kit только ради двух routes.

Не хранить пароль оператора в Git.

---

# 5. Создание первого оператора

Добавь безопасный и понятный способ создать оператора локально.

Предпочтительно Artisan command, например:

`php artisan operator:create`

Команда должна запросить или принять:

- name;
- email;
- password.

Пароль должен сохраняться через Laravel hashing.

Не выводить hash или пароль в лог.

Не создавать default credentials вроде:

`admin/admin`.

Не создавать публичную регистрацию.

README должен описывать создание первого оператора.

---

# 6. Не менять модель пользователей без необходимости

Текущая модель `users` уже содержит:

- name;
- email;
- password;
- is_active.

Используй её.

Не добавляй `role=operator`, потому что в MVP все пользователи панели имеют одну роль.

Неактивный пользователь:

`is_active = false`

не должен иметь возможность войти в панель.

Если его деактивировали после создания session, защищённые запросы также должны учитывать active status.

---

# 7. Основной layout

Создай простой общий layout панели.

Минимальная навигация:

- Обращения;
- Статистика;
- Выход.

Показывать имя текущего оператора.

Не добавляй ненужные разделы:

- настройки;
- пользователи;
- правила;
- LLM management;
- Telegram management.

---

# 8. Очередь обращений

Главная страница панели должна показывать OPEN tickets.

Очередь должна быть понятной оператору.

Для каждого ticket показать минимум:

- ticket ID;
- время создания;
- время ожидания;
- краткий фрагмент исходного обращения;
- признак, был ли уже ответ оператора;
- ссылку на карточку обращения.

Сортировка:

самые старые открытые обращения первыми.

Не использовать participant Telegram identifiers как основной UI label без необходимости.

Не показывать телефон/карту, если они вдруг встречаются в сообщении, отдельным metadata field.

---

# 9. N+1

При загрузке очереди и истории не создавать очевидный N+1.

Используй Eloquent eager loading там, где это уместно.

Но не создавай repository abstraction только ради query.

---

# 10. Карточка обращения

Отдельная страница ticket должна показывать:

- ticket ID;
- status;
- created_at;
- closed_at, если есть;
- first operator response time, если есть;
- историю сообщений.

История должна быть хронологической.

В неё входят:

1. trigger participant message;
2. bot/system escalation message;
3. последующие participant messages, связанные с ticket;
4. operator replies.

Учитывай ранее зафиксированную особенность schema:

trigger message может иметь:

`support_ticket_id = NULL`

и связан с ticket через:

`support_tickets.trigger_message_id`.

Не теряй trigger message при построении истории.

---

# 11. Отображение авторов

В истории визуально различай:

- Участник;
- Бот;
- Система;
- Оператор.

Для operator message можно показывать имя пользователя.

Не выводить внутренние:

- BotDecision;
- business_reason;
- technical_outcome;
- prompt version;

как основной пользовательский интерфейс оператора.

Допустимо при необходимости оставить минимальный технический блок только если он реально помогает демонстрации, но по умолчанию не нужен.

---

# 12. Оригинальный participant text

Оператор должен видеть оригинальный текст, сохранённый в `messages.body`.

PII redaction применялся только перед LLM и не должен заменять историю оператора.

При выводе использовать безопасное HTML escaping Blade.

Не использовать `{!! !!}` для пользовательских сообщений.

Не интерпретировать Markdown/HTML от participant.

Это защита от XSS.

Добавь regression test с HTML/script-like participant message.

---

# 13. Ответ оператора

На странице открытого ticket должна быть форма ответа.

Оператор вводит текст.

После submit:

1. проверить ticket;
2. убедиться, что он `open`;
3. валидировать непустой разумной длины текст;
4. сохранить operator `Message`;
5. связать:
   - participant;
   - ticket;
   - authenticated operator user;
6. `author_type = operator`;
7. delivery state первоначально `pending`;
8. при первом сохранённом operator response установить:
   `first_operator_response_at`;
9. завершить DB transaction;
10. только ПОСЛЕ commit вызвать Telegram `sendMessage`;
11. записать `sent` либо `failed`.

Не держать database transaction открытой во время Telegram network request.

---

# 14. first_operator_response_at

Очень важно сохранить уже утверждённую метрику:

среднее время ответа считается до первого СОХРАНЁННОГО ответа оператора.

Поэтому:

`first_operator_response_at`

фиксируется при первом успешно сохранённом operator message.

Даже если последующая доставка Telegram завершилась `failed`, время первого ответа оператора уже существует.

Не переписывать timestamp при втором/третьем ответе.

---

# 15. Delivery failure

Если Telegram send ответа оператора завершился ошибкой:

- operator message остаётся в БД;
- `delivery_status = failed`;
- `delivery_error` сохраняется в допустимой безопасной форме;
- ticket остаётся open;
- ответ не исчезает из истории;
- UI показывает оператору, что доставка не удалась.

Не создавать автоматически второй message.

Не выполнять бесконечный retry.

Не закрывать ticket автоматически.

---

# 16. Delivery success

При успешной отправке:

- сохранить Telegram message id;
- delivery status = sent;
- telegram_sent_at заполнен;
- delivery error отсутствует.

Используй существующий Telegram transport / delivery design.

Не создавай второй независимый Telegram client.

---

# 17. Повторный submit

Защити обычный UX от случайного двойного submit настолько, насколько разумно для MVP.

Не нужна distributed idempotency infrastructure.

Но приложение не должно легко создавать два одинаковых ответа из одного двойного POST из-за плохой формы/redirect flow.

Используй стандартный:

POST → redirect → GET.

CSRF обязателен.

Не отключай Laravel CSRF protection.

---

# 18. Закрытие ticket

Для open ticket должна существовать отдельная action:

`Закрыть обращение`.

При закрытии:

- status = closed;
- closed_at = current time;
- операция идемпотентна в разумных пределах.

Не отправляй пользователю автоматическое сообщение о закрытии, поскольку ТЗ этого не требует.

Не удаляй историю.

Не удаляй BotDecision.

Не удаляй messages.

---

# 19. Поведение после закрытия

Это важный end-to-end invariant.

После закрытия ticket следующий новый participant Telegram message:

НЕ должен прикрепляться к закрытому ticket.

Существующий intake должен:

- не найти open ticket;
- вернуть ready_for_classification;
- снова пройти LLM classification.

Не изменяй intake, если это уже автоматически следует из существующей реализации.

Добавь integration test, подтверждающий этот сценарий.

---

# 20. Ответ в закрытый ticket

Нельзя отправлять новый operator reply в ticket со статусом:

`closed`.

UI не должен показывать активную форму ответа либо должен явно блокировать submit.

Backend также обязан проверять status — одной UI-защиты недостаточно.

---

# 21. Concurrent close/reply

Не внедряй сложный locking framework.

Но учитывай возможную гонку:

оператор открыл ticket, другой запрос уже закрыл его, первый отправляет reply.

Backend должен проверять актуальный status в момент операции.

Если ticket уже closed:

не отправлять Telegram message.

Вернуть оператору понятное сообщение.

---

# 22. Статистика

Создай отдельную страницу статистики.

Используй определения, уже зафиксированные в архитектуре.

## Решено ботом

Количество incoming participant messages, для которых:

- существует `BotDecision`;
- `action = answer`;
- `technical_outcome = valid`;
- связанный автоматический outgoing response успешно доставлен:
  `delivery_status = sent`.

Не считать:

- failed Telegram delivery;
- escalation;
- malformed/fail-safe.

Не хранить отдельный counter.

Вычислять из данных.

---

# 23. Передано оператору

Метрика:

количество созданных:

`support_tickets`.

Не считать messages.

Не считать повторные сообщения внутри уже существующего ticket отдельными эскалациями.

---

# 24. Среднее время ответа оператора

Метрика:

для tickets с:

`first_operator_response_at IS NOT NULL`

вычислить среднюю длительность:

`first_operator_response_at - created_at`.

Не включать unanswered tickets как 0.

Не использовать closed_at.

Не использовать время успешной Telegram delivery.

Показывать человекочитаемо.

Например:

- `12 мин 34 сек`;
- `1 ч 08 мин`.

Если отвеченных tickets нет:

показывать:

`—`

или `Нет данных`.

Не делить на ноль.

---

# 25. Период статистики

Согласно текущим assumptions статистика:

ALL-TIME.

Не добавлять date filters, charts и dashboards без необходимости.

Три карточки KPI достаточно:

- Решено ботом;
- Передано оператору;
- Среднее время ответа оператора.

Допускается небольшой поясняющий текст.

---

# 26. Query для статистики

Статистика должна быть рассчитана непосредственно из PostgreSQL/Eloquent.

Не загружай все messages/tickets в PHP и не вычисляй агрегаты в foreach.

Используй aggregate queries.

Не добавляй materialized view или отдельную statistics table.

---

# 27. Authentication tests

Минимально проверить:

- guest перенаправляется на login;
- корректный active user может войти;
- неверный пароль отклоняется;
- inactive user войти не может;
- logout завершает session;
- защищённые routes недоступны после logout.

---

# 28. Queue/history tests

Проверить:

- queue показывает только open tickets;
- closed ticket отсутствует в open queue;
- oldest ticket идёт раньше нового;
- trigger message присутствует;
- последующие ticket messages присутствуют;
- история в хронологическом порядке;
- пользовательский HTML выводится escaped;
- operator name отображается для operator messages.

---

# 29. Operator reply tests

На PostgreSQL + fake Telegram проверить:

1. operator reply создаёт один Message;
2. author_type корректен;
3. operator_user_id корректен;
4. ticket/participant связи корректны;
5. first_operator_response_at устанавливается при первом ответе;
6. второй ответ не меняет first_operator_response_at;
7. Telegram вызывается после persistence;
8. success → delivery sent;
9. failure → delivery failed;
10. failed delivery не удаляет message;
11. reply в closed ticket запрещён;
12. guest reply запрещён.

---

# 30. Close tests

Проверить:

- open → closed;
- closed_at установлен;
- повторный close не портит состояние;
- история остаётся;
- закрытый ticket исчезает из queue;
- новый participant message после закрытия снова идёт в classification path.

В тесте classification можно использовать Fake LLM.

Не вызывать реальный Groq в обычном test suite.

---

# 31. Statistics tests

Создай набор данных, который однозначно доказывает определения метрик.

Проверь:

### bot resolved

Считается только:

valid answer + successful delivery.

Не считаются:

- answer + failed delivery;
- escalation;
- malformed response.

### escalated

Считаются именно tickets.

### average operator response

Например создать tickets с известными интервалами и проверить точное/разумно округлённое среднее.

Не использовать `sleep()` в тестах.

Используй controlled timestamps.

---

# 32. Clock/timezone

Продолжай общий подход проекта.

DB timestamps:

UTC.

UI может отображать даты оператору в бизнес timezone:

`Europe/Moscow`.

Если проект уже имеет единый time formatting abstraction/helper — используй его.

Не создавай вторую конкурирующую систему времени.

Для тестов timestamp задавать детерминированно.

---

# 33. Ошибки UI

Используй понятные flash messages:

- ответ отправлен;
- ответ сохранён, но доставка Telegram не удалась;
- обращение закрыто;
- ticket уже закрыт;
- validation error.

Не показывай пользователю:

- stack trace;
- SQL error;
- Telegram token;
- raw Telegram API response.

---

# 34. Logging

Не логировать полностью:

- participant message;
- operator reply;
- Telegram token;
- Telegram full URL;
- LLM prompt.

Для технической диагностики достаточно IDs и safe error type.

---

# 35. Реальный Telegram smoke-test

Автоматические тесты используют Fake Telegram.

После их успеха разрешается выполнить ОДИН контролируемый live smoke-test operator reply, только если в существующей БД имеется реальный participant/chat от предыдущего Telegram smoke.

Перед реальной отправкой:

НЕ отправляй сообщение пользователю автоматически без явного отдельного разрешения пользователя в текущей сессии.

Поэтому по умолчанию этот этап ограничивается fake Telegram delivery и проверкой готовности live flow.

Не считать отсутствие реальной operator-send проверки дефектом автоматических тестов.

---

# 36. README

Обнови README.

Добавь кратко:

- создание первого оператора;
- login URL;
- очередь обращений;
- ответ участнику;
- закрытие ticket;
- статистика;
- определения трёх метрик.

Не перегружай README внутренними implementation details.

---

# 37. Не менять LLM

На текущем этапе запрещено менять:

- `bot-v2`;
- Groq adapter;
- model;
- prompt iteration reports;
- evaluation results;

если только операторская реализация не выявит настоящий integration bug.

Не выполнять новый 25-case evaluation.

---

# 38. Не добавлять лишнее

Не реализовывать:

- assignment ticket конкретному оператору;
- operator roles;
- comments/notes;
- search;
- filters;
- pagination, если очередь MVP мала;
- SLA alerts;
- WebSocket;
- notifications;
- email;
- attachments;
- audit subsystem;
- ticket priority;
- tags.

Только требования тестового.

---

# 39. Docker smoke

После реализации реально проверить:

- `docker compose config --quiet`;
- image build;
- PostgreSQL healthy;
- migrations;
- application `/up`;
- login page открывается;
- protected operator route требует auth;
- полный test suite внутри Docker;
- PostgreSQL integration suite.

Не нужен отдельный frontend container.

---

# 40. Git

После успешного завершения:

1. убедиться, что ветка `task_mvp-tgbot`;
2. проверить `git status`;
3. просмотреть полный diff;
4. не включать pre-existing `docs/agent-prompts/*`;
5. выборочно stage файлы этапа;
6. проверить `git diff --cached`;
7. создать один осмысленный commit на русском.

Commit message должен описывать:

операторскую панель, ответы и статистику.

Не упоминать Codex/AI-agent.

`git push` не выполнять.

После commit:

`git status`

`git log -1 --stat`

---

# Критерий готовности

Этап считается завершённым, если:

- оператор может войти;
- видит очередь open tickets;
- открывает ticket;
- видит полную историю;
- пишет ответ;
- ответ сохраняется;
- Telegram delivery корректно отражается;
- first_operator_response_at работает;
- operator может закрыть ticket;
- closed ticket исчезает из очереди;
- новый participant message после закрытия снова проходит bot classification;
- статистика соответствует утверждённым формулам;
- UI безопасно выводит пользовательский текст;
- тесты проходят;
- Docker environment работает.

---

# Финальный отчёт

В конце сообщи:

1. какие routes/pages добавлены;
2. как устроена authentication;
3. как создаётся первый operator;
4. как строится ticket queue;
5. как собирается history;
6. как работает operator reply;
7. когда устанавливается first_operator_response_at;
8. как обрабатывается Telegram delivery failure;
9. как работает close;
10. как подтверждён новый classification после закрытия;
11. SQL/Eloquent определения трёх статистик;
12. какие security/XSS/CSRF проверки добавлены;
13. результаты test suite;
14. результаты Docker smoke;
15. были ли изменения DB schema;
16. hash и сообщение commit;
17. основные файлы commit;
18. осталось ли что-либо из обязательного ТЗ, что ещё не реализовано.

Не переходи к следующему этапу самостоятельно.
