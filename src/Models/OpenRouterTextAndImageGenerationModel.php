<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Models;

use WordPress\AiClient\Providers\ApiBasedImplementation\Contracts\ApiBasedModelInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Class for an OpenRouter model that supports both text and image generation.
 *
 * OpenRouter uses the same model ID for both, so this delegates text generation to the chat completions API and
 * image generation to the images API.
 *
 * Based on the Vercel AI Gateway provider, except that images are generated through the images API.
 *
 * @since 0.1.0
 *
 * @link https://github.com/vercel-labs/ai-gateway-provider-php/blob/66cde97/src/Models/AiGatewayTextAndImageGenerationModel.php
 */
class OpenRouterTextAndImageGenerationModel implements
    ApiBasedModelInterface,
    WithHttpTransporterInterface,
    WithRequestAuthenticationInterface,
    TextGenerationModelInterface,
    ImageGenerationModelInterface
{
    /**
     * The model used for text generation.
     *
     * @since 0.1.0
     *
     * @var OpenRouterTextGenerationModel
     */
    private OpenRouterTextGenerationModel $textModel;

    /**
     * The model used for image generation.
     *
     * @since 0.1.0
     *
     * @var OpenRouterImageGenerationModel
     */
    private OpenRouterImageGenerationModel $imageModel;

    /**
     * Constructor.
     *
     * @since 0.1.0
     *
     * @param ModelMetadata    $metadata         The model metadata.
     * @param ProviderMetadata $providerMetadata The provider metadata.
     */
    public function __construct(ModelMetadata $metadata, ProviderMetadata $providerMetadata)
    {
        $this->textModel = new OpenRouterTextGenerationModel($metadata, $providerMetadata);
        $this->imageModel = new OpenRouterImageGenerationModel($metadata, $providerMetadata);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function metadata(): ModelMetadata
    {
        return $this->textModel->metadata();
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function providerMetadata(): ProviderMetadata
    {
        return $this->textModel->providerMetadata();
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function setConfig(ModelConfig $config): void
    {
        $this->textModel->setConfig($config);
        $this->imageModel->setConfig($config);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function getConfig(): ModelConfig
    {
        return $this->textModel->getConfig();
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function setRequestOptions(RequestOptions $requestOptions): void
    {
        $this->textModel->setRequestOptions($requestOptions);
        $this->imageModel->setRequestOptions($requestOptions);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function getRequestOptions(): ?RequestOptions
    {
        return $this->textModel->getRequestOptions();
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function setHttpTransporter(HttpTransporterInterface $httpTransporter): void
    {
        $this->textModel->setHttpTransporter($httpTransporter);
        $this->imageModel->setHttpTransporter($httpTransporter);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function getHttpTransporter(): HttpTransporterInterface
    {
        return $this->textModel->getHttpTransporter();
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function setRequestAuthentication(RequestAuthenticationInterface $requestAuthentication): void
    {
        $this->textModel->setRequestAuthentication($requestAuthentication);
        $this->imageModel->setRequestAuthentication($requestAuthentication);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        return $this->textModel->getRequestAuthentication();
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function generateTextResult(array $prompt): GenerativeAiResult
    {
        return $this->textModel->generateTextResult($prompt);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        return $this->imageModel->generateImageResult($prompt);
    }
}
