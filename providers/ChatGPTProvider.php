<?php

declare(strict_types=1);

namespace nova\plugin\ai\providers;

class ChatGPTProvider extends BaseOpenAIProvider
{
    public function getName(): string
    {
        return 'chatgpt';
    }

    public function getCreateKeyUri(): string
    {
        return 'https://platform.openai.com/api-keys';
    }

    protected function getDefaultApiUri(): string
    {
        return 'https://api.openai.com';
    }

    protected function getDefaultModel(): string
    {
        return 'gpt-3.5-turbo';
    }
}


