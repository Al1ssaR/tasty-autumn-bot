# Codex Prompt 13 — UX оператора, метрика ответа и bot-v3 routing

Продолжаем разработку MVP «Вкусная осень».

После ручной проверки работающего приложения обнаружены три продуктовые проблемы, которые необходимо исправить до сдачи.

Это не общий refactoring. Работай строго над указанными проблемами.

Перед изменениями изучи:

- `AGENTS.md`;
- `docs/task.md`;
- `docs/architecture.md`;
- `docs/assumptions.md`;
- `docs/database-schema.md`;
- `docs/evaluation-results-v2.md`;
- `docs/prompt-iterations.md`;
- текущий `prompts/bot/system.md`;
- LLM structured contract;
- `MessageClassificationService`;
- Telegram delivery;
- operator reply/close services;
- `SupportStatistics`;
- PostgreSQL migrations и constraints;
- соответствующие tests.

Также:

`git status`

`git branch --show-current`

`git log -10 --pretty=format:"%h %s"`

Рабочая ветка:

`task_mvp-tgbot`

Не выполнять `git push`.

---

# 1. Проблема: среднее время ответа оператора показывает лишние 3 часа

Продуктовое определение метрики НЕ меняется.

Среднее время ответа оператора должно считаться:

ОТ момента создания обращения:

`support_tickets.created_at`

ДО первого сохранённого ответа оператора:

`support_tickets.first_operator_response_at`.

Пример:

- ticket created_at: 10:00;
- participant написал ещё одно сообщение: 10:05;
- operator first response: 10:10.

Response time:

`10 минут`.

Последующие participant messages НЕ должны сбрасывать или изменять начало отсчёта.

Причина:

ticket уже находится в очереди оператора с момента его создания, поэтому именно `created_at` является началом ожидания ответа.

---

# 2. Исправить +3 часа

При ручной проверке UI среднее время ответа отображается примерно на 3 часа больше реальной длительности.

Исследуй фактическую причину.

Не исправляй её через:

`-3 hours`

или другой hardcoded timezone offset.

Database timestamps остаются:

- UTC;
- PostgreSQL `timestamptz`.

Business timezone:

`Europe/Moscow`.

ВАЖНО:

response time является ДЛИТЕЛЬНОСТЬЮ, а не временем суток.

Timezone conversion не должна влиять на разницу между двумя моментами времени.

Пример:

ticket:

`10:00 UTC`

operator response:

`10:05 UTC`

Результат:

`5 минут`.

Те же моменты в Москве:

`13:00 Europe/Moscow`

и:

`13:05 Europe/Moscow`

Результат всё равно:

`5 минут`.

Не должно получаться:

`3 часа 5 минут`.

---

# 3. Проверить фактическую причину

Проверь:

- SQL/Eloquent query статистики;
- casts timestamps;
- Carbon operations;
- преобразование UTC → Europe/Moscow;
- formatter duration;
- presentation layer.

Особенно проверь, не происходит ли ошибка вида:

duration в секундах/минутах
→ преобразуется в datetime
→ к ней применяется timezone.

Duration нельзя форматировать как timestamp/date.

Правильный принцип:

`first_operator_response_at - ticket.created_at`

→ количество секунд/минут

→ human-readable duration.

Timezone может использоваться для отображения абсолютных дат в UI, но не должна изменять интервал.

---

# 4. Regression tests response time

Добавь тесты минимум:

### 5 минут

- ticket created_at = T;
- first_operator_response_at = T + 5 min;
- отображение/расчёт = 5 минут.

### Больше часа

Например:

- 1 час 8 минут.

Результат не должен превращаться в:

- 4 часа 8 минут.

### Последующее сообщение участника

- ticket created_at = 10:00;
- participant message = 10:07;
- operator response = 10:10.

Метрика:

`10 минут`.

Не:

`3 минуты`.

### Несколько tickets

Среднее должно корректно рассчитываться именно из:

`first_operator_response_at - created_at`

для каждого отвеченного ticket.

### Unanswered

Ticket с:

`first_operator_response_at = NULL`

не участвует в average.

---

# 5. Обновить документацию статистики

Проверь:

- README;
- `docs/assumptions.md`;
- `docs/architecture.md`;
- `docs/submission-checklist.md`.

Формула должна оставаться:

`ticket.created_at → first_operator_response_at`.

Если где-либо после прошлых изменений появилась другая формулировка — исправь её.

Не менять historic evaluation artifacts.

---

# 6. Проблема: пользователь не понимает, что отвечает оператор

Telegram bot технически является одним отправителем.

