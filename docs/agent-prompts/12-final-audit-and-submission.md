# Codex Prompt 12 — финальный аудит и подготовка тестового к сдаче

Продолжаем разработку тестового задания M-Social «Вкусная осень».

Это НОВАЯ сессия.

Не полагайся на контекст предыдущих чатов. Источник истины — текущее состояние репозитория, Git history и документация проекта.

Основная функциональная разработка MVP завершена.

Реализованы:

- PostgreSQL schema и invariants;
- Laravel/Docker environment;
- Telegram Bot API через long polling;
- реальный Telegram intake;
- LLM provider-neutral layer;
- Groq integration;
- system prompt `bot-v1`;
- baseline evaluation;
- контролируемая итерация `bot-v2`;
- сравнительный evaluation;
- PII redaction;
- fail-safe escalation;
- operator authentication;
- очередь обращений;
- история сообщений;
- ответы оператора;
- закрытие ticket;
- статистика.

Текущий runtime prompt:

`bot-v2`

На этом этапе НЕ создавать `bot-v3`.

Цель текущего этапа:

провести полный аудит исходного тестового задания, устранить только реальные проблемы готовности к сдаче, привести документацию и Git-состояние в порядок и подготовить репозиторий так, чтобы проверяющий мог запустить и понять проект без объяснений автора.

---

# 1. Сначала только аудит

До изменения файлов полностью изучи:

- `AGENTS.md`;
- `README.md`;
- `docs/task.md`;
- `docs/promo-rules.md`;
- `docs/requests.md`;
- `docs/architecture.md`;
- `docs/assumptions.md`;
- `docs/database-schema.md`;
- `docs/evaluation-results.md`;
- `docs/evaluation-results-v2.md`;
- `docs/prompt-iterations.md`;
- `prompts/bot/README.md`;
- `prompts/bot/system.md`;
- `prompts/bot/response-schema.json`;
- `prompts/bot/versions/*`;
- `evaluation/results.json`;
- `evaluation/results-v2.json`.

Также изучи:

- migrations;
- models;
- Telegram layer;
- LLM layer;
- operator layer;
- routes;
- Compose;
- tests.

Посмотри Git history.

Выполни:

`git status`

`git branch --show-current`

`git log --oneline --decorate -20`

Рабочая ветка:

`task_mvp-tgbot`

Сначала сформируй checklist исходного задания и сопоставь каждый пункт с фактической реализацией.

До завершения этого анализа ничего не меняй.

---

# 2. Проверить требования исходного тестового задания

Для каждого требования установи один статус:

- DONE;
- PARTIAL;
- MISSING;
- NOT REQUIRED.

Не считать документацию доказательством реализации, если код этого не подтверждает.

---

# 3. Telegram bot

Проверить фактически:

- Telegram bot создан;
- используется Bot API;
- long polling работает;
- пользовательские текстовые сообщения принимаются;
- сообщения сохраняются;
- duplicate updates идемпотентны;
- FAQ может обрабатываться LLM;
- personal/missing/unsafe запросы эскалируются;
- пользователь получает automatic escalation notice;
- operator reply способен доставляться обратно через Telegram transport.

Реальный Telegram intake уже проверялся ранее.

Не выполнять новые реальные Telegram send без отдельного прямого разрешения пользователя.

---

# 4. Operator panel

Проверить:

- login/logout;
- inactive operator restriction;
- open ticket queue;
- история обращения;
- trigger message;
- последующие сообщения;
- bot/system/operator messages;
- operator reply;
- delivery status;
- close;
- closed ticket исчезает из queue;
- новый вопрос после закрытия снова идёт на bot classification.

---

# 5. Статистика

Проверить точные определения:

## Решено ботом

Только:

valid `answer`
+
успешно доставленный automatic response.

## Передано оператору

Количество созданных support tickets.

## Среднее время ответа

От `support_tickets.created_at`
до
`first_operator_response_at`.

Только отвеченные tickets.

Убедиться, что UI и код используют именно эти определения.

---

# 6. PostgreSQL

Проверить:

- используется PostgreSQL, не SQLite как production DB;
- fresh migrations;
- rollback;
- повторные migrations;
- constraints;
- FK;
- partial unique open-ticket index;
- JSONB;
- timestamptz;
- indexes;
- schema diagram соответствует фактической реализации.

Если Mermaid ERD в `docs/database-schema.md` устарела — актуализировать.

Не менять database architecture без необходимости.

---

# 7. Docker

Исходное требование:

