<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Tests\unit\Models;

use PHPUnit\Framework\TestCase;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterTextGenerationModel;
use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use ToroUnit\OpenRouterAiProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * @covers \ToroUnit\OpenRouterAiProvider\Models\OpenRouterTextGenerationModel
 */
class OpenRouterTextGenerationModelTest extends TestCase
{
    public function testGenerateTextResultSendsChatCompletionsRequest(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(200, [], (string) json_encode([
                'id' => 'gen-123',
                'model' => 'anthropic/claude-haiku-5.5',
                'provider' => 'Anthropic',
                'choices' => [
                    [
                        'index' => 0,
                        'finish_reason' => 'stop',
                        'message' => ['role' => 'assistant', 'content' => 'Hello!'],
                    ],
                ],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
            ])),
        ]);
        $model = $this->createModel($transporter, 'anthropic/claude-haiku-5.5');

        $result = $model->generateTextResult([$this->createUserMessage('Hi')]);

        $this->assertSame('gen-123', $result->getId());
        $this->assertSame('Hello!', $result->toText());
        $this->assertSame(7, $result->getTokenUsage()->getTotalTokens());
        $this->assertSame('Anthropic', $result->getAdditionalData()['provider']);

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $request->getUri());
        $this->assertSame('Bearer sk-or-test', $request->getHeaderAsString('Authorization'));

        $data = $request->getData();
        $this->assertNotNull($data);
        $this->assertSame('anthropic/claude-haiku-5.5', $data['model']);
    }

    public function testPrepareGenerateTextParamsIncludesTopK(): void
    {
        $config = new ModelConfig();
        $config->setTopK(40);
        $config->setTemperature(0.5);

        $params = $this->prepareParams($config);

        $this->assertSame(40, $params['top_k']);
        $this->assertSame(0.5, $params['temperature']);
    }

    public function testPrepareGenerateTextParamsRejectsConflictingTopK(): void
    {
        $config = new ModelConfig();
        $config->setTopK(40);
        $config->setCustomOptions(['top_k' => 10]);

        $this->expectException(InvalidArgumentException::class);

        $this->prepareParams($config);
    }

    public function testPrepareGenerateTextParamsWrapsOutputSchema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['capital' => ['type' => 'string']],
            'required' => ['capital'],
            'additionalProperties' => false,
        ];
        $config = new ModelConfig();
        $config->setOutputMimeType('application/json');
        $config->setOutputSchema($schema);

        $params = $this->prepareParams($config);

        $this->assertSame(
            [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'response_schema',
                    'schema' => $schema,
                ],
            ],
            $params['response_format']
        );
    }

    public function testPrepareGenerateTextParamsSendsInlinePdfAsFile(): void
    {
        $dataUri = 'data:application/pdf;base64,' . base64_encode('%PDF-1.4 test');

        $params = $this->prepareParams(
            new ModelConfig(),
            [new MessagePart('Summarize this.'), new MessagePart(new File($dataUri))]
        );

        $this->assertSame(
            [
                ['type' => 'text', 'text' => 'Summarize this.'],
                [
                    'type' => 'file',
                    'file' => [
                        'filename' => 'document.pdf',
                        'file_data' => $dataUri,
                    ],
                ],
            ],
            $params['messages'][0]['content']
        );
    }

    public function testPrepareGenerateTextParamsSendsRemotePdfAsFile(): void
    {
        $params = $this->prepareParams(
            new ModelConfig(),
            [new MessagePart(new File('https://example.com/report.pdf', 'application/pdf'))]
        );

        $this->assertSame(
            [
                [
                    'type' => 'file',
                    'file' => [
                        'filename' => 'report.pdf',
                        'file_data' => 'https://example.com/report.pdf',
                    ],
                ],
            ],
            $params['messages'][0]['content']
        );
    }

    public function testPrepareGenerateTextParamsSendsOtherDocumentsAsFile(): void
    {
        $params = $this->prepareParams(
            new ModelConfig(),
            [
                new MessagePart(new File(
                    'https://example.com/report.docx',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                )),
            ]
        );

        $this->assertSame(
            [
                [
                    'type' => 'file',
                    'file' => [
                        'filename' => 'report.docx',
                        'file_data' => 'https://example.com/report.docx',
                    ],
                ],
            ],
            $params['messages'][0]['content']
        );
    }

    public function testPrepareGenerateTextParamsSendsInlineTextFileAsFile(): void
    {
        $dataUri = 'data:text/plain;base64,' . base64_encode('Hello');

        $params = $this->prepareParams(new ModelConfig(), [new MessagePart(new File($dataUri))]);

        $this->assertSame(
            [
                [
                    'type' => 'file',
                    'file' => [
                        'filename' => 'document.txt',
                        'file_data' => $dataUri,
                    ],
                ],
            ],
            $params['messages'][0]['content']
        );
    }

    /**
     * @dataProvider dataRemoteFilenames
     */
    public function testPrepareGenerateTextParamsDerivesFilenameFromUrl(string $url, string $expectedFilename): void
    {
        $params = $this->prepareParams(
            new ModelConfig(),
            [new MessagePart(new File($url, 'application/pdf'))]
        );

        $this->assertSame($expectedFilename, $params['messages'][0]['content'][0]['file']['filename']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function dataRemoteFilenames(): array
    {
        return [
            'encoded' => ['https://example.com/My%20Report.pdf', 'My Report.pdf'],
            'without extension' => ['https://example.com/download?id=5', 'download.pdf'],
            'without path' => ['https://example.com', 'document.pdf'],
        ];
    }

    /**
     * @param list<MessagePart>|null $parts
     * @return array<string, mixed>
     */
    private function prepareParams(ModelConfig $config, ?array $parts = null): array
    {
        $model = new class (
            new ModelMetadata('vendor/model', 'Model', [CapabilityEnum::textGeneration()], []),
            OpenRouterProvider::metadata()
        ) extends OpenRouterTextGenerationModel {
            /**
             * @param list<Message> $prompt
             * @return array<string, mixed>
             */
            public function exposePrepareGenerateTextParams(array $prompt): array
            {
                return $this->prepareGenerateTextParams($prompt);
            }
        };
        $model->setConfig($config);

        $message = $parts !== null
            ? new Message(MessageRoleEnum::user(), $parts)
            : $this->createUserMessage('Hi');

        return $model->exposePrepareGenerateTextParams([$message]);
    }

    private function createModel(MockHttpTransporter $transporter, string $modelId): OpenRouterTextGenerationModel
    {
        $model = new OpenRouterTextGenerationModel(
            new ModelMetadata($modelId, $modelId, [CapabilityEnum::textGeneration()], []),
            OpenRouterProvider::metadata()
        );
        $model->setHttpTransporter($transporter);
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication('sk-or-test'));
        return $model;
    }

    private function createUserMessage(string $text): Message
    {
        return new Message(MessageRoleEnum::user(), [new MessagePart($text)]);
    }
}
