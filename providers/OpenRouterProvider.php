<?php

declare(strict_types=1);

namespace nova\plugin\ai\providers;

class OpenRouterProvider extends BaseOpenAIProvider
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'openrouter';
    }

    /**
     * @inheritDoc
     */
    public function getCreateKeyUri(): string
    {
        return 'https://openrouter.ai/keys';
    }

    /**
     * @inheritDoc
     */
    protected function getDefaultApiUri(): string
    {
        // BaseOpenAIProvider 会在后续拼接 /v1
        return 'https://openrouter.ai/api';
    }

    /**
     * @inheritDoc
     */
    protected function getDefaultModel(): string
    {
        return 'openrouter/auto';
    }
}