проект должен запускаться через:

`docker compose up`

Проведи максимально чистый smoke-test.

Не использовать случайно запущенные старые containers как доказательство.

Проверь:

- Compose config;
- image build;
- PostgreSQL health;
- migrations;
- app health;
- bot worker configuration;
- operator pages.

Не удалять основной volume без необходимости.

---

# 8. First-run experience

Представь, что проверяющий впервые клонировал repository.

Проверь README как инструкцию для этого человека.

Из README должно быть понятно:

1. какие требования нужны;
2. как создать `.env`;
3. какие secrets заполнить;
4. как сгенерировать `APP_KEY`;
5. как запустить проект;
6. как создать первого оператора;
7. как открыть web panel;
8. как запустить Telegram bot;
9. как работает Groq;
10. как запустить tests;
11. где посмотреть evaluation;
12. как остановить проект.

Не предполагать знания предыдущих разговоров.

---

# 9. Secrets

Проверь весь tracked repository на наличие:

- Telegram bot token;
- Groq API key;
- Authorization header;
- реального `.env`;
- случайных credentials;
- PAN;
- паролей.

Используй безопасный поиск.

Не выводи сами найденные secrets в отчёт, если они обнаружатся.

Если secret реально оказался в Git history:

не переписывай историю автоматически.

Остановись и явно сообщи пользователю.

---

# 10. `.env.example`

Проверить, что в нём присутствуют все необходимые переменные и нет реальных secrets.

В частности:

- application;
- PostgreSQL;
- Telegram;
- Groq/LLM.

Значения должны позволять понять конфигурацию.

Secret values должны быть пустыми/placeholders.

---

# 11. README — архитектура

README должен кратко объяснять flow:

Telegram
→ intake
→ classification
→ answer/escalation
→ PostgreSQL
→ operator panel
→ Telegram reply.

Не копировать весь `docs/architecture.md`.

Нужен короткий понятный overview.

---

# 12. Assumptions

Проверить `docs/assumptions.md`.

Он должен содержать только реально принятые допущения.

Удалить/исправить только явно устаревшие утверждения.

Особенно проверить:

- long polling;
- text-only;
- один open ticket;
- partial answer;
- statistics definitions;
- UTC DB / Europe Moscow business time;
- no site integration;
- operator auth.

Не переписывать историю проекта задним числом.

---

# 13. Database diagram

Исходное задание требует схему БД и объяснение решений.

Проверить, что:

`docs/database-schema.md`

содержит:

- актуальную ER diagram;
- основные entities;
- relationships;
- constraints;
- indexes;
- reasoning по ключевым решениям.

Если Mermaid diagram существует и соответствует коду — не менять ради изменения.

---

# 14. Runtime bot prompts

Исходное задание требует bot prompts отдельными файлами.

Проверить наличие и реальное runtime использование:

- `prompts/bot/system.md`;
- response schema;
- version history;
- README prompt layer.

Убедиться, что current runtime:

`bot-v2`.

Baseline `bot-v1` не менять.

---

# 15. Evaluation 25 запросов

Исходное задание требует таблицу запуска 25 примеров.

Проверить:

- все 25 присутствуют;
- original dataset не изменён;
- final v2 report содержит все 25;
- actual result есть;
- PASS/FAIL не скрыт;
- technical failures обозначены;
- manual content review обозначен;
- provider/model/prompt version/frozen time указаны;
- №22 безопасно redacted.

Final README должен однозначно направлять проверяющего к:

`docs/evaluation-results-v2.md`

как к текущему итоговому прогону.

Baseline v1 сохранить как историю итерации.

---

# 16. Не приукрашивать evaluation

Не писать в README, что бот решил все 25 обращений.

Фактический v2 результат:

- exact action+reason: 20/25;
- strict content PASS: 17/25;
- content FAIL: 8/25;
- technical failures: 2;
- security: 2/2.

Если текущие artifacts подтверждают эти цифры — сохранить их честно.

Не перезапускать evaluation на этом этапе.

Не менять prompt ради score.

---

# 17. Prompt iteration documentation

Проверить:

`docs/prompt-iterations.md`

Документ должен понятно показывать:

`bot-v1`
→ baseline
→ наблюдаемые проблемы
→ обобщаемые изменения
→ `bot-v2`
→ повторный evaluation.

Не писать, что prompt специально подгонялся под отдельные номера.

Не скрывать regression и provider failures.

---

# 18. Agent prompts

Исходное задание отдельно оценивает:

