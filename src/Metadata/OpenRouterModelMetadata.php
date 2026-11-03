<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Metadata;

use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Class for the metadata of an OpenRouter model, which also holds OpenRouter's model ID.
 *
 * The model ID exposed to the AI Client can differ from OpenRouter's model ID, e.g. `claude-opus-4.7` for
 * `anthropic/claude-opus-4.7`. Keeping OpenRouter's model ID in the metadata keeps both in sync, including when the
 * models list is read from a cache.
 *
 * @since 0.1.0
 */
class OpenRouterModelMetadata extends ModelMetadata
{
    /**
     * OpenRouter's model ID.
     *
     * @since 0.1.0
     *
     * @var string
     */
    private string $openRouterModelId;

    /**
     * Constructor.
     *
     * @since 0.1.0
     *
     * @param string                 $id                    The model ID exposed to the AI Client.
     * @param string                 $name                  The model name.
     * @param list<CapabilityEnum>   $supportedCapabilities The supported capabilities.
     * @param list<SupportedOption>  $supportedOptions      The supported options.
     * @param string                 $openRouterModelId     OpenRouter's model ID.
     */
    public function __construct(
        string $id,
        string $name,
        array $supportedCapabilities,
        array $supportedOptions,
        string $openRouterModelId
    ) {
        parent::__construct($id, $name, $supportedCapabilities, $supportedOptions);
        $this->openRouterModelId = $openRouterModelId;
    }

    /**
     * Returns OpenRouter's model ID, which is sent in requests.
     *
     * @since 0.1.0
     *
     * @return string OpenRouter's model ID.
     */
    public function getOpenRouterModelId(): string
    {
        return $this->openRouterModelId;
    }
}
