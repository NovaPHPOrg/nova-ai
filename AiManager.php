<?php

declare(strict_types=1);

namespace nova\plugin\ai;

use nova\plugin\ai\providers\BaseAIProvider;

class AiManager
{
    public function getCurrentProvider(?string $name = null): ?BaseAIProvider
    {
        return AiConfig::getInstance()->resolveProvider($name);
    }

    /**
     * @param array{onHeader?:callable,onChunk?:callable,onComplete?:callable} $streamOptions
     */
    public function request(string $system, string|array $user, array $streamOptions = []): ?string
    {
        return $this->getCurrentProvider()?->request($system, $user, $streamOptions);
    }
}