Поэтому обычный operator reply визуально выглядит как очередное сообщение бота.

При доставке operator reply пользователю Telegram-текст должен явно показывать, что отвечает человек.

Используй presentation prefix по смыслу:

`Оператор: <текст оператора>`

Хранимый:

`messages.body`

должен оставаться оригинальным текстом оператора БЕЗ prefix.

Prefix — delivery/presentation concern.

Не модифицируй operator body в БД.

Не добавляй имя конкретного сотрудника без необходимости.

---

# 7. Первый ответ оператора

Не требуется отправлять отдельное сообщение:

`Оператор подключился`.

Prefix:

`Оператор:`

уже достаточно явно показывает пользователю переход к человеку.

Не создавай дополнительный Telegram noise.

---

# 8. Проблема: закрытие обращения незаметно пользователю

При закрытии open ticket приложение должно создать отдельное system outgoing message и попытаться доставить его пользователю.

Текст должен быть application-owned, а не LLM-generated.

Используй короткую естественную формулировку по смыслу:

`Обращение закрыто. Если у вас появится новый вопрос, просто напишите сюда.`

Можно минимально улучшить формулировку.

Не обещай SLA.

---

# 9. Persistence close notification

При закрытии:

1. в DB transaction:
   - ticket → closed;
   - установить `closed_at`;
   - создать system outgoing `Message`;
   - delivery status = pending;
2. commit transaction;
3. только затем Telegram send;
4. обновить delivery:
   - sent;
   - failed.

Не держать database transaction во время Telegram network call.

Если доставка close notification failed:

- ticket остаётся closed;
- system message остаётся;
- delivery status = failed;
- история не теряется.

Не откатывать закрытие ticket из-за Telegram failure.

---

# 10. Повторное закрытие

Close остаётся идемпотентным.

Повторный close:

- не создаёт второй close notification;
- не отправляет второе Telegram message;
- не меняет исходный `closed_at`.

Добавь regression test.

---

# 11. Новый вопрос после закрытия

Сохрани существующий invariant:

после закрытия следующий participant message снова проходит normal bot classification.

Close notification не должна менять это поведение.

---

# 12. Проблема: бот слишком быстро вызывает оператора

После ручного использования обнаружено:

текущая схема:

`не могу ответить → escalation`

создаёт лишние support tickets для сообщений, которые оператору не нужны.

Общие классы:

- приветствия;
- small talk;
- бессвязный текст;
- вопросы вне акции;
- просьбы общего характера, не относящиеся к акции;
- prompt injection;
- административные команды, которые бот выполнить не может.

Это нужно исправить архитектурно, а не keyword/regex hacks.

---

# 13. Новый classification action

Добавь третий structured action:

`respond_static`.

Итоговый набор:

- `answer`;
- `escalate`;
- `respond_static`.

Названия в коде могут отличаться только при сильной архитектурной причине, но semantic separation должна сохраниться.

---

# 14. Reasons

Сохрани:

- `grounded_in_rules`;
- `participant_data_required`;
- `missing_rule`;
- `insufficient_context`;
- `unsafe_request`.

Добавь:

`out_of_scope`.

Новая семантика:

## answer

Только:

`grounded_in_rules`.

Ответ формируется LLM из правил.

Ticket не создаётся.

---

## escalate

Только:

- `participant_data_required`;
- `missing_rule`.

### participant_data_required

Правильный ответ требует реального статуса/операции/данных конкретного участника.

### missing_rule

Вопрос относится к поддержке акции, но нужного ответа нет в rules и такой вопрос действительно имеет смысл передать оператору.

Создаётся SupportTicket.

---

## respond_static

Используется для:

- `insufficient_context`;
- `out_of_scope`;
- `unsafe_request`.

SupportTicket НЕ создаётся.

Свободный generated answer модели для этих причин НЕ использовать.

Ответ выбирается application layer.

---

# 15. out_of_scope

Использовать для запросов, которые не относятся к поддержке акции.

Общие категории:

- рецепт;
- погода;
- unrelated knowledge;
- small talk;
- развлекательный запрос.

Не hardcode конкретные evaluation strings.

Application-owned ответ по смыслу:

`Я могу помочь с вопросами об акции «Вкусная осень». Задайте вопрос об участии, чеках, розыгрышах или призах.`

Формулировку можно сделать естественнее.

Ticket не создаётся.

---

# 16. insufficient_context

Использовать, когда невозможно понять полезный вопрос.

Например:

- бессвязный текст;
- непонятная короткая фраза;
- недостаточно контекста, чтобы определить, что именно пользователь спрашивает.

