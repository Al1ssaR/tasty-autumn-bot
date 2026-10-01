<?php

namespace Tests\Fakes;

use App\Llm\Contracts\LlmDecisionClient;
use App\Llm\Data\LlmClientIdentity;
use App\Llm\Data\LlmDecisionRequest;
use App\Llm\Data\LlmRawResponse;
use Throwable;

final class FakeLlmDecisionClient implements LlmDecisionClient
{
    /** @var list<LlmDecisionRequest> */
    public array $requests = [];

    public LlmRawResponse|Throwable|null $nextResult = null;

    public function __construct(
        private readonly LlmClientIdentity $clientIdentity = new LlmClientIdentity(
            'fake-provider',
            'fake-model',
        ),
    ) {}

    public function identity(): LlmClientIdentity
    {
        return $this->clientIdentity;
    }

    public function decide(LlmDecisionRequest $request): LlmRawResponse
    {
        $this->requests[] = $request;

        if ($this->nextResult instanceof Throwable) {
            throw $this->nextResult;
        }

        return $this->nextResult ?? new LlmRawResponse('{}');
    }

    /** @param array<string, mixed> $payload */
    public function respondWith(array $payload): void
    {
        $this->nextResult = new LlmRawResponse(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }
}
