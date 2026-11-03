<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Provider;

use ToroUnit\OpenRouterAiProvider\Metadata\OpenRouterModelMetadataDirectory;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterEmbeddingGenerationModel;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterImageGenerationModel;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterTextAndImageGenerationModel;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterTextGenerationModel;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Class for the AI Provider for OpenRouter.
 *
 * @since 0.1.0
 */
class OpenRouterProvider extends AbstractApiProvider
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function baseUrl(): string
    {
        return 'https://openrouter.ai/api/v1';
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        $capabilities = $modelMetadata->getSupportedCapabilities();

        $supportsTextGeneration = false;
        $supportsImageGeneration = false;
        foreach ($capabilities as $capability) {
            if ($capability->isTextGeneration()) {
                $supportsTextGeneration = true;
            }
            if ($capability->isImageGeneration()) {
                $supportsImageGeneration = true;
            }
            if ($capability->isEmbeddingGeneration()) {
                return new OpenRouterEmbeddingGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        if ($supportsTextGeneration && $supportsImageGeneration) {
            return new OpenRouterTextAndImageGenerationModel($modelMetadata, $providerMetadata);
        }
        if ($supportsTextGeneration) {
            return new OpenRouterTextGenerationModel($modelMetadata, $providerMetadata);
        }
        if ($supportsImageGeneration) {
            return new OpenRouterImageGenerationModel($modelMetadata, $providerMetadata);
        }

        throw new RuntimeException(
            'Unsupported model capabilities: ' . implode(', ', $capabilities)
        );
    }

    /**
     * {@inheritDoc}
     *
     * Based on the AI Provider for OpenAI, without its AI Client version checks, and with the logo only passed
     * when the file exists.
     *
     * @since 0.1.0
     *
     * @link https://github.com/WordPress/ai-provider-for-openai/blob/24ba3be/src/Provider/OpenAiProvider.php
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $description = 'Text, image, and embedding generation with hundreds of models through a single API key.';
        // For WordPress, we should translate the description.
        if (function_exists('__')) {
            $translatedDescription = __(
                'Text, image, and embedding generation with hundreds of models through a single API key.',
                'ai-provider-for-openrouter'
            );
            if (is_string($translatedDescription)) {
                $description = $translatedDescription;
            }
        }

        $pluginDir = dirname(__DIR__, 2);
        if (defined('AI_PROVIDER_FOR_OPENROUTER_PLUGIN_DIR')) {
            $definedPluginDir = constant('AI_PROVIDER_FOR_OPENROUTER_PLUGIN_DIR');
            if (is_string($definedPluginDir)) {
                $pluginDir = $definedPluginDir;
            }
        }
        $logoPath = $pluginDir . '/assets/images/openrouter.svg';

        return new ProviderMetadata(
            'openrouter',
            'OpenRouter',
            ProviderTypeEnum::cloud(),
            'https://openrouter.ai/settings/keys',
            RequestAuthenticationMethod::apiKey(),
            $description,
            file_exists($logoPath) ? $logoPath : null
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        // OpenRouter's models endpoint is public, so the API key must be checked separately.
        return new OpenRouterProviderAvailability();
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new OpenRouterModelMetadataDirectory();
    }
}