Application-owned ответ по смыслу:

`Не совсем понял вопрос. Уточните, пожалуйста, что вы хотите узнать об акции.`

Ticket не создаётся.

Не использовать `insufficient_context` вместо нормального `missing_rule`.

---

# 17. unsafe_request

Prompt injection, запрос system prompt, попытка административной операции и аналогичные security-запросы:

`respond_static / unsafe_request`

а НЕ operator escalation.

Operator queue не должна засоряться попытками prompt injection.

Application-owned safe response сохраняется.

Ticket не создаётся.

Не раскрывать security policy.

---

# 18. missing_rule остаётся escalation

Не уйти в другую крайность.

Если пользователь задаёт нормальный вопрос ПО АКЦИИ, но rules ответа не содержат:

`escalate / missing_rule`.

Отсутствие ответа в knowledge source не означает автоматически:

`out_of_scope`.

Сначала определить, находится ли вопрос в домене поддержки акции.

---

# 19. participant_data_required остаётся escalation

Запросы:

- статус конкретного чека;
- причина отклонения;
- статус конкретного приза;
- доставка;
- изменение аккаунта;
- участие конкретного чека;

продолжают:

`escalate / participant_data_required`.

Не ухудшать этот flow.

---

# 20. Greetings

Приветствие без вопроса не должно создавать ticket.

Допустима классификация:

`respond_static / out_of_scope`

либо другой обобщаемый вариант в рамках утверждённого static routing.

Ответ по смыслу:

`Здравствуйте! Я помогу с вопросами об акции «Вкусная осень». Что вы хотите узнать?`

Не добавлять:

`if text === "привет"`.

Решение должно оставаться LLM-classified.

---

# 21. Structured schema

Обнови JSON Schema минимально.

`action`:

- answer;
- escalate;
- respond_static.

`reason`:

добавить:

- out_of_scope.

Сохрани Groq strict-compatible subset.

Не возвращай unsupported:

- allOf;
- if;
- then;
- else.

Application validator остаётся обязательным вторым уровнем.

---

# 22. Database migration

Текущий CHECK для:

`bot_decisions.action`

вероятно разрешает только:

- answer;
- escalate.

Создай НОВУЮ migration.

Не редактируй историческую migration задним числом.

Добавь:

`respond_static`.

Business reason constraint должен разрешать:

`out_of_scope`.

Старые записи v1/v2 должны остаться валидны.

---

# 23. Semantic invariants

Новые business combinations:

`answer`
→ `grounded_in_rules`

`escalate`
→ `participant_data_required | missing_rule`

`respond_static`
→ `insufficient_context | out_of_scope | unsafe_request`

Technical fail-safe НЕ менять:

- timeout;
- malformed;
- api failure;
- application failure

→ `escalate`
→ `business_reason = NULL`
→ SupportTicket.

Причина:

при технической ошибке нормальный вопрос пользователя не должен потеряться.

---

# 24. Static response persistence

Для `respond_static`:

1. создать BotDecision;
2. SupportTicket НЕ создавать;
3. создать outgoing message;
4. связать с decision;
5. отправить Telegram;
6. обновить delivery state.

Provider/model/prompt version/rules hash сохраняются как обычно.

---

# 25. Статистика bot resolved

Продуктовое решение:

успешный `respond_static` считается самостоятельно обработанным ботом запросом.

Следовательно:

`Решено ботом`

считает:

valid `answer`

ИЛИ

valid `respond_static`

при successful outgoing delivery.

Не считать:

- failed delivery;
- escalation;
- technical fail-safe.

---

# 26. Среднее время оператора в статистике

Ещё раз зафиксировать окончательную формулу:

Для каждого отвеченного ticket:

`first_operator_response_at - ticket.created_at`.

Average:

среднее этой duration по отвеченным tickets.

Не использовать:

- last participant message;
- Telegram delivery timestamp;
- closed_at.

Не включать unanswered tickets.

Timezone не должна добавлять смещение в duration.

---

# 27. bot-v3

Изменение routing contract является существенной продуктовой итерацией.

Создай:

`bot-v3`.

Не переписывай v2.

Убедись, что сохранён:

`prompts/bot/versions/bot-v2.md`.

Создай:

`prompts/bot/versions/bot-v3.md`.

Текущий:

`prompts/bot/system.md`

становится bot-v3.

В `docs/prompt-iterations.md` зафиксируй реальную причину:

ручная UX-проверка выявила избыточную эскалацию сообщений, не требующих участия оператора.

Не писать, что v3 создавался ради повышения evaluation score.