- декомпозицию;
- постановку задач AI-agent;
- управление процессом.

В repository уже существуют пользовательские:

`docs/agent-prompts/*`

ЭТИ ФАЙЛЫ НУЖНО ТЕПЕРЬ ПРОВЕРИТЬ.

До этого они намеренно не попадали в commits реализации.

На финальном этапе:

- изучи их;
- убедись, что в них нет secrets;
- убедись, что это реальные использованные/prompts процесса;
- не переписывай их задним числом под красивую историю;
- не удаляй неудачные/corrective prompts, если они являются значимой частью реального процесса.

Допустимо привести только:

- filenames;
- heading consistency;
- README/index;

если содержимое сохраняет реальную историю.

---

# 19. Создать индекс agent prompts

Если его ещё нет, создай небольшой:

`docs/agent-prompts/README.md`

Он должен объяснять:

- зачем сохранены prompts;
- что это prompts разработки для Codex, а не runtime prompts бота;
- порядок основных этапов;
- что runtime prompts находятся отдельно в `prompts/bot/`.

Дай короткий индекс файлов.

Не превращать его в огромный журнал.

---

# 20. Agent session logs

Исходное задание просит 2–3 ключевых лога сессий AI-agent.

Очень важно:

НЕ ФАБРИКОВАТЬ логи.

Не создавать «примерные» разговоры на основе prompt-файлов.

Сначала проверь, есть ли в текущей среде реальный способ экспортировать текущую Codex session/history.

Если реальный session export доступен:

сохрани только реальные экспорты.

Если export недоступен:

НЕ создавай искусственные logs.

В финальном отчёте явно укажи, что для выполнения этого deliverable требуется пользовательский экспорт из интерфейса/инструмента.

Создай директорию для реальных экспортов только если это оправдано, например:

`docs/agent-sessions/`

с небольшим README, который объясняет, что здесь должны находиться настоящие session exports.

Не добавлять fake transcripts.

---

# 21. Какие 2–3 сессии считать ключевыми

Если реальные экспорты доступны, предпочтительные группы:

### Session 1 — построение MVP

Архитектура → DB → Telegram → LLM v1 → Groq baseline.

### Session 2 — controlled LLM iteration

Анализ baseline → bot-v2 → comparative evaluation.

### Session 3 — product completion

Operator panel → statistics → final audit.

Если фактическая история сессий отличается — использовать реальную историю, а не искусственно делить её.

---

# 22. CLAUDE.md

Исходное задание упоминает `CLAUDE.md`, если использовался Claude Code.

В проекте использовался Codex.

НЕ создавать фальшивый `CLAUDE.md` только ради checklist.

`AGENTS.md` остаётся инструкцией проекта для Codex.

README может кратко сказать, что разработка велась через Codex и правила агента находятся в `AGENTS.md`.

Это уместно именно в документации процесса AI-assisted разработки, поскольку тестовое прямо требует её показать.

---

# 23. Production improvements

Исходное задание просит описать, что было бы улучшено для production.

Проверь README.

Добавь или актуализируй короткий раздел:

`Что улучшить для production`

Только реальные вещи.

Например:

- webhook вместо long polling;
- queue/retry для delivery;
- устойчивый retry/backoff;
- observability;
- secrets manager;
- CSRF/session hardening по deployment environment;
- HTTPS/reverse proxy;
- retention policy;
- rate limiting;
- operator management;
- production database backup;
- более стабильный/платный LLM SLA;
- дополнительная prompt/model evaluation.

Не говорить, что всё это необходимо для текущего MVP.

---

# 24. Known limitations

README должен честно перечислять текущие ограничения MVP.

В частности:

- text-only Telegram input;
- long polling;
- один общий operator queue;
- Groq free-tier provider variability;
- нет automatic retry failed Telegram delivery;
- нет operator-management UI;
- не реализована интеграция с реальными данными сайта акции;
- LLM v2 не имеет 100% evaluation score.

Не перечислять десятки несущественных ограничений.

---

# 25. Code quality audit

Проверить, но НЕ устраивать большой refactoring.

Искать:

- debug statements;
- `dd()`;
- `dump()`;
- `var_dump`;
- commented debugging code;
- hardcoded absolute Windows paths;
- TODO, которые означают незавершённое обязательное требование;
- dead fake provider в production;
- accidental test credentials.

Исправить только очевидные проблемы готовности.

Не рефакторить работающий код ради вкуса.

---

# 26. UI smoke

Провести локальный smoke:

