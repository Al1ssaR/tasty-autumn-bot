# Итерации prompt первой линии поддержки

Документ фиксирует контролируемые изменения поведения. Он не заменяет полные отчёты evaluation и не используется runtime.

## `bot-v1`

Первая baseline-версия сохранена без изменений в `prompts/bot/versions/bot-v1.md`.

Результат последовательного прогона 25 обращений:

- exact `action + reason`: 16/25;
- strict manual content PASS: 11/25;
- technical failures: 4.

Основные наблюдаемые проблемы: правдоподобные предположения при отсутствии правила, нестабильный выбор причины эскалации, неверная обработка относительной даты, неполные ответы и недостаточная дисциплина grounding/references. Provider timeout и другие сетевые сбои учитывались отдельно и не использовались как основание для изменения prompt.

Полные результаты: `docs/evaluation-results.md` и `evaluation/results.json`.

## `bot-v2`

Вторая версия усиливает только обобщаемые инструкции:

- `answer` разрешён, только если все существенные утверждения непосредственно подтверждаются правилами;
- причины эскалации разделены по основному блокирующему фактору с приоритетом `unsafe_request`;
- составной вопрос с participant-specific частью сохраняет итоговое действие `escalate`;
- перед ответом требуется покрыть все существенные части вопроса и влияющие на смысл условия;
- относительные даты вычисляются только из переданного `CURRENT_TIME`, часового пояса и расписания правил;
- каждая существенная часть ответа должна иметь реальную supporting reference, без нерелевантных ссылок;
- внутренний анализ не выводится, а небезопасные запросы не получают свободный generated answer.

Provider, model, reasoning effort, sampling, frozen time, rules, JSON Schema, expected mapping, PII redaction и retry policy между прогонами не менялись.

Результат последовательного прогона 25 обращений:

- exact `action + reason`: 20/25;
- strict manual content PASS: 17/25;
- content FAIL: 8/25;
- technical failures: 2;
- исправлено baseline FAIL: 7;
- regressions: 1 (технический сбой обращения №1).

Улучшились выбор `missing_rule`/`unsafe_request`, полнота налогового ответа, обработка части прежних reference failures и participant-specific запросов. Остались неподкреплённые предположения, ошибки action для отдельных персональных запросов, неточная ссылка на правило, ошибка вычисления следующей даты и provider failures. Поэтому `bot-v2` заметно лучше baseline, но перед production требуется как минимум устранить calendar/reasoning риск и дополнительно проверить устойчивость provider-вызовов.

Полные результаты и ручная оценка: `docs/evaluation-results-v2.md` и `evaluation/results-v2.json`.

## `bot-v3`

Третья версия создана после ручной UX-проверки работающего приложения. Проверка показала, что прежнее правило «не могу ответить → escalation» создаёт ненужные support tickets для приветствий, small talk, бессвязных и посторонних сообщений, а также prompt injection. Причина итерации — продуктовая маршрутизация очереди операторов, а не попытка повысить evaluation score.

Изменения:

- добавлено действие `respond_static`, которое не создаёт ticket;
- `insufficient_context`, `out_of_scope` и `unsafe_request` получают только application-owned ответы;
- `participant_data_required` и `missing_rule` остаются единственными валидными бизнес-причинами эскалации;
- технические timeout, malformed, API и application failures по-прежнему fail-safe эскалируются;
- greeting относится к обобщаемому `out_of_scope`, без keyword/regex веток в production code;
- добавлен отдельный generalization dataset из 10 routing cases.

Для обязательного набора из 25 обращений намеренно меняется oracle только у №23–25: рецепт становится `respond_static / out_of_scope`, а обе security-попытки — `respond_static / unsafe_request`. Артефакты и prompt `bot-v1`/`bot-v2` не переписываются.

Разрешённый последовательный live-прогон зафиксирован отдельно:

- обязательный набор: 21/25 exact `action + reason`, 19/25 strict manual content PASS, 6 content FAIL, 1 technical failure;
- routing/generalization: 8/10 exact, 1 technical failure;
- фактическое распределение обязательного набора: `answer` — 13, `escalate` — 7, `respond_static` — 4, без результата из-за technical failure — 1;
- №23–25 больше не создают ненужные operator tickets согласно новой продуктовой семантике;
- в routing dataset семь сообщений, которым не нужен оператор, фактически не привели бы к ticket, но №05 ошибочно получил `answer` вместо уточняющего static-ответа;
- PAN №22 и телефон №24 были redacted до provider; security cases №24–25 не раскрыли prompt и не выполнили административные операции.

Относительно `bot-v2` исправлены №1, №16 и №21; №15 регрессировал по причине эскалации, №19 завершился новым `malformed_response`, а №22 дал валидный, но неверный static route вместо participant escalation. Ошибка календарного рассуждения №20 и grounding-дефект №12 сохранились. Изменения №23–25 не считаются улучшением score: это заранее объявленное изменение oracle и product semantics.

Полные результаты: `docs/evaluation-results-v3.md`, `evaluation/results-v3.json` и `docs/routing-evaluation-v3.md`.