---

# 28. Evaluation oracle

Не менять v1/v2 artifacts.

Для v3 product semantics намеренно изменилась.

Допустимо обновить v3 expected mapping только для cases, где новое routing-решение действительно меняет ожидаемое поведение.

Особенно:

- out-of-scope;
- unsafe.

Каждое изменение expected behavior явно перечислить в v3 report.

Не скрывать изменение oracle как улучшение score.

---

# 29. Дополнительный routing regression dataset

Добавь небольшой отдельный generalization dataset.

Примерно 8–12 случаев:

- greeting;
- small talk;
- unrelated question;
- gibberish;
- unclear promo question;
- missing-rule promo question;
- participant-specific request;
- prompt injection.

Не использовать этот dataset в production code.

Цель:

проверить уменьшение ненужных operator tickets.

---

# 30. Routing tests

Проверить:

`answer / grounded`
→ no ticket.

`escalate / participant`
→ ticket.

`escalate / missing_rule`
→ ticket.

`respond_static / out_of_scope`
→ no ticket.

`respond_static / insufficient_context`
→ no ticket.

`respond_static / unsafe_request`
→ no ticket.

technical timeout
→ ticket.

technical malformed
→ ticket.

---

# 31. Operator UX tests

Проверить:

- operator body в DB не содержит prefix;
- Telegram operator delivery содержит `Оператор:`;
- несколько operator replies форматируются корректно;
- close создаёт ровно одно system notification;
- Telegram send close notification происходит после commit;
- failed close delivery не открывает ticket обратно;
- duplicate close не создаёт duplicate notification.

---

# 32. Statistics tests

Проверить минимум:

- created_at 10:00 / response 10:05 → 5 минут;
- participant пишет в 10:04 → результат всё равно 5 минут;
- duration > 1h;
- timezone не добавляет +3h;
- unanswered ticket исключается;
- successful respond_static считается bot resolved;
- failed respond_static не считается.

---

# 33. Real Groq

Сначала полностью реализуй локальный bot-v3 и выполни automated/fake tests.

Live Groq НЕ вызывай автоматически.

Перед:

- bot-v3 smoke;
- full evaluation;
- routing generalization live evaluation

остановись и запроси отдельное прямое разрешение пользователя.

---

# 34. Ручная проверка пользователем

Пользователь хочет самостоятельно проверить готовый MVP.

Добавь в README или отдельный короткий manual-testing section сценарий:

1. запустить Docker;
2. войти оператором;
3. отправить FAQ;
4. получить bot answer;
5. отправить `привет`;
6. убедиться, что ticket не появился;
7. отправить бессвязное/out-of-scope сообщение;
8. убедиться, что ticket не появился;
9. отправить participant-specific вопрос;
10. увидеть ticket;
11. ответить из панели;
12. получить Telegram message с `Оператор:`;
13. закрыть ticket;
14. получить Telegram уведомление о закрытии;
15. написать новый вопрос;
16. убедиться, что он снова классифицируется ботом;
17. посмотреть статистику;
18. проверить разумное average response time без +3 часов.

Не автоматизируй действия реального пользователя.

---

# 35. Проверки перед live Groq

Выполни:

- `composer validate --strict`;
- PostgreSQL test suite;
- migration fresh;
- rollback;
- reapply;
- Pint;
- PHP syntax check;
- `git diff --check`;
- `docker compose config --quiet`;
- Docker smoke;
- operator UI tests.

Не выполнять live Groq.

---

# 36. Git

Не создавай итоговый commit до завершения bot-v3 live evaluation, если evaluation входит в текущий этап.

До разрешения допустимо оставить локальные изменения незакоммиченными.

После live evaluation:

- проверить v1/v2 artifacts;
- убедиться, что они не изменены;
- добавить v3 implementation/results;
- создать один осмысленный commit на русском;
- не выполнять push.

---

# Отчёт перед live Groq

После локальной реализации остановись и сообщи:

1. точную причину ошибки +3 часа;
2. как исправлена duration;
3. подтверждение формулы:
   `created_at → first_operator_response_at`;
4. результаты regression tests времени;
5. как реализован `Оператор:` prefix;
6. как реализовано уведомление о закрытии;
7. как работает idempotent close;
8. новую action/reason semantics;
9. migration changes;
10. влияние `respond_static` на статистику;
11. количество routing tests;
12. готовность bot-v3;
13. подтверждение, что live Groq ещё НЕ вызывался.

После этого запроси отдельное разрешение на bot-v3 live smoke и evaluation.

Не продолжай с Groq без явного разрешения.
