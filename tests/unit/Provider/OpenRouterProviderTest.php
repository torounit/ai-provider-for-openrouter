<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Tests\unit\Provider;

use PHPUnit\Framework\TestCase;
use ToroUnit\OpenRouterAiProvider\Metadata\OpenRouterModelMetadataDirectory;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterEmbeddingGenerationModel;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterImageGenerationModel;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterTextAndImageGenerationModel;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterTextAndSpeechGenerationModel;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterTextGenerationModel;
use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProviderAvailability;
use ToroUnit\OpenRouterAiProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\ProviderRegistry;

/**
 * @covers \ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider
 */
class OpenRouterProviderTest extends TestCase
{
    public function testMetadata(): void
    {
        $metadata = OpenRouterProvider::metadata();

        $this->assertSame('openrouter', $metadata->getId());
        $this->assertSame('OpenRouter', $metadata->getName());
        $this->assertTrue($metadata->getType()->isCloud());
        $this->assertSame('https://openrouter.ai/settings/keys', $metadata->getCredentialsUrl());
        $authenticationMethod = $metadata->getAuthenticationMethod();
        $this->assertNotNull($authenticationMethod);
        $this->assertTrue($authenticationMethod->isApiKey());
        $this->assertNotEmpty($metadata->getDescription());
    }

    public function testUrl(): void
    {
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', OpenRouterProvider::url('chat/completions'));
    }

    public function testUsesApiKeyBasedAvailability(): void
    {
        $this->assertInstanceOf(OpenRouterProviderAvailability::class, OpenRouterProvider::availability());
    }

    public function testUsesOpenRouterModelMetadataDirectory(): void
    {
        $this->assertInstanceOf(
            OpenRouterModelMetadataDirectory::class,
            OpenRouterProvider::modelMetadataDirectory()
        );
    }

    public function testCreatesTextGenerationModel(): void
    {
        $model = $this->createModel(
            new ModelMetadata(
                'openrouter/auto',
                'Auto Router',
                [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()],
                []
            )
        );

        $this->assertInstanceOf(OpenRouterTextGenerationModel::class, $model);
        $this->assertSame('openrouter/auto', $model->metadata()->getId());
    }

    public function testCreatesEmbeddingGenerationModel(): void
    {
        $model = $this->createModel(
            new ModelMetadata(
                'nvidia/nemotron-3.5-embed-1b:free',
                'Nemotron Embed',
                [CapabilityEnum::embeddingGeneration()],
                []
            )
        );

        $this->assertInstanceOf(OpenRouterEmbeddingGenerationModel::class, $model);
    }

    public function testCreatesTextAndImageGenerationModel(): void
    {
        $model = $this->createModel(
            new ModelMetadata(
                'google/gemini-3.1-flash-lite-image',
                'Gemini Flash Lite Image',
                [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory(), CapabilityEnum::imageGeneration()],
                []
            )
        );

        $this->assertInstanceOf(OpenRouterTextAndImageGenerationModel::class, $model);
    }

    public function testCreatesImageGenerationModel(): void
    {
        $model = $this->createModel(
            new ModelMetadata('vendor/image', 'Image', [CapabilityEnum::imageGeneration()], [])
        );

        $this->assertInstanceOf(OpenRouterImageGenerationModel::class, $model);
    }

    public function testCreatesTextAndSpeechGenerationModel(): void
    {
        $model = $this->createModel(
            new ModelMetadata(
                'openai/gpt-audio-mini',
                'GPT Audio Mini',
                [
                    CapabilityEnum::textGeneration(),
                    CapabilityEnum::chatHistory(),
                    CapabilityEnum::textToSpeechConversion(),
                ],
                []
            )
        );

        $this->assertInstanceOf(OpenRouterTextAndSpeechGenerationModel::class, $model);
    }

    public function testThrowsForUnsupportedCapabilities(): void
    {
        $this->expectException(RuntimeException::class);

        $this->createModel(
            new ModelMetadata('vendor/music', 'Music', [CapabilityEnum::musicGeneration()], [])
        );
    }

    public function testRegistryChecksApiKeyAgainstKeyEndpoint(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(200, [], '{"data":{}}'),
        ]);
        $registry = new ProviderRegistry();
        $registry->setHttpTransporter($transporter);
        $registry->registerProvider(OpenRouterProvider::class);
        $registry->setProviderRequestAuthentication(
            'openrouter',
            new ApiKeyRequestAuthentication('sk-or-registry-' . uniqid())
        );

        $this->assertTrue($registry->isProviderConfigured('openrouter'));

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('https://openrouter.ai/api/v1/key', $request->getUri());
    }

    public function testRegistryResolvesFlatModelIdForRequests(): void
    {
        $fixtures = dirname(__DIR__, 2) . '/fixtures';
        $transporter = new MockHttpTransporter([
            new Response(200, [], (string) file_get_contents($fixtures . '/models.json')),
            new Response(200, [], (string) file_get_contents($fixtures . '/embedding-models.json')),
            new Response(200, [], (string) json_encode([
                'choices' => [
                    ['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Hi']],
                ],
            ])),
        ]);
        $registry = new ProviderRegistry();
        $registry->setHttpTransporter($transporter);
        $registry->registerProvider(OpenRouterProvider::class);
        $registry->setProviderRequestAuthentication('openrouter', new ApiKeyRequestAuthentication('sk-or-test'));
        OpenRouterProvider::modelMetadataDirectory()->invalidateCaches();

        $model = $registry->getProviderModel('openrouter', 'claude-haiku-5.5');
        $this->assertInstanceOf(OpenRouterTextGenerationModel::class, $model);
        $model->generateTextResult([new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])]);

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $data = $request->getData();
        $this->assertNotNull($data);
        $this->assertSame('anthropic/claude-haiku-5.5', $data['model']);
    }

    /**
     * @dataProvider dataFullModelIdsSettings
     */
    public function testUsesFullModelIdsWhenEnabled(string $value, bool $expected): void
    {
        putenv('OPENROUTER_USE_FULL_MODEL_IDS=' . $value);
        try {
            $provider = new class extends OpenRouterProvider {
                public static function exposeUseFullModelIds(): bool
                {
                    return static::useFullModelIds();
                }
            };

            $this->assertSame($expected, $provider::exposeUseFullModelIds());
        } finally {
            putenv('OPENROUTER_USE_FULL_MODEL_IDS');
        }
    }

    /**
     * @dataProvider dataFullModelIdsSettings
     */
    public function testUsesFreeModelsOnlyWhenEnabled(string $value, bool $expected): void
    {
        putenv('OPENROUTER_FREE_MODELS_ONLY=' . $value);
        try {
            $provider = new class extends OpenRouterProvider {
                public static function exposeFreeModelsOnly(): bool
                {
                    return static::freeModelsOnly();
                }
            };

            $this->assertSame($expected, $provider::exposeFreeModelsOnly());
        } finally {
            putenv('OPENROUTER_FREE_MODELS_ONLY');
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public function dataFullModelIdsSettings(): array
    {
        return [
            'true' => ['true', true],
            'false' => ['false', false],
        ];
    }

    private function createModel(ModelMetadata $modelMetadata): ModelInterface
    {
        $provider = new class extends OpenRouterProvider {
            public static function exposeCreateModel(ModelMetadata $modelMetadata): ModelInterface
            {
                return static::createModel($modelMetadata, static::metadata());
            }
        };

        return $provider::exposeCreateModel($modelMetadata);
    }
}
