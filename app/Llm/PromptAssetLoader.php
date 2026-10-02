<?php

namespace App\Llm;

use App\Llm\Data\PromptAssets;
use App\Llm\Exceptions\PromptAssetException;
use JsonException;

final class PromptAssetLoader
{
    private ?PromptAssets $assets = null;

    public function load(): PromptAssets
    {
        if ($this->assets !== null) {
            return $this->assets;
        }

        $systemPrompt = $this->readRequiredFile(
            (string) config('bot.system_prompt_path'),
            'System prompt',
        );
        $rules = $this->readRequiredFile(
            (string) config('bot.rules_path'),
            'Promotion rules',
        );
        $schemaJson = $this->readRequiredFile(
            (string) config('bot.response_schema_path'),
            'Response schema',
        );
        $version = trim((string) config('bot.prompt_version'));

        if ($version === '' || strlen($version) > 64) {
            throw new PromptAssetException('Prompt version is not configured correctly.');
        }

        try {
            $schema = json_decode($schemaJson, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PromptAssetException('Response schema is not valid JSON.', previous: $exception);
        }

        if (! is_array($schema) || ($schema['type'] ?? null) !== 'object') {
            throw new PromptAssetException('Response schema must describe a JSON object.');
        }

        return $this->assets = new PromptAssets(
            $systemPrompt,
            $rules,
            $schema,
            $version,
            hash('sha256', $rules),
        );
    }

    private function readRequiredFile(string $path, string $label): string
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new PromptAssetException("{$label} file is missing or unreadable.");
        }

        $content = file_get_contents($path);

        if ($content === false || trim($content) === '') {
            throw new PromptAssetException("{$label} file is empty or unreadable.");
        }

        return $content;
    }
}
