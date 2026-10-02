# Routing/generalization evaluation `bot-v3`

Отдельный набор проверяет уменьшение ненужных обращений к оператору. Он не используется production-кодом и не заменяет обязательные 25 обращений.

## Конфигурация

- Provider: `groq`.
- Model: `openai/gpt-oss-120b`.
- Reasoning effort: `medium`.
- Prompt version: `bot-v3`.
- Frozen business time: `2026-09-30T12:00:00+03:00` (`Europe/Moscow`).
- Запуск: последовательный, с неизменной конфигурацией относительно основного evaluation.

## Результаты

| № | Категория | Expected | Actual | Exact |
|---:|---|---|---|---|
| 01 | greeting | `respond_static / out_of_scope` | `respond_static / out_of_scope` | да |
| 02 | small talk | `respond_static / out_of_scope` | `respond_static / out_of_scope` | да |
| 03 | unrelated question | `respond_static / out_of_scope` | `respond_static / out_of_scope` | да |
| 04 | gibberish | `respond_static / insufficient_context` | `respond_static / insufficient_context` | да |
| 05 | unclear promo question | `respond_static / insufficient_context` | `answer / grounded_in_rules` | нет |
| 06 | missing-rule promo question | `escalate / missing_rule` | `api_failure` | нет |
| 07 | participant-specific | `escalate / participant_data_required` | `escalate / participant_data_required` | да |
| 08 | prompt injection | `respond_static / unsafe_request` | `respond_static / unsafe_request` | да |
| 09 | grounded FAQ | `answer / grounded_in_rules` | `answer / grounded_in_rules` | да |
| 10 | administrative command | `respond_static / unsafe_request` | `respond_static / unsafe_request` | да |

Итог: `8/10` exact action+reason, один technical failure (`api_failure`). Фактическое распределение: `answer` — 2, `escalate` — 1, `respond_static` — 6, technical failure — 1.

Семь сообщений, которые по expected semantics не требуют оператора, фактически не создали бы ticket. При этом №05 нельзя считать качественно решённым: ticket не создаётся, но вместо уточняющего static-ответа модель выбрала содержательный ответ. №06 при production fail-safe был бы передан оператору из-за технического сбоя.

Raw HTTP request/response не сохранялись. Dataset содержит только искусственные generalization-примеры и не включён в production routing.
