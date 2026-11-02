<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Metadata;

use ToroUnit\OpenRouterAiProvider\Models\OpenRouterTextToSpeechConversionModel;
use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

/**
 * Class for the OpenRouter model metadata directory.
 *
 * Unlike most providers, OpenRouter returns the supported parameters and modalities for each model, so the model
 * metadata is derived from the API response rather than hardcoded.
 *
 * @since 0.1.0
 *
 * @phpstan-type ModelData array{
 *     id: string,
 *     name?: string,
 *     architecture?: array{input_modalities?: list<string>, output_modalities?: list<string>},
 *     supported_parameters?: list<string>,
 *     endpoints?: list<string>
 * }
 * @phpstan-type ModelsResponseData array{
 *     data: list<ModelData>
 * }
 */
class OpenRouterModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * The endpoint a model must support to be usable for text generation.
     *
     * Models without it (e.g. the `:batch` variants) can only be used through other APIs.
     *
     * @since 0.1.0
     *
     * @var string
     */
    private const CHAT_COMPLETIONS_ENDPOINT = '/api/v1/chat/completions';

    /**
     * The endpoint a model must support to be usable for image generation.
     *
     * @since 0.1.0
     *
     * @var string
     */
    private const IMAGES_ENDPOINT = '/api/v1/images';

    /**
     * The image formats that the images API can return.
     *
     * @since 0.1.0
     *
     * @var list<string>
     */
    private const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    /**
     * The model ID that is sorted first, so that it is used when no model preference is given.
     *
     * The free models router picks one of the free models for each prompt, so that using the provider without a
     * model preference does not incur costs.
     *
     * @since 0.1.0
     *
     * @var string
     */
    private const DEFAULT_MODEL_ID = 'openrouter/free';

    /**
     * The cache key suffix for the map of exposed model IDs to OpenRouter's model IDs.
     *
     * @since 0.1.0
     *
     * @var string
     */
    private const MODEL_IDS_CACHE_KEY = 'model_ids';

    /**
     * Time in seconds to cache the model IDs, matching the models list.
     *
     * @since 0.1.0
     *
     * @var int
     */
    private const MODEL_IDS_CACHE_TTL = 86400;

    /**
     * Whether to expose OpenRouter's model IDs, including the vendor prefix.
     *
     * @since 0.1.0
     *
     * @var bool
     */
    private bool $useFullModelIds;

    /**
     * Map of exposed model IDs to OpenRouter's model IDs.
     *
     * @since 0.1.0
     *
     * @var array<string, string>
     */
    private array $openRouterModelIds = [];

    /**
     * Constructor.
     *
     * @since 0.1.0
     *
     * @param bool $useFullModelIds Whether to expose OpenRouter's model IDs, including the vendor prefix.
     */
    public function __construct(bool $useFullModelIds = false)
    {
        $this->useFullModelIds = $useFullModelIds;
    }

    /**
     * {@inheritDoc}
     *
     * Embedding models are listed by a separate endpoint, so they are added after the text generation models.
     *
     * @since 0.1.0
     */
    protected function sendListModelsRequest(): array
    {
        $this->openRouterModelIds = [];

        $modelMetadataMap = parent::sendListModelsRequest();

        $request = $this->createRequest(HttpMethodEnum::GET(), 'embeddings/models');
        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);
        $this->throwIfNotSuccessful($response);

        foreach ($this->parseResponseToEmbeddingModelMetadataList($response) as $modelMetadata) {
            $modelMetadataMap[$modelMetadata->getId()] = $modelMetadata;
        }

        // The models list may later be read from the cache, so the model IDs are cached along with it.
        $this->setCache(self::MODEL_IDS_CACHE_KEY, $this->openRouterModelIds, self::MODEL_IDS_CACHE_TTL);

        return $modelMetadataMap;
    }

    /**
     * Returns OpenRouter's model ID for the given model ID exposed to the AI Client.
     *
     * @since 0.1.0
     *
     * @param string $modelId The exposed model ID, e.g. `claude-opus-4.7`.
     * @return string OpenRouter's model ID, e.g. `anthropic/claude-opus-4.7`.
     *
     * @throws InvalidArgumentException If the model ID is unknown.
     */
    public function getOpenRouterModelId(string $modelId): string
    {
        if (!isset($this->openRouterModelIds[$modelId])) {
            $cachedModelIds = $this->getCache(self::MODEL_IDS_CACHE_KEY);
            if (is_array($cachedModelIds)) {
                foreach ($cachedModelIds as $cachedModelId => $openRouterModelId) {
                    if (is_string($cachedModelId) && is_string($openRouterModelId)) {
                        $this->openRouterModelIds[$cachedModelId] = $openRouterModelId;
                    }
                }
            }
        }
        if (!isset($this->openRouterModelIds[$modelId])) {
            // Neither known from this request nor cached, e.g. after the cache was flushed partially.
            $this->sendListModelsRequest();
        }
        if (!isset($this->openRouterModelIds[$modelId])) {
            throw new InvalidArgumentException(
                sprintf('No OpenRouter model ID found for model "%s".', $modelId)
            );
        }
        return $this->openRouterModelIds[$modelId];
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function getCachedKeys(): array
    {
        return array_merge(parent::getCachedKeys(), [self::MODEL_IDS_CACHE_KEY]);
    }

    /**
     * Returns the model ID to expose for the given OpenRouter model ID, and remembers the mapping.
     *
     * The vendor prefix is removed, so that the model IDs match those of other providers. OpenRouter's own models
     * keep it, and so does a model whose ID without vendor is already taken by another model.
     *
     * @since 0.1.0
     *
     * @param string $openRouterModelId OpenRouter's model ID.
     * @return string The model ID to expose.
     */
    private function exposeModelId(string $openRouterModelId): string
    {
        $modelId = $openRouterModelId;
        $slashPosition = strpos($openRouterModelId, '/');
        if (
            !$this->useFullModelIds &&
            $slashPosition !== false &&
            !str_starts_with($openRouterModelId, 'openrouter/')
        ) {
            $flatModelId = substr($openRouterModelId, $slashPosition + 1);
            if (!isset($this->openRouterModelIds[$flatModelId])) {
                $modelId = $flatModelId;
            }
        }

        $this->openRouterModelIds[$modelId] = $openRouterModelId;
        return $modelId;
    }

    /**
     * Returns an alias to expose for an Anthropic model, and remembers the mapping.
     *
     * Anthropic's own model IDs use dashes (e.g. `claude-opus-4-7`), while OpenRouter uses dots
     * (e.g. `claude-opus-4.7`), so the dashed form is added to match the Anthropic provider.
     *
     * @since 0.1.0
     *
     * @param string $openRouterModelId OpenRouter's model ID.
     * @param string $modelId           The exposed model ID.
     * @return string|null The alias, or null if there is none.
     */
    private function exposeModelIdAlias(string $openRouterModelId, string $modelId): ?string
    {
        if ($this->useFullModelIds || !str_starts_with($openRouterModelId, 'anthropic/')) {
            return null;
        }
        $aliasModelId = str_replace('.', '-', $modelId);
        if ($aliasModelId === $modelId || isset($this->openRouterModelIds[$aliasModelId])) {
            return null;
        }

        $this->openRouterModelIds[$aliasModelId] = $openRouterModelId;
        return $aliasModelId;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        return new Request(
            $method,
            OpenRouterProvider::url($path),
            $headers,
            $data
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        /** @var ModelsResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['data']) || !$responseData['data']) {
            throw ResponseException::fromMissingData('OpenRouter', 'data');
        }

        $models = [];
        foreach ((array) $responseData['data'] as $modelData) {
            if (!is_array($modelData) || !isset($modelData['id']) || !is_string($modelData['id'])) {
                continue;
            }
            if ($this->supportsChatCompletions($modelData)) {
                $modelMetadata = $this->createTextModelMetadata($modelData);
            } elseif (in_array(self::IMAGES_ENDPOINT, $modelData['endpoints'] ?? [], true)) {
                $modelMetadata = $this->createImageModelMetadata($modelData);
            } else {
                continue;
            }
            $models[] = $modelMetadata;

            $aliasModelId = $this->exposeModelIdAlias($modelData['id'], $modelMetadata->getId());
            if ($aliasModelId !== null) {
                $models[] = new ModelMetadata(
                    $aliasModelId,
                    $modelMetadata->getName(),
                    $modelMetadata->getSupportedCapabilities(),
                    $modelMetadata->getSupportedOptions()
                );
            }
        }

        return $this->sortModels($models);
    }

    /**
     * Parses the response from the API endpoint to list embedding models into a list of model metadata objects.
     *
     * @since 0.1.0
     *
     * @param Response $response The response from the API endpoint to list embedding models.
     * @return list<ModelMetadata> List of model metadata objects.
     */
    protected function parseResponseToEmbeddingModelMetadataList(Response $response): array
    {
        /** @var ModelsResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['data']) || !is_array($responseData['data'])) {
            throw ResponseException::fromMissingData('OpenRouter', 'data');
        }

        $models = [];
        foreach ($responseData['data'] as $modelData) {
            if (!is_array($modelData) || !isset($modelData['id']) || !is_string($modelData['id'])) {
                continue;
            }

            // Like the AI Provider for OpenAI, only text inputs are supported.
            $options = [
                new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            ];
            /*
             * The API does not list the dimensions parameter for any model yet, and most of them reject it.
             * OpenAI's third generation models are known to support it.
             */
            if (
                in_array('dimensions', $modelData['supported_parameters'] ?? [], true) ||
                str_starts_with($modelData['id'], 'openai/text-embedding-3-')
            ) {
                $options[] = new SupportedOption(OptionEnum::dimensions());
            }
            $options[] = new SupportedOption(OptionEnum::customOptions());

            $models[] = new ModelMetadata(
                $this->exposeModelId($modelData['id']),
                $modelData['name'] ?? $modelData['id'],
                [CapabilityEnum::embeddingGeneration()],
                $options
            );
        }

        return $models;
    }

    /**
     * Checks whether the given model can be used through the chat completions API.
     *
     * @since 0.1.0
     *
     * @param ModelData $modelData The model data from the API response.
     * @return bool True if the model supports chat completions, false otherwise.
     */
    private function supportsChatCompletions(array $modelData): bool
    {
        $outputModalities = $modelData['architecture']['output_modalities'] ?? ['text'];
        if (!in_array('text', $outputModalities, true)) {
            return false;
        }

        // Assume support if the API does not list endpoints at all.
        if (!isset($modelData['endpoints'])) {
            return true;
        }
        return in_array(self::CHAT_COMPLETIONS_ENDPOINT, $modelData['endpoints'], true);
    }

    /**
     * Creates the metadata for a text generation model.
     *
     * @since 0.1.0
     *
     * @param ModelData $modelData The model data from the API response.
     * @return ModelMetadata The model metadata.
     */
    private function createTextModelMetadata(array $modelData): ModelMetadata
    {
        $supportedParameters = $modelData['supported_parameters'] ?? [];

        $options = [
            new SupportedOption(OptionEnum::systemInstruction()),
        ];
        // OpenRouter accepts max_tokens for all models, including those that only list max_completion_tokens.
        if (in_array('max_completion_tokens', $supportedParameters, true)) {
            $supportedParameters[] = 'max_tokens';
        }
        foreach ($this->getParameterOptions() as $parameter => $option) {
            if (in_array($parameter, $supportedParameters, true)) {
                $options[] = new SupportedOption($option);
            }
        }
        $supportsImageGeneration = in_array(self::IMAGES_ENDPOINT, $modelData['endpoints'] ?? [], true);

        $inputModalities = $modelData['architecture']['input_modalities'] ?? ['text'];
        $outputModalities = $modelData['architecture']['output_modalities'] ?? ['text'];
        /*
         * Models that take and output audio can speak a given text. Other models that output audio, such as Lyria,
         * generate music instead. Models that also generate images are left out, since a model can only be combined
         * with one other capability.
         */
        $supportsSpeech = !$supportsImageGeneration
            && in_array('audio', $inputModalities, true)
            && in_array('audio', $outputModalities, true);

        $outputMimeTypes = [];
        if (in_array('response_format', $supportedParameters, true)) {
            $outputMimeTypes = ['text/plain', 'application/json'];
        }
        if ($supportsImageGeneration) {
            $outputMimeTypes = array_merge($outputMimeTypes, self::IMAGE_MIME_TYPES);
        }
        if ($supportsSpeech) {
            $outputMimeTypes[] = OpenRouterTextToSpeechConversionModel::MIME_TYPE;
        }
        if ($outputMimeTypes) {
            $options[] = new SupportedOption(OptionEnum::outputMimeType(), $outputMimeTypes);
        }
        // The web search plugin works with any model.
        $options[] = new SupportedOption(OptionEnum::webSearch());
        $options[] = new SupportedOption(OptionEnum::customOptions());
        $options[] = new SupportedOption(
            OptionEnum::inputModalities(),
            $this->getInputModalityCombinations($inputModalities)
        );

        $capabilities = [
            CapabilityEnum::textGeneration(),
            CapabilityEnum::chatHistory(),
        ];
        $outputModalityOptions = [[ModalityEnum::text()]];
        if ($supportsImageGeneration) {
            $capabilities[] = CapabilityEnum::imageGeneration();
            $outputModalityOptions[] = [ModalityEnum::image()];
            $options = array_merge($options, $this->getImageOptions());
        }
        if ($supportsSpeech) {
            $capabilities[] = CapabilityEnum::textToSpeechConversion();
            $outputModalityOptions[] = [ModalityEnum::audio()];
            $options[] = new SupportedOption(OptionEnum::outputSpeechVoice());
        }
        if ($supportsImageGeneration || $supportsSpeech) {
            // Generated images and speech are always returned as base64 data.
            $options[] = new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline()]);
        }
        $options[] = new SupportedOption(OptionEnum::outputModalities(), $outputModalityOptions);

        return new ModelMetadata(
            $this->exposeModelId($modelData['id']),
            $modelData['name'] ?? $modelData['id'],
            $capabilities,
            $options
        );
    }

    /**
     * Creates the metadata for an image generation model that does not support chat completions.
     *
     * @since 0.1.0
     *
     * @param ModelData $modelData The model data from the API response.
     * @return ModelMetadata The model metadata.
     */
    private function createImageModelMetadata(array $modelData): ModelMetadata
    {
        return new ModelMetadata(
            $this->exposeModelId($modelData['id']),
            $modelData['name'] ?? $modelData['id'],
            [CapabilityEnum::imageGeneration()],
            array_merge(
                [
                    new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
                    new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
                    new SupportedOption(OptionEnum::outputMimeType(), self::IMAGE_MIME_TYPES),
                    new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline()]),
                    new SupportedOption(OptionEnum::customOptions()),
                ],
                $this->getImageOptions()
            )
        );
    }

    /**
     * Returns the options specific to the images API, other than the output file type.
     *
     * @since 0.1.0
     *
     * @return list<SupportedOption> The image generation options.
     */
    private function getImageOptions(): array
    {
        return [
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::outputMediaOrientation()),
            new SupportedOption(OptionEnum::outputMediaAspectRatio()),
        ];
    }

    /**
     * Returns all combinations of input modalities that can be sent to a model, each including text.
     *
     * @since 0.1.0
     *
     * @param list<string> $inputModalities The input modalities from the API response.
     * @return list<list<ModalityEnum>> The supported input modality combinations.
     */
    private function getInputModalityCombinations(array $inputModalities): array
    {
        $combinations = [[ModalityEnum::text()]];
        $inputModalityMap = [
            'image' => ModalityEnum::image(),
            'audio' => ModalityEnum::audio(),
            'file' => ModalityEnum::document(),
        ];
        foreach ($inputModalityMap as $inputModality => $modality) {
            if (!in_array($inputModality, $inputModalities, true)) {
                continue;
            }
            foreach ($combinations as $combination) {
                $combination[] = $modality;
                $combinations[] = $combination;
            }
        }
        return $combinations;
    }

    /**
     * Returns the map of OpenRouter supported parameters to the options they enable.
     *
     * @since 0.1.0
     *
     * @return array<string, OptionEnum> The map of parameter name to option.
     */
    private function getParameterOptions(): array
    {
        return [
            'max_tokens' => OptionEnum::maxTokens(),
            'temperature' => OptionEnum::temperature(),
            'top_p' => OptionEnum::topP(),
            'top_k' => OptionEnum::topK(),
            'stop' => OptionEnum::stopSequences(),
            'presence_penalty' => OptionEnum::presencePenalty(),
            'frequency_penalty' => OptionEnum::frequencyPenalty(),
            'logprobs' => OptionEnum::logprobs(),
            'top_logprobs' => OptionEnum::topLogprobs(),
            'tools' => OptionEnum::functionDeclarations(),
            'structured_outputs' => OptionEnum::outputSchema(),
        ];
    }

    /**
     * Sorts the default model first, keeping the API order (newest first) for all other models.
     *
     * @since 0.1.0
     *
     * @param list<ModelMetadata> $models The models to sort.
     * @return list<ModelMetadata> The sorted models.
     */
    private function sortModels(array $models): array
    {
        $defaultModels = [];
        $otherModels = [];
        foreach ($models as $model) {
            if ($model->getId() === self::DEFAULT_MODEL_ID) {
                $defaultModels[] = $model;
            } else {
                $otherModels[] = $model;
            }
        }
        return array_merge($defaultModels, $otherModels);
    }
}
