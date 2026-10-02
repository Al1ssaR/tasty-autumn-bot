# «Вкусная осень» — AI Support MVP

MVP системы поддержки участников промоакции M-Social. Проект содержит Laravel 13, PostgreSQL 17, Docker Compose, Telegram-контур на long polling, provider-neutral LLM-слой с concrete Groq adapter и server-rendered операторскую панель. Новое личное текстовое сообщение проходит PII redaction, классификацию по полному документу правил и строгую проверку structured result. Затем бот отвечает по правилам, даёт application-owned static response без ticket либо передаёт действительно требующий человека вопрос оператору.

## Архитектура

Основной поток MVP:

`Telegram → long polling intake → PII redaction → LLM classification → answer / respond_static / escalation → PostgreSQL → operator panel → Telegram reply`.

Приложение является одним Laravel-монолитом с отдельными Compose-процессами web-приложения и Telegram worker. LLM не имеет доступа к БД, Telegram API или административным операциям: оно возвращает только структурированное предложение, которое валидирует application layer. Полное описание границ и отказоустойчивости находится в [`docs/architecture.md`](docs/architecture.md), а фактическая PostgreSQL-схема — в [`docs/database-schema.md`](docs/database-schema.md).

## Требования

- Docker с поддержкой Docker Compose;
- свободный порт `8000`.

PHP и Composer на хосте не требуются.

## Первый запуск

1. Создайте локальный файл окружения:

   ```bash
   cp .env.example .env
   ```

   В PowerShell используйте `Copy-Item .env.example .env`.

2. Соберите образ приложения:

   ```bash
   docker compose build app
   ```

3. Создайте уникальный `APP_KEY` в локальном `.env`:

   ```bash
   docker compose run --rm --no-deps --user root app php artisan key:generate
   ```

   Ключ записывается только в `.env`. Этот файл исключён из Git и Docker-образа.

4. Создайте бота через BotFather и укажите полученный token только в локальном `.env`:

   ```dotenv
   TELEGRAM_BOT_TOKEN=
   ```

   Значение token не должно попадать в Git, README, логи или команды. `.env.example` содержит только пустой placeholder.

5. Создайте API key в Groq Console и укажите его только в локальном `.env`:

   ```dotenv
   LLM_PROVIDER=groq
   LLM_MODEL=openai/gpt-oss-120b
   LLM_API_KEY=
   LLM_BASE_URL=https://api.groq.com/openai/v1
   LLM_TIMEOUT=30
   LLM_REASONING_EFFORT=medium
   ```

   На момент разработки для проверки использовался доступный Groq free-tier. Его доступность и лимиты могут измениться; проект не обещает постоянную бесплатность.

6. Запустите приложение, Telegram worker и PostgreSQL:

   ```bash
   docker compose up --build
   ```

7. В отдельном терминале создайте первого оператора. Имя и email можно передать опциями, пароль команда всегда запрашивает скрыто и сохраняет только как Laravel hash:

   ```bash
   docker compose exec app php artisan operator:create
   ```

   Публичной регистрации и пароля по умолчанию нет. Для неинтерактивной передачи имени и email доступны `--name` и `--email`; пароль намеренно не принимается через command-line option, чтобы не оставлять его в shell history и списке процессов.

PostgreSQL проверяется через healthcheck. Laravel запускает миграции только после готовности базы данных. Compose-сервис `bot` ждёт готовности web-приложения после миграций и затем запускает `telegram:poll`.

Последующие запуски не требуют пересборки:

```bash
docker compose up
```

## Адреса

- операторская панель: <http://localhost:8000>;
- форма входа: <http://localhost:8000/login>;
- health endpoint Laravel: <http://localhost:8000/up>.

## Операторская панель

Панель использует Laravel session authentication и существующую таблицу `users`. Все активные пользователи являются операторами; roles, permissions и публичной регистрации нет. `is_active=false` запрещает новый вход и завершает уже существующую сессию при следующем защищённом запросе.

Очередь показывает только открытые обращения, от старых к новым. Карточка собирает trigger-сообщение и все связанные сообщения в хронологическом порядке, включая последующие сообщения участника, системную эскалацию и ответы операторов. Исходный пользовательский текст выводится с HTML escaping и не интерпретируется как Markdown или HTML.

