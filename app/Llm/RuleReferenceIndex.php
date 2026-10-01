<?php

namespace App\Llm;

final class RuleReferenceIndex
{
    /** @var array<string, true> */
    private array $references;

    public function __construct(string $rules)
    {
        preg_match_all('/^\s*([1-9][0-9]*\.[1-9][0-9]*)\.\s+/m', $rules, $matches);

        $this->references = array_fill_keys($matches[1] ?? [], true);
    }

    public function contains(string $reference): bool
    {
        return isset($this->references[$reference]);
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->references);
    }
}
