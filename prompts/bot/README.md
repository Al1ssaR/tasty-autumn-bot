# Prompt первой линии поддержки

Текущая осознанная версия system prompt: `bot-v3`.

Точные version artifacts находятся в `prompts/bot/versions/`: `bot-v1.md` фиксирует baseline, `bot-v2.md` сохранён без изменений, а `bot-v3.md` совпадает с текущим runtime `system.md`.

Runtime передаёт LLM отдельными компонентами system prompt, полный `docs/promo-rules.md`, текущее время `Europe/Moscow`, redacted-сообщение участника и контракт `response-schema.json`. Правила не копируются внутрь system prompt; их точные bytes хешируются SHA-256 для аудита решения.

JSON Schema находится в `prompts/bot/response-schema.json`. Дополнительные semantic invariants проверяет application layer. Первая concrete-интеграция использует Groq Chat Completions и модель `openai/gpt-oss-120b` через provider-neutral application interface.

`bot-v3` разделяет содержательный ответ, настоящую передачу оператору и application-owned static response. `respond_static` не создаёт support ticket и применяется только для `insufficient_context`, `out_of_scope` и `unsafe_request`.