Ответ оператора сначала сохраняется в короткой PostgreSQL-транзакции. Там же один раз фиксируется `first_operator_response_at`; затем, уже после commit, существующий Telegram delivery service выполняет `sendMessage`. При отправке приложение добавляет presentation-prefix `Оператор:`, но в `messages.body` хранится исходный текст без prefix. При ошибке сообщение остаётся в истории со статусом `failed`, а обращение остаётся открытым.

Закрытие — отдельное идемпотентное действие. В одной транзакции ticket закрывается и создаётся системное исходящее сообщение; после commit оно отправляется участнику. Ошибка Telegram не откатывает закрытие и сохраняется как `failed` delivery. Повторное закрытие не меняет `closed_at` и не создаёт второе уведомление. После закрытия следующий participant message не связывается со старым ticket и снова проходит LLM-классификацию.

Страница статистики вычисляет по сохранённым данным три all-time показателя:

- успешно доставленные валидные ответы бота;
- число созданных обращений оператору;
- среднее время `support_tickets.created_at → first_operator_response_at` только для отвеченных обращений. Интервал форматируется как длительность и не проходит timezone-преобразование; последующие сообщения участника не меняют начало отсчёта.

## Telegram worker

Бот использует long polling и обрабатывает только обычные текстовые сообщения в private chat. Фото, документы, voice, video и sticker не скачиваются и получают статическое предложение отправить вопрос текстом. Другие типы updates игнорируются.

При обычном запуске `docker compose up` polling выполняет сервис `bot`. Для одного token должен работать только один polling worker.

Безопасно проверить credentials через `getMe`, не выводя token:

```bash
docker compose run --rm --no-deps bot php artisan telegram:check
```

Выполнить один batch `getUpdates` и завершить процесс:

```bash
docker compose run --rm bot php artisan telegram:poll --once
```

HTTP timeout должен быть больше `TELEGRAM_LONG_POLL_TIMEOUT`; worker проверяет это перед запуском. Offset хранится только в памяти процесса, а повторно доставленные updates обезвреживаются unique constraint по `telegram_update_id`.

## LLM-слой

Runtime использует prompt версии `bot-v3` из `prompts/bot/system.md` и строгий контракт `prompts/bot/response-schema.json`. Версии `bot-v1` и `bot-v2` сохранены без изменений, а `prompts/bot/versions/bot-v3.md` совпадает с текущим runtime prompt. Полный `docs/promo-rules.md`, текущее московское время и redacted-копия пользовательского сообщения передаются отдельно. SHA-256 точных bytes правил сохраняется вместе с решением.

Перед LLM маскируются распространённые российские номера телефонов и вероятные номера банковских карт. Оригинальный текст остаётся в истории PostgreSQL, а redacted-копия не сохраняется. Это ограниченная защита для явно распознаваемых форматов, а не универсальный DLP.

При `LLM_PROVIDER=groq` приложение использует Laravel HTTP Client и endpoint `{LLM_BASE_URL}/chat/completions`; SDK и сторонняя LLM abstraction library не устанавливаются. Model всегда берётся из `LLM_MODEL`, fallback на другую модель или provider отсутствует. Runtime-запрос использует `reasoning_effort=medium`, не задаёт `temperature` и `top_p`, не включает tools, raw reasoning или streaming. Контракт передаётся как `response_format=json_schema` с `strict=true`.

Для неизвестного provider production-binding не подменяет конфигурацию Groq или Fake client: применяется существующий безопасный unavailable-client и техническая fail-safe эскалация. HTTP/provider error, включая 401/403, 429 и 5xx, становится `api_failure`; timeout — `timeout`; отсутствующий или некорректный assistant content — `malformed_response`. Публичные ошибки не содержат API key, Authorization header, provider body или полный request URL.

При валидном `answer` приложение сохраняет grounded ответ без ticket. `respond_static` используется только для `insufficient_context`, `out_of_scope` и `unsafe_request`: ticket не создаётся, свободный текст модели отбрасывается, а приложение отправляет собственную формулировку. Валидные `participant_data_required` и `missing_rule`, а также malformed result, timeout или API/application failure создают обращение. Таким образом, нормальный вопрос по акции без правила не теряется, но приветствия, посторонние запросы и prompt injection не засоряют очередь.

## Groq smoke-test и evaluation

Один безопасный live smoke на исходном обращении №1 выполняется отдельно от PHPUnit и ничего не отправляет в Telegram:

```bash
docker compose run --rm --no-deps app php artisan bot:evaluate --case=1 --no-report --max-attempts=1
```

