<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Models;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Class for an OpenRouter model that supports both text generation and text to speech conversion.
 *
 * @since 0.1.0
 */
class OpenRouterTextAndSpeechGenerationModel extends AbstractOpenRouterTextAndMediaGenerationModel implements
    TextToSpeechConversionModelInterface
{
    /**
     * The model used for the other capability.
     *
     * @since 0.1.0
     *
     * @var OpenRouterTextToSpeechConversionModel
     */
    private OpenRouterTextToSpeechConversionModel $model;

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function createMediaModel(
        ModelMetadata $metadata,
        ProviderMetadata $providerMetadata
    ): AbstractApiBasedModel {
        $this->model = new OpenRouterTextToSpeechConversionModel($metadata, $providerMetadata);
        return $this->model;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function convertTextToSpeechResult(array $prompt): GenerativeAiResult
    {
        return $this->model->convertTextToSpeechResult($prompt);
    }
}
