<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Models;

use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;

/**
 * Class for an OpenRouter image generation model using the Images API.
 *
 * The Images API is similar to OpenAI's image generations API, but it takes an aspect ratio instead of a size and
 * always returns base64 data.
 *
 * @since 0.1.0
 *
 * @link https://openrouter.ai/docs/guides/overview/multimodal/image-generation
 */
class OpenRouterImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel
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
        // The OpenAI compatible implementation uses OpenAI's path.
        if ($path === 'images/generations') {
            $path = 'images';
        }

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
     *
     * @param list<Message> $prompt The prompt to generate an image for.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateImageParams(array $prompt): array
    {
        $params = parent::prepareGenerateImageParams($prompt);

        // The API always returns base64 data.
        unset($params['response_format']);

        // The parent sets the size only from the configured aspect ratio or orientation, which is an aspect ratio here.
        $config = $this->getConfig();
        if ($config->getOutputMediaAspectRatio() !== null || $config->getOutputMediaOrientation() !== null) {
            if (isset($params['aspect_ratio'])) {
                throw new InvalidArgumentException(
                    'The custom option "aspect_ratio" conflicts with an existing parameter.'
                );
            }
            $params['aspect_ratio'] = $params['size'] ?? null;
            unset($params['size']);
        }

        return $params;
    }

    /**
     * {@inheritDoc}
     *
     * Returns the aspect ratio instead, which is sent as `aspect_ratio`. Any aspect ratio is passed through, since
     * the supported ones depend on the model.
     *
     * @since 0.1.0
     */
    protected function prepareSizeParam(?MediaOrientationEnum $orientation, ?string $aspectRatio): string
    {
        if ($aspectRatio !== null) {
            return $aspectRatio;
        }
        // These match the sizes that the OpenAI compatible implementation uses for each orientation.
        if ($orientation !== null && $orientation->isLandscape()) {
            return '3:2';
        }
        if ($orientation !== null && $orientation->isPortrait()) {
            return '2:3';
        }
        return '1:1';
    }

    /**
     * {@inheritDoc}
     *
     * Reads the token usage, which the API reports with the chat completions keys.
     *
     * @since 0.1.0
     */
    protected function parseResponseToGenerativeAiResult(
        Response $response,
        string $expectedMimeType = 'image/png'
    ): GenerativeAiResult {
        $result = parent::parseResponseToGenerativeAiResult($response, $expectedMimeType);

        $responseData = $response->getData();
        $usage = isset($responseData['usage']) && is_array($responseData['usage']) ? $responseData['usage'] : [];
        if (!isset($usage['prompt_tokens']) && !isset($usage['completion_tokens'])) {
            return $result;
        }

        $promptTokens = is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : 0;
        $completionTokens = is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : 0;
        $totalTokens = is_int($usage['total_tokens'] ?? null)
            ? $usage['total_tokens']
            : $promptTokens + $completionTokens;

        return new GenerativeAiResult(
            $result->getId(),
            $result->getCandidates(),
            new TokenUsage($promptTokens, $completionTokens, $totalTokens),
            $result->getProviderMetadata(),
            $result->getModelMetadata(),
            $result->getAdditionalData()
        );
    }

    /**
     * {@inheritDoc}
     *
     * Uses the media type from the response, since the image format depends on the model.
     *
     * @since 0.1.0
     */
    protected function parseResponseChoiceToCandidate(
        array $choiceData,
        int $index,
        string $expectedMimeType = 'image/png'
    ): Candidate {
        if (isset($choiceData['media_type']) && is_string($choiceData['media_type'])) {
            $expectedMimeType = $choiceData['media_type'];
        }

        return parent::parseResponseChoiceToCandidate($choiceData, $index, $expectedMimeType);
    }
}