Полный evaluation читает тексты непосредственно из `docs/requests.md`, выполняет все 25 cases строго последовательно и использует frozen time `2026-09-30T12:00:00+03:00`. Для `bot-v3` expected mapping хранится отдельно в `evaluation/expected-v3.php` и не используется production-классификацией:

```bash
docker compose run --rm --no-deps --user 0:0 -v "${PWD}/evaluation:/var/www/html/evaluation" -v "${PWD}/docs:/var/www/html/docs" app php artisan bot:evaluate
```

Bind mounts сохраняют отчёты из одноразового контейнера в рабочую копию; `--user 0:0` используется только этой CLI-командой для записи host artifacts и не меняет пользователя runtime-контейнеров `app`/`bot`.

Отдельный generalization dataset из 10 сообщений проверяет greeting, small talk, unrelated question, gibberish, unclear promo question, missing-rule promo question, participant-specific request, prompt injection, grounded FAQ и administrative command:

```bash
docker compose run --rm --no-deps app php artisan bot:evaluate-routing
```

При 429 runner учитывает `Retry-After`, ждёт не более 30 секунд за одну попытку и делает максимум три попытки case. Production classification не повторяет внешний вызов бесконечно и сразу использует fail-safe. Raw provider responses и secrets в artifacts не сохраняются; обращение №22 публикуется только с `[CARD_REDACTED]`.

Артефакты версионированы и не перезаписывают предыдущий прогон:

- `bot-v1`: `docs/evaluation-results.md` и `evaluation/results.json`;
- `bot-v2`: [`docs/evaluation-results-v2.md`](docs/evaluation-results-v2.md) и `evaluation/results-v2.json`;
- `bot-v3`: [`docs/evaluation-results-v3.md`](docs/evaluation-results-v3.md) и `evaluation/results-v3.json`; отдельный routing-прогон зафиксирован в [`docs/routing-evaluation-v3.md`](docs/routing-evaluation-v3.md).

История одной контролируемой итерации и наблюдаемые изменения поведения описаны в `docs/prompt-iterations.md`.

Application-level live smoke использует real Groq, настоящий classification orchestration, rollback и fake Telegram transport. Команда намеренно разрешена только для изолированной базы с суффиксом `_test`:

```bash
docker compose run --rm --no-deps -e DB_DATABASE=tasty_autumn_llm_test app php artisan bot:llm-application-smoke
```

`bot-v1` является неизменяемой baseline-версией. Зафиксированный baseline дал 16 из 25 точных совпадений `action + reason`, 4 технических сбоя и 11 из 25 строгих ручных PASS по содержанию. Контролируемый прогон `bot-v2` дал соответственно 20 из 25, 2 и 17 из 25. Прогон `bot-v3` дал 21 из 25 exact, 1 technical failure и 19 из 25 manual PASS; эти числа напрямую не являются score-улучшением относительно v2, потому что у №23–25 expected behavior намеренно изменён вместе с продуктовой семантикой. Отдельный routing dataset дал 8/10 exact и один technical failure.

## Ручная проверка MVP

1. Запустить проект командой `docker compose up`.
2. Войти в операторскую панель.
3. Отправить боту FAQ по правилам акции.
4. Убедиться, что пришёл содержательный bot answer.
5. Отправить `привет`.
6. Убедиться, что ответ пришёл, но новый ticket в панели не появился.
7. Отправить бессвязное или не относящееся к акции сообщение.
8. Убедиться, что ticket также не появился.
9. Отправить вопрос о статусе конкретного чека, приза или доставки.
10. Увидеть новый ticket в очереди.
11. Ответить участнику из панели.
12. Убедиться, что Telegram-сообщение начинается с `Оператор:`.
13. Закрыть ticket.
14. Получить отдельное Telegram-уведомление о закрытии.
15. Написать новый вопрос после закрытия.
16. Убедиться, что он снова проходит обычную bot classification.
17. Открыть страницу статистики.
18. Проверить, что среднее время ответа выглядит как длительность без лишних трёх часов.

## База данных

Приложение подключается к сервису `postgres` по настройкам `DB_*` из `.env`. Данные PostgreSQL сохраняются в Docker volume `postgres-data`. Пароль в `.env.example` предназначен только для локальной разработки; перед любым внешним развёртыванием его необходимо заменить.

Все timestamps приложения и базы хранятся в UTC. В интерфейсе оператора время показывается в `Europe/Moscow`; LLM также получает московское время явно.

## Допущения и границы MVP

