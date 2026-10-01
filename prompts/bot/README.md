# Prompt первой линии поддержки

Текущая осознанная версия system prompt: `bot-v1`.

Runtime передаёт LLM отдельными компонентами system prompt, полный `docs/promo-rules.md`, текущее время `Europe/Moscow`, redacted-сообщение участника и контракт `response-schema.json`. Правила не копируются внутрь system prompt; их точные bytes хешируются SHA-256 для аудита решения.

JSON Schema находится в `prompts/bot/response-schema.json`. Дополнительные semantic invariants проверяет application layer. Первая concrete-интеграция использует Groq Chat Completions и модель `openai/gpt-oss-120b` через provider-neutral application interface.
