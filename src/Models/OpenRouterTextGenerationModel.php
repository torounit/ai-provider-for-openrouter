<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Models;

use ToroUnit\OpenRouterAiProvider\Authentication\OpenRouterRequestAuthentication;
use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Class for an OpenRouter text generation model using the OpenAI compatible Chat Completions API.
 *
 * @since 0.1.0
 */
class OpenRouterTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        return OpenRouterRequestAuthentication::wrap(parent::getRequestAuthentication());
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        return new Request(
            $method,
            OpenRouterProvider::url($path),
            $headers,
            $data,
            $this->getRequestOptions()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $params = parent::prepareGenerateTextParams($prompt);

        // OpenRouter supports top_k for many models, which the OpenAI compatible implementation does not send.
        $topK = $this->getConfig()->getTopK();
        if ($topK !== null) {
            if (isset($params['top_k'])) {
                throw new InvalidArgumentException(
                    'The custom option "top_k" conflicts with an existing parameter.'
                );
            }
            $params['top_k'] = $topK;
        }

        return $params;
    }
}