Все решения, которых не было в исходном ТЗ, собраны в [`docs/assumptions.md`](docs/assumptions.md). Ключевые: long polling вместо webhook, только текстовые private messages, максимум одно открытое обращение участника, общая очередь без assignment, all-time статистика и отсутствие интеграции с данными сайта акции.

## Известные ограничения

- Нет интеграции с личным кабинетом акции: оператор проверяет конкретный чек, выигрыш или доставку во внешнем процессе.
- Одновременно у участника может быть только одно открытое обращение; новый вопрос присоединяется к нему до закрытия.
- Telegram работает через long polling; webhook и горизонтальное масштабирование worker не реализованы.
- Telegram-доставка синхронная, без автоматического retry и без отдельной истории попыток. Failed-ответ виден оператору, но кнопка ручной повторной отправки не входит в текущий MVP.
- Все операторы используют одну общую очередь. Нет assignment, SLA, фильтров статистики по периоду, ролей, MFA/SSO и административного UI управления операторами.
- Support history не удаляется автоматически: production retention policy не определена.
- Поддерживаются только текстовые private messages; вложения не сохраняются и не анализируются.
- Использованный Groq free-tier не гарантирует стабильную доступность, latency и отсутствие rate limits.
- `bot-v2` дал 17/25 строгих содержательных PASS; `bot-v3` меняет routing semantics, но не устраняет автоматически все зафиксированные calendar/grounding риски содержательных ответов.

## Что улучшить для production

- перейти на webhook за HTTPS/reverse proxy либо спроектировать управляемое масштабирование polling;
- вынести Telegram delivery в надёжную очередь/outbox с ограниченными retry и backoff;
- добавить observability, безопасный аудит действий и мониторинг внешних интеграций;
- хранить secrets в менеджере секретов, усилить session/CSRF-политику под выбранную среду и добавить rate limiting входа;
- согласовать retention/access policy, резервное копирование и восстановление PostgreSQL;
- добавить SSO/MFA и управление операторами;
- выбрать LLM-провайдера с подходящим SLA и продолжить prompt/model evaluation;
- продолжить работу над зафиксированными calendar/grounding дефектами содержательных ответов до production-запуска.

Эти улучшения не заявлены как реализованные и не требуются для локального MVP.

## AI-assisted разработка

Проект разрабатывался через Codex. Правила работы агента находятся в [`AGENTS.md`](AGENTS.md), а сохранённые реальные постановки этапов — в [`docs/agent-prompts/`](docs/agent-prompts/README.md). Это development prompts; runtime prompt бота хранится отдельно в [`prompts/bot/`](prompts/bot/README.md).

Поддерживаемого экспорта истории сессий из текущего интерфейса Codex в этой среде нет, поэтому fake transcripts не создавались. В [`docs/agent-sessions/README.md`](docs/agent-sessions/README.md) описано, какие 2–3 настоящих экспорта пользователь должен добавить перед передачей репозитория, если этот deliverable проверяется отдельно. `CLAUDE.md` отсутствует намеренно: Claude Code не использовался.

Сводное соответствие исходному заданию находится в [`docs/submission-checklist.md`](docs/submission-checklist.md).

## Проверки

```bash
docker compose exec app php artisan test
docker compose exec app php artisan about
```

Команда `php artisan about` показывает активное подключение к базе данных. Для явной проверки соединения можно выполнить:

```bash
docker compose exec app php artisan db:show --database=pgsql
```

Тесты ограничений предметной модели необходимо запускать на PostgreSQL. Для изоляции используется отдельная временная база; основная локальная база и её данные не изменяются:

```bash
docker compose up -d postgres
docker compose exec postgres createdb -U tasty_autumn tasty_autumn_model_test
docker compose run --rm --no-deps -e DB_DATABASE=tasty_autumn_model_test app php artisan migrate:fresh --force
docker compose run --rm --no-deps -e DB_CONNECTION=pgsql -e DB_DATABASE=tasty_autumn_model_test app php artisan test
docker compose exec postgres dropdb -U tasty_autumn --force tasty_autumn_model_test
```

Если `DB_USERNAME` изменён относительно `.env.example`, замените `tasty_autumn` в командах `createdb` и `dropdb` на настроенное имя пользователя.

## Остановка

```bash
docker compose down
```

Чтобы дополнительно удалить локальные данные PostgreSQL:

```bash
docker compose down --volumes
```

Удаление volume необратимо и для обычной остановки не требуется.
