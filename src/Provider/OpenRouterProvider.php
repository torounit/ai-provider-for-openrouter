<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Provider;

use ToroUnit\OpenRouterAiProvider\Metadata\OpenRouterModelMetadataDirectory;
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
        foreach ($capabilities as $capability) {
            if ($capability->isTextGeneration()) {
                return new OpenRouterTextGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException(
            'Unsupported model capabilities: ' . implode(', ', $capabilities)
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $description = 'Text generation with hundreds of models from many providers through a single API key.';
        // For WordPress, we should translate the description.
        if (function_exists('__')) {
            $translatedDescription = __(
                'Text generation with hundreds of models from many providers through a single API key.',
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
