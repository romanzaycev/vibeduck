<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Service;

use OpenAI\Client as OpenAiSdkClient;
use OpenAI; // Для доступа к OpenAI::factory()
use OpenAI\Resources\Completions;
use Romanzaycev\Vibe\Contract\LlmClientInterface;
use OpenAI\Resources\Chat;
use RuntimeException;

class LazyOpenAiClientProxy implements LlmClientInterface
{
    private Configuration $config;
    private ?OpenAiSdkClient $clientInstance = null;

    /**
     * Constructor.
     * Dependencies are injected to allow for lazy initialization of the actual SDK client.
     *
     * @param Configuration $config The application configuration service.
     */
    public function __construct(Configuration $config)
    {
        $this->config = $config;
    }

    /**
     * Initializes and/or returns the actual OpenAI SDK client.
     * The client is created only on the first call to this method.
     *
     * @return OpenAiSdkClient
     * @throws RuntimeException If API key is not configured.
     */
    private function getClient(): OpenAiSdkClient
    {
        if ($this->clientInstance === null) {
            $apiKey = $this->config->get('OPENAI_API_KEY');
            $baseUri = $this->config->get('OPENAI_API_BASE_URI');

            if (empty($apiKey)) {
                throw new \RuntimeException(
                    'OPENAI_API_KEY is not configured. Cannot create OpenAI Client. ' .
                    "Please set it in your global or project `vibeduck` configuration or use `config` command.\n" .
                    "Your global config: " . $this->config->getGlobalConfigFilePath(),
                );
            }

            $factory = OpenAI::factory()->withApiKey((string)$apiKey);

            // Стандартный URI OpenAI клиента, с которым будем сравнивать
            $defaultOpenAiClientUri = 'https://api.openai.com/v1';

            if ($baseUri && $baseUri !== $defaultOpenAiClientUri) {
                $factory = $factory->withBaseUri((string)$baseUri);
            }

            // Здесь можно добавить другие настройки из Configuration, если они появятся
            // например, таймауты, кастомный HTTP клиент и т.д.
            // $timeout = $this->config->get('OPENAI_API_TIMEOUT', 30);
            // $factory = $factory->withHttpClient(new \GuzzleHttp\Client(['timeout' => $timeout]));

            $this->clientInstance = $factory->make();
        }
        return $this->clientInstance;
    }

    /**
     * {@inheritdoc}
     */
    public function chat(): Chat
    {
        return $this->getClient()->chat();
    }

    // Если бы мы добавили другие методы в LlmClientInterface, они бы выглядели так:
    // public function embeddings(): \OpenAI\Resources\Embeddings
    // {
    //     return $this->getClient()->embeddings();
    // }
}
