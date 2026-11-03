<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Models;

use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
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

    /**
     * {@inheritDoc}
     *
     * The OpenAI compatible implementation passes the schema as `json_schema` directly, while the API expects it to
     * be wrapped in an object with a name. Strict mode is not enabled, since it only accepts schemas that meet
     * additional requirements, which vary between the providers that OpenRouter routes to.
     *
     * The schema name matches the AI Provider for OpenAI.
     *
     * @since 0.1.0
     *
     * @link https://openrouter.ai/docs/guides/features/structured-outputs
     * @link https://github.com/WordPress/ai-provider-for-openai/blob/24ba3be/src/Models/OpenAiTextGenerationModel.php
     */
    protected function prepareResponseFormatParam(?array $outputSchema): array
    {
        if (!is_array($outputSchema)) {
            return parent::prepareResponseFormatParam($outputSchema);
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'response_schema',
                'schema' => $outputSchema,
            ],
        ];
    }
}
