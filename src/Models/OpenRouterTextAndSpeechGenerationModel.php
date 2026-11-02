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
 *
 * @extends AbstractOpenRouterTextAndMediaGenerationModel<OpenRouterTextToSpeechConversionModel>
 */
class OpenRouterTextAndSpeechGenerationModel extends AbstractOpenRouterTextAndMediaGenerationModel implements
    TextToSpeechConversionModelInterface
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     *
     * @return OpenRouterTextToSpeechConversionModel The model.
     */
    protected function createMediaModel(
        ModelMetadata $metadata,
        ProviderMetadata $providerMetadata
    ): AbstractApiBasedModel {
        return new OpenRouterTextToSpeechConversionModel($metadata, $providerMetadata);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function convertTextToSpeechResult(array $prompt): GenerativeAiResult
    {
        return $this->getMediaModel()->convertTextToSpeechResult($prompt);
    }
}
