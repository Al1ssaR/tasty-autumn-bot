<?php

return [
    'prompt_version' => 'bot-v3',
    'system_prompt_path' => base_path('prompts/bot/system.md'),
    'response_schema_path' => base_path('prompts/bot/response-schema.json'),
    'rules_path' => base_path('docs/promo-rules.md'),
    'business_timezone' => 'Europe/Moscow',
    'escalation_notice' => 'Передал ваш вопрос оператору. Он ответит здесь, в чате.',
    'unsafe_response' => 'Я могу помочь только с вопросами по правилам акции. Этот запрос выполнить не могу.',
    'out_of_scope_response' => 'Здравствуйте! Я помогу с вопросами об акции «Вкусная осень». Что вы хотите узнать?',
    'insufficient_context_response' => 'Не совсем понял вопрос. Уточните, пожалуйста, что вы хотите узнать об акции.',
];
