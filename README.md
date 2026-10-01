# «Вкусная осень» — AI Support MVP

MVP системы поддержки участников промоакции M-Social. Проект содержит Laravel 13, PostgreSQL 17, Docker Compose, Telegram-контур на long polling и provider-neutral LLM-слой. Новое личное текстовое сообщение проходит PII redaction, классификацию по полному документу правил, строгую проверку structured result и затем получает ответ либо создаёт обращение оператору. Операторская панель и конкретный LLM provider будут добавлены на следующих этапах.

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

5. Запустите приложение, Telegram worker и PostgreSQL:

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

Runtime использует prompt версии `bot-v1` из `prompts/bot/system.md` и строгий контракт `prompts/bot/response-schema.json`. Полный `docs/promo-rules.md`, текущее московское время и redacted-копия пользовательского сообщения передаются отдельно. SHA-256 точных bytes правил сохраняется вместе с решением.

Перед LLM маскируются распространённые российские номера телефонов и вероятные номера банковских карт. Оригинальный текст остаётся в истории PostgreSQL, а redacted-копия не сохраняется. Это ограниченная защита для явно распознаваемых форматов, а не универсальный DLP.

Конкретный provider на текущем этапе не подключён. Production-binding не генерирует фиктивные ответы: отсутствие provider приводит к технической fail-safe эскалации и созданию обращения. Fake client используется только автоматическими тестами. Настройки `LLM_PROVIDER`, `LLM_MODEL`, `LLM_API_KEY`, `LLM_BASE_URL` и `LLM_TIMEOUT` зарезервированы для следующего этапа интеграции.

При валидном `answer` приложение сохраняет decision и исходящее сообщение, затем вне database transaction вызывает Telegram. При `escalate`, malformed result, timeout или API/application failure создаётся обращение и отправляется предсказуемое application-owned уведомление. Сгенерированный моделью ответ для `unsafe_request` не используется.

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
