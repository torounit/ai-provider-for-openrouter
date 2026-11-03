<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Models;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Class for an OpenRouter model that supports both text and image generation, using the images API for images.
 *
 * Based on the Vercel AI Gateway provider, except that images are generated through the images API.
 *
 * Based on the Vercel AI Gateway provider, except that images are generated through the images API.
 *
 * @since 0.1.0
 *
 * @link https://github.com/vercel-labs/ai-gateway-provider-php/blob/66cde97/src/Models/AiGatewayTextAndImageGenerationModel.php
 *
 * @extends AbstractOpenRouterTextAndMediaGenerationModel<OpenRouterImageGenerationModel>
 */
class OpenRouterTextAndImageGenerationModel extends AbstractOpenRouterTextAndMediaGenerationModel implements
    ImageGenerationModelInterface
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     *
     * @return OpenRouterImageGenerationModel The model.
     */
    protected function createMediaModel(
        ModelMetadata $metadata,
        ProviderMetadata $providerMetadata,
        ?string $openRouterModelId
    ): AbstractApiBasedModel {
        return new OpenRouterImageGenerationModel($metadata, $providerMetadata, $openRouterModelId);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        return $this->getMediaModel()->generateImageResult($prompt);
    }
}
