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
 * @since 0.1.0
 *
 * @link https://github.com/vercel-labs/ai-gateway-provider-php/blob/66cde97/src/Models/AiGatewayTextAndImageGenerationModel.php
 */
class OpenRouterTextAndImageGenerationModel extends AbstractOpenRouterTextAndMediaGenerationModel implements
    ImageGenerationModelInterface
{
    /**
     * The model used for the other capability.
     *
     * @since 0.1.0
     *
     * @var OpenRouterImageGenerationModel
     */
    private OpenRouterImageGenerationModel $model;

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function createMediaModel(
        ModelMetadata $metadata,
        ProviderMetadata $providerMetadata
    ): AbstractApiBasedModel {
        $this->model = new OpenRouterImageGenerationModel($metadata, $providerMetadata);
        return $this->model;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        return $this->model->generateImageResult($prompt);
    }
}
