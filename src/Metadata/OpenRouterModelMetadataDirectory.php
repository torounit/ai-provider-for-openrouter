<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Metadata;

use ToroUnit\OpenRouterAiProvider\Authentication\OpenRouterRequestAuthentication;
use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
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
     * The model ID that is sorted first, so that it is used when no model preference is given.
     *
     * The auto router picks a suitable model for each prompt.
     *
     * @since 0.1.0
     *
     * @var string
     */
    private const DEFAULT_MODEL_ID = 'openrouter/auto';

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        return OpenRouterRequestAuthentication::wrap(parent::getRequestAuthentication());
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
            if (!$this->supportsChatCompletions($modelData)) {
                continue;
            }
            $models[] = $this->createTextModelMetadata($modelData);
        }

        return $this->sortModels($models);
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
        foreach ($this->getParameterOptions() as $parameter => $option) {
            if (in_array($parameter, $supportedParameters, true)) {
                $options[] = new SupportedOption($option);
            }
        }
        if (in_array('response_format', $supportedParameters, true)) {
            $options[] = new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']);
        }
        $options[] = new SupportedOption(OptionEnum::customOptions());
        $options[] = new SupportedOption(
            OptionEnum::inputModalities(),
            $this->getInputModalityCombinations($modelData['architecture']['input_modalities'] ?? ['text'])
        );
        $options[] = new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]);

        return new ModelMetadata(
            $modelData['id'],
            $modelData['name'] ?? $modelData['id'],
            [
                CapabilityEnum::textGeneration(),
                CapabilityEnum::chatHistory(),
            ],
            $options
        );
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
