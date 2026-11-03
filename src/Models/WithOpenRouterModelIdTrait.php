<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Models;

use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Trait for models that send OpenRouter's model ID, which can differ from the model ID exposed to the AI Client.
 *
 * The provider exposes model IDs without the vendor prefix (e.g. `claude-opus-4.7` instead of
 * `anthropic/claude-opus-4.7`), so that they match the model IDs of other providers.
 *
 * @since 0.1.0
 */
trait WithOpenRouterModelIdTrait
{
    /**
     * OpenRouter's model ID, or null if it is the same as the model metadata ID.
     *
     * @since 0.1.0
     *
     * @var string|null
     */
    private ?string $openRouterModelId = null;

    /**
     * Constructor.
     *
     * @since 0.1.0
     *
     * @param ModelMetadata    $metadata          The model metadata.
     * @param ProviderMetadata $providerMetadata  The provider metadata.
     * @param string|null      $openRouterModelId OpenRouter's model ID, if it differs from the model metadata ID.
     */
    public function __construct(
        ModelMetadata $metadata,
        ProviderMetadata $providerMetadata,
        ?string $openRouterModelId = null
    ) {
        parent::__construct($metadata, $providerMetadata);
        $this->openRouterModelId = $openRouterModelId;
    }

    /**
     * Returns OpenRouter's model ID, which is sent in requests.
     *
     * @since 0.1.0
     *
     * @return string The model ID.
     */
    protected function getOpenRouterModelId(): string
    {
        return $this->openRouterModelId ?? $this->metadata()->getId();
    }
}
