<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Models;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\ApiBasedImplementation\Contracts\ApiBasedModelInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Base class for an OpenRouter model that supports text generation and generation of another media type.
 *
 * OpenRouter uses the same model ID for both, so this delegates text generation to a text generation model and the
 * other capability to a separate model, keeping the configuration of both in sync.
 *
 * @since 0.1.0
 */
abstract class AbstractOpenRouterTextAndMediaGenerationModel implements
    ApiBasedModelInterface,
    WithHttpTransporterInterface,
    WithRequestAuthenticationInterface,
    TextGenerationModelInterface
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
     * The model used for the other capability.
     *
     * @since 0.1.0
     *
     * @var AbstractApiBasedModel
     */
    private AbstractApiBasedModel $mediaModel;

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
        $this->mediaModel = $this->createMediaModel($metadata, $providerMetadata);
    }

    /**
     * Creates the model used for the other capability.
     *
     * @since 0.1.0
     *
     * @param ModelMetadata    $metadata         The model metadata.
     * @param ProviderMetadata $providerMetadata The provider metadata.
     * @return AbstractApiBasedModel The model.
     */
    abstract protected function createMediaModel(
        ModelMetadata $metadata,
        ProviderMetadata $providerMetadata
    ): AbstractApiBasedModel;

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
        $this->mediaModel->setConfig($config);
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
        $this->mediaModel->setRequestOptions($requestOptions);
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
        $this->mediaModel->setHttpTransporter($httpTransporter);
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
        $this->mediaModel->setRequestAuthentication($requestAuthentication);
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
}