- `/login`;
- login active operator;
- ticket queue;
- ticket page;
- statistics;
- logout.

Если тестовых tickets нет, используй test environment/fixtures.

Не загрязнять основную БД бессмысленными demo records.

---

# 27. Full automated test suite

Запусти полный набор в PostgreSQL.

Не ограничиваться SQLite.

Зафиксировать:

- tests count;
- assertions count;
- skipped tests, если есть.

Все обязательные tests должны быть зелёными перед финальным commit.

---

# 28. Formatting/static sanity

Выполнить:

- `composer validate --strict`;
- PHP syntax verification в разумном объёме;
- Pint;
- `git diff --check`;
- `docker compose config --quiet`.

---

# 29. Clean startup verification

Проверь сценарий максимально близкий к проверяющему:

1. необходимые environment values присутствуют;
2. image build;
3. `docker compose up`;
4. PostgreSQL healthy;
5. migrations проходят;
6. Laravel health endpoint;
7. operator web UI доступна;
8. bot worker стартует при корректном Telegram token.

Не выполнять реальный Groq evaluation снова.

Не отправлять реальные Telegram сообщения.

---

# 30. Проверить Git diff перед финальными изменениями

Особенно внимательно отдели:

- текущие finalization changes;
- ранее существующие пользовательские `docs/agent-prompts/*`.

На ЭТОМ этапе `docs/agent-prompts/*` являются частью итоговой сдачи и должны быть намеренно добавлены в Git после проверки secrets/content.

Это отличие от предыдущих implementation prompts.

---

# 31. Создать submission checklist

Создай:

`docs/submission-checklist.md`

Это внутренний/проверочный документ, но он может остаться в repository.

Формат — компактная таблица:

| Требование | Реализация | Где смотреть | Статус |

Покрыть основные пункты исходного задания.

Не превращать документ в копию README.

Если что-либо реально отсутствует — статус должен быть MISSING/PARTIAL.

---

# 32. Не создавать новые функции только ради checklist

Если найдена небольшая документационная проблема — исправить.

Если найдена настоящая обязательная функциональная ошибка — исправить и протестировать.

Если найдено крупное отсутствующее требование:

НЕ импровизировать большой новый subsystem внутри final audit.

Сначала явно описать blocker.

---

# 33. Не делать bot-v3

Даже если evaluation показывает ошибки.

`bot-v2` остаётся current version.

Решение о `bot-v3` будет принято отдельно после финального аудита.

Не менять:

- system prompt;
- Groq model;
- evaluation artifacts;
- rules;
- oracle.

---

# 34. Git commit

После всех успешных проверок:

- проверить ветку `task_mvp-tgbot`;
- проверить diff;
- проверить staged diff;
- убедиться в отсутствии secrets;
- включить проверенные `docs/agent-prompts/*`;
- включить final documentation/audit changes;
- НЕ включать `.env`;
- НЕ включать временные DB/log/cache файлы.

Создай один финальный документационный/audit commit на русском языке.

Сообщение должно соответствовать стилю истории проекта.

Например по смыслу:

`Финализация документации и подготовка проекта к сдаче`

Но сначала изучи реальные предыдущие commit messages.

`git push` не выполнять.

---

# 35. После commit

Выполни:

`git status`

`git log -1 --stat`

В идеале рабочее дерево должно быть чистым.

Если остаются файлы:

объясни каждый из них.

---

# Финальный отчёт

После завершения дай структурированный итог.

## Соответствие ТЗ

Перечисли:

- DONE;
- PARTIAL;
- MISSING.

Особенно явно сообщи, осталось ли хоть одно обязательное функциональное требование.

## Запуск

Укажи проверенный путь первого запуска.

## Tests

Укажи фактическое число tests/assertions.

## Docker

Укажи фактически выполненные smoke checks.

## Security

Подтверди отсутствие tracked secrets.

## Evaluation

Подтверди итоговую версию `bot-v2` и ссылки на v1/v2 reports.

## AI development artifacts

Укажи:

- `AGENTS.md`;
- `docs/agent-prompts/*`;
- наличие/отсутствие настоящих session exports.

Если session exports отсутствуют — НЕ считать их созданными и явно сказать, что их должен экспортировать пользователь из интерфейса Codex.

## Git

Укажи hash и сообщение final commit.

## Remaining work

Раздели:

- обязательные blockers для сдачи;
- optional improvements;
- production improvements.

Не создавать `bot-v3` и не выполнять `git push`.
