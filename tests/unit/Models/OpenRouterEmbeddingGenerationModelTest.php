<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Tests\unit\Models;

use PHPUnit\Framework\TestCase;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterEmbeddingGenerationModel;
use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use ToroUnit\OpenRouterAiProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * @covers \ToroUnit\OpenRouterAiProvider\Models\OpenRouterEmbeddingGenerationModel
 */
class OpenRouterEmbeddingGenerationModelTest extends TestCase
{
    public function testGenerateEmbeddingResultSendsEmbeddingsRequest(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(200, [], (string) json_encode([
                'id' => 'gen-emb-1',
                'object' => 'list',
                'model' => 'nvidia/nemotron-3.5-embed-1b:free',
                'provider' => 'Nvidia',
                'data' => [
                    ['object' => 'embedding', 'embedding' => [0.1, 0.2, 0.3], 'index' => 0],
                    ['object' => 'embedding', 'embedding' => [0.4, 0.5, 0.6], 'index' => 1],
                ],
                'usage' => ['prompt_tokens' => 6, 'total_tokens' => 6, 'cost' => 0],
            ])),
        ]);
        $model = $this->createModel($transporter);

        $result = $model->generateEmbeddingResult([new MessagePart('Hello'), new MessagePart('World')]);

        $this->assertSame('gen-emb-1', $result->getId());
        $this->assertCount(2, $result->getEmbeddings());
        $this->assertSame([0.4, 0.5, 0.6], $result->getEmbeddings()[1]->getValues());
        $this->assertSame(3, $result->getDimensions());
        $this->assertSame(6, $result->getTokenUsage()->getTotalTokens());
        $this->assertSame('Nvidia', $result->getAdditionalData()['provider']);

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('https://openrouter.ai/api/v1/embeddings', $request->getUri());
        $this->assertSame('Bearer sk-or-test', $request->getHeaderAsString('Authorization'));
        $this->assertSame(
            ['model' => 'nvidia/nemotron-3.5-embed-1b:free', 'input' => ['Hello', 'World']],
            $request->getData()
        );
    }

    public function testGenerateEmbeddingResultSendsDimensionsAndCustomOptions(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(200, [], '{"data":[{"embedding":[0.1,0.2],"index":0}]}'),
        ]);
        $config = new ModelConfig();
        $config->setDimensions(256);
        $config->setCustomOptions(['provider' => ['order' => ['openai']]]);
        $model = $this->createModel($transporter);
        $model->setConfig($config);

        $model->generateEmbeddingResult([new MessagePart('Hello')]);

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $data = $request->getData();
        $this->assertNotNull($data);
        $this->assertSame(256, $data['dimensions']);
        $this->assertSame(['order' => ['openai']], $data['provider']);
    }

    public function testRejectsConflictingCustomOptions(): void
    {
        $config = new ModelConfig();
        $config->setCustomOptions(['input' => ['Other']]);
        $model = $this->createModel(new MockHttpTransporter());
        $model->setConfig($config);

        $this->expectException(InvalidArgumentException::class);

        $model->generateEmbeddingResult([new MessagePart('Hello')]);
    }

    public function testRejectsEmptyInput(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createModel(new MockHttpTransporter())->generateEmbeddingResult([]);
    }

    public function testRejectsNonTextInput(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createModel(new MockHttpTransporter())->generateEmbeddingResult([
            new MessagePart(new File('https://example.com/image.png', 'image/png')),
        ]);
    }

    public function testRejectsBlankText(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createModel(new MockHttpTransporter())->generateEmbeddingResult([new MessagePart('  ')]);
    }

    public function testThrowsWhenDataIsMissing(): void
    {
        $model = $this->createModel(new MockHttpTransporter([new Response(200, [], '{"data":[]}')]));

        $this->expectException(ResponseException::class);

        $model->generateEmbeddingResult([new MessagePart('Hello')]);
    }

    public function testThrowsOnErrorResponse(): void
    {
        $model = $this->createModel(new MockHttpTransporter([
            new Response(400, [], '{"error":{"message":"Bad dimensions","code":400}}'),
        ]));

        $this->expectException(ClientException::class);

        $model->generateEmbeddingResult([new MessagePart('Hello')]);
    }

    private function createModel(MockHttpTransporter $transporter): OpenRouterEmbeddingGenerationModel
    {
        $model = new OpenRouterEmbeddingGenerationModel(
            new ModelMetadata(
                'nvidia/nemotron-3.5-embed-1b:free',
                'Nemotron Embed',
                [CapabilityEnum::embeddingGeneration()],
                []
            ),
            OpenRouterProvider::metadata()
        );
        $model->setHttpTransporter($transporter);
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication('sk-or-test'));
        return $model;
    }
}
