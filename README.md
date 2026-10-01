# «Вкусная осень» — AI Support MVP

MVP системы поддержки участников промоакции M-Social. Проект содержит Laravel 13, PostgreSQL 17, Docker Compose, Telegram-контур на long polling и provider-neutral LLM-слой с concrete Groq adapter. Новое личное текстовое сообщение проходит PII redaction, классификацию по полному документу правил, строгую проверку structured result и затем получает ответ либо создаёт обращение оператору. Операторская панель будет добавлена на следующем этапе.

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

PostgreSQL проверяется через healthcheck. Laravel запускает миграции только после готовности базы данных. Compose-сервис `bot` ждёт готовности web-приложения после миграций и затем запускает `telegram:poll`.

Последующие запуски не требуют пересборки:

```bash
docker compose up
```

## Адреса

- приложение: <http://localhost:8000>;
- health endpoint Laravel: <http://localhost:8000/up>.

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

Runtime использует prompt версии `bot-v2` из `prompts/bot/system.md` и строгий контракт `prompts/bot/response-schema.json`. Неизменяемый baseline `bot-v1` сохранён в `prompts/bot/versions/bot-v1.md`, а точная копия текущей версии — в `prompts/bot/versions/bot-v2.md`. Полный `docs/promo-rules.md`, текущее московское время и redacted-копия пользовательского сообщения передаются отдельно. SHA-256 точных bytes правил сохраняется вместе с решением.

Перед LLM маскируются распространённые российские номера телефонов и вероятные номера банковских карт. Оригинальный текст остаётся в истории PostgreSQL, а redacted-копия не сохраняется. Это ограниченная защита для явно распознаваемых форматов, а не универсальный DLP.

При `LLM_PROVIDER=groq` приложение использует Laravel HTTP Client и endpoint `{LLM_BASE_URL}/chat/completions`; SDK и сторонняя LLM abstraction library не устанавливаются. Model всегда берётся из `LLM_MODEL`, fallback на другую модель или provider отсутствует. Runtime-запрос использует `reasoning_effort=medium`, не задаёт `temperature` и `top_p`, не включает tools, raw reasoning или streaming. Контракт передаётся как `response_format=json_schema` с `strict=true`.

Для неизвестного provider production-binding не подменяет конфигурацию Groq или Fake client: применяется существующий безопасный unavailable-client и техническая fail-safe эскалация. HTTP/provider error, включая 401/403, 429 и 5xx, становится `api_failure`; timeout — `timeout`; отсутствующий или некорректный assistant content — `malformed_response`. Публичные ошибки не содержат API key, Authorization header, provider body или полный request URL.

При валидном `answer` приложение сохраняет decision и исходящее сообщение, затем вне database transaction вызывает Telegram. При `escalate`, malformed result, timeout или API/application failure создаётся обращение и отправляется предсказуемое application-owned уведомление. Сгенерированный моделью ответ для `unsafe_request` не используется.

## Groq smoke-test и evaluation

Один безопасный live smoke на исходном обращении №1 выполняется отдельно от PHPUnit и ничего не отправляет в Telegram:

```bash
docker compose run --rm --no-deps app php artisan bot:evaluate --case=1 --no-report --max-attempts=1
```

Полный evaluation читает тексты непосредственно из `docs/requests.md`, выполняет все 25 cases строго последовательно и использует frozen time `2026-09-30T12:00:00+03:00`. Expected mapping хранится только в `evaluation/expected.php` и не используется production-классификацией:

```bash
docker compose run --rm --no-deps app php artisan bot:evaluate
```

При 429 runner учитывает `Retry-After`, ждёт не более 30 секунд за одну попытку и делает максимум три попытки case. Production classification не повторяет внешний вызов бесконечно и сразу использует fail-safe. Raw provider responses и secrets в artifacts не сохраняются; обращение №22 публикуется только с `[CARD_REDACTED]`.

Артефакты версионированы и не перезаписывают предыдущий прогон:

- `bot-v1`: `docs/evaluation-results.md` и `evaluation/results.json`;
- `bot-v2`: `docs/evaluation-results-v2.md` и `evaluation/results-v2.json`.

История одной контролируемой итерации и наблюдаемые изменения поведения описаны в `docs/prompt-iterations.md`.

Application-level live smoke использует real Groq, настоящий classification orchestration, rollback и fake Telegram transport. Команда намеренно разрешена только для изолированной базы с суффиксом `_test`:

```bash
docker compose run --rm --no-deps -e DB_DATABASE=tasty_autumn_llm_test app php artisan bot:llm-application-smoke
```

`bot-v1` является неизменяемой baseline-версией. Зафиксированный baseline дал 16 из 25 точных совпадений `action + reason`, 4 технических сбоя и 11 из 25 строгих ручных PASS по содержанию. Контролируемый прогон `bot-v2` дал соответственно 20 из 25, 2 и 17 из 25. Подробный разбор и ограничения находятся в версионированных отчётах.

## База данных

Приложение подключается к сервису `postgres` по настройкам `DB_*` из `.env`. Данные PostgreSQL сохраняются в Docker volume `postgres-data`. Пароль в `.env.example` предназначен только для локальной разработки; перед любым внешним развёртыванием его необходимо заменить.

Все timestamps приложения и базы хранятся в UTC. Московское время (`Europe/Moscow`) будет передаваться явно в сценарии, которым оно необходимо по правилам акции.

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
