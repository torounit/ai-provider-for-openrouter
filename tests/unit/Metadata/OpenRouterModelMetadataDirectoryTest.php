<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Tests\unit\Metadata;

use PHPUnit\Framework\TestCase;
use ToroUnit\OpenRouterAiProvider\Metadata\OpenRouterModelMetadataDirectory;
use ToroUnit\OpenRouterAiProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * @covers \ToroUnit\OpenRouterAiProvider\Metadata\OpenRouterModelMetadataDirectory
 */
class OpenRouterModelMetadataDirectoryTest extends TestCase
{
    public function testListsChatModelsWithFreeRouterFirst(): void
    {
        // The free models router comes first so that prompts without a model preference cost nothing.
        $this->assertSame(
            [
                'openrouter/free',
                'anthropic/claude-haiku-5.5',
                'nvidia/nemotron-3.5-super-vl:free',
                'meta/muse-spark-open',
                'google/gemini-nano-banana-2.1',
                'openrouter/auto',
            ],
            array_keys($this->parseFixture())
        );
    }

    public function testExcludesModelsWithoutChatCompletionsEndpoint(): void
    {
        $models = $this->parseResponse([
            ['id' => 'vendor/model:batch', 'endpoints' => []],
            ['id' => 'vendor/model', 'endpoints' => ['/api/v1/chat/completions']],
            ['id' => 'vendor/legacy'],
            [
                'id' => 'vendor/speech',
                'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['audio']],
            ],
        ]);

        $this->assertSame(['vendor/model', 'vendor/legacy'], array_keys($models));
    }

    public function testDerivesOptionsFromSupportedParameters(): void
    {
        $model = $this->findModel($this->parseFixture(), 'anthropic/claude-haiku-5.5');
        $capabilities = $model->getSupportedCapabilities();

        $this->assertTrue($capabilities[0]->isTextGeneration());
        $this->assertTrue($capabilities[1]->isChatHistory());

        $optionNames = $this->getOptionNames($model);
        foreach (
            [
                OptionEnum::systemInstruction(),
                OptionEnum::maxTokens(),
                OptionEnum::stopSequences(),
                OptionEnum::functionDeclarations(),
                OptionEnum::outputSchema(),
                OptionEnum::outputMimeType(),
                OptionEnum::customOptions(),
            ] as $expectedOption
        ) {
            $this->assertContains((string) $expectedOption, $optionNames);
        }

        // Not listed in the model's supported parameters.
        $this->assertNotContains((string) OptionEnum::temperature(), $optionNames);
        $this->assertNotContains((string) OptionEnum::topK(), $optionNames);
    }

    public function testAdvertisesMaxTokensForMaxCompletionTokens(): void
    {
        $models = $this->parseResponse([
            ['id' => 'openai/codex', 'supported_parameters' => ['max_completion_tokens']],
            ['id' => 'vendor/no-limit', 'supported_parameters' => []],
        ]);

        $this->assertContains((string) OptionEnum::maxTokens(), $this->getOptionNames($models['openai/codex']));
        $this->assertNotContains((string) OptionEnum::maxTokens(), $this->getOptionNames($models['vendor/no-limit']));
    }

    public function testAdvertisesInputModalityCombinations(): void
    {
        $models = $this->parseFixture();

        $textOnly = $this->getInputModalitiesOption($this->findModel($models, 'anthropic/claude-haiku-5.5'));
        $this->assertTrue($textOnly->isSupportedValue([ModalityEnum::text(), ModalityEnum::image()]));
        $this->assertFalse($textOnly->isSupportedValue([ModalityEnum::text(), ModalityEnum::audio()]));
        $this->assertTrue($textOnly->isSupportedValue(
            [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::document()]
        ));

        $withoutFiles = $this->getInputModalitiesOption(
            $this->findModel($models, 'nvidia/nemotron-3.5-super-vl:free')
        );
        $this->assertFalse($withoutFiles->isSupportedValue([ModalityEnum::text(), ModalityEnum::document()]));

        $multimodal = $this->getInputModalitiesOption($this->findModel($models, 'meta/muse-spark-open'));
        $this->assertTrue($multimodal->isSupportedValue([ModalityEnum::text()]));
        $this->assertTrue($multimodal->isSupportedValue([ModalityEnum::text(), ModalityEnum::audio()]));
        $this->assertTrue($multimodal->isSupportedValue(
            [ModalityEnum::audio(), ModalityEnum::text(), ModalityEnum::image()]
        ));
    }

    public function testListModelMetadataRequestsModelsEndpoint(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(200, [], (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/models.json')),
        ]);
        $directory = new OpenRouterModelMetadataDirectory();
        $directory->setHttpTransporter($transporter);
        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication('sk-or-test'));

        $this->assertTrue($directory->hasModelMetadata('openrouter/auto'));

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('https://openrouter.ai/api/v1/models', $request->getUri());
    }

    /**
     * @return array<string, ModelMetadata>
     */
    private function parseFixture(): array
    {
        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/models.json'),
            true
        );
        return $this->parseResponse($fixture['data']);
    }

    /**
     * @param list<array<string, mixed>> $data
     * @return array<string, ModelMetadata> Map of model ID to metadata, in list order.
     */
    private function parseResponse(array $data): array
    {
        $directory = new class extends OpenRouterModelMetadataDirectory {
            public function exposeParseResponseToModelMetadataList(Response $response): array
            {
                return $this->parseResponseToModelMetadataList($response);
            }
        };

        $models = $directory->exposeParseResponseToModelMetadataList(
            new Response(200, [], (string) json_encode(['data' => $data]))
        );

        $map = [];
        foreach ($models as $model) {
            $map[$model->getId()] = $model;
        }
        return $map;
    }

    /**
     * @param array<string, ModelMetadata> $models
     */
    private function findModel(array $models, string $id): ModelMetadata
    {
        $this->assertArrayHasKey($id, $models);
        return $models[$id];
    }

    /**
     * @return list<string>
     */
    private function getOptionNames(ModelMetadata $model): array
    {
        return array_map(
            static function (SupportedOption $option): string {
                return (string) $option->getName();
            },
            $model->getSupportedOptions()
        );
    }

    private function getInputModalitiesOption(ModelMetadata $model): SupportedOption
    {
        foreach ($model->getSupportedOptions() as $option) {
            if ($option->getName()->isInputModalities()) {
                return $option;
            }
        }
        $this->fail('The model does not advertise input modalities.');
    }
}
