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
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Tools\DTO\WebSearch;

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

    public function testGenerateTextResultParsesReasoningAsSignedThought(): void
    {
        $details = [
            ['type' => 'reasoning.text', 'text' => '91 = 7 × 13.', 'format' => 'unknown', 'index' => 0],
        ];
        $model = $this->createModel(
            new MockHttpTransporter([$this->createChatResponse([
                'role' => 'assistant',
                'content' => 'No',
                'reasoning' => '91 = 7 × 13.',
                'reasoning_details' => $details,
            ])]),
            'vendor/model'
        );

        $result = $model->generateTextResult([$this->createUserMessage('Is 91 prime?')]);
        $parts = $result->getCandidates()[0]->getMessage()->getParts();

        $this->assertCount(2, $parts);
        $this->assertTrue($parts[0]->getChannel()->isThought());
        $this->assertSame('91 = 7 × 13.', $parts[0]->getText());
        $this->assertSame($details, json_decode((string) $parts[0]->getThoughtSignature(), true));
        $this->assertSame('No', $parts[1]->getText());
        $this->assertSame('No', $result->toText());
    }

    public function testGenerateTextResultParsesReasoningWithoutDetails(): void
    {
        $model = $this->createModel(
            new MockHttpTransporter([$this->createChatResponse([
                'role' => 'assistant',
                'content' => 'No',
                'reasoning' => 'Thinking.',
            ])]),
            'vendor/model'
        );

        $parts = $model->generateTextResult([$this->createUserMessage('Is 91 prime?')])
            ->getCandidates()[0]->getMessage()->getParts();

        $this->assertTrue($parts[0]->getChannel()->isThought());
        $this->assertSame('Thinking.', $parts[0]->getText());
        $this->assertNull($parts[0]->getThoughtSignature());
    }

    public function testGenerateTextResultKeepsEncryptedReasoningWithoutText(): void
    {
        $details = [
            ['type' => 'reasoning.encrypted', 'data' => 'abc', 'id' => 'rs_1', 'format' => 'openai', 'index' => 0],
        ];
        $model = $this->createModel(
            new MockHttpTransporter([$this->createChatResponse([
                'role' => 'assistant',
                'content' => 'No',
                'reasoning' => null,
                'reasoning_details' => $details,
            ])]),
            'vendor/model'
        );

        $parts = $model->generateTextResult([$this->createUserMessage('Is 91 prime?')])
            ->getCandidates()[0]->getMessage()->getParts();

        $this->assertTrue($parts[0]->getChannel()->isThought());
        $this->assertSame($details, json_decode((string) $parts[0]->getThoughtSignature(), true));
    }

    public function testGenerateTextResultDoesNotDuplicateReasoningContent(): void
    {
        $details = [['type' => 'reasoning.text', 'text' => 'Thinking.', 'format' => 'unknown', 'index' => 0]];
        $model = $this->createModel(
            new MockHttpTransporter([$this->createChatResponse([
                'role' => 'assistant',
                'content' => 'No',
                'reasoning_content' => 'Thinking.',
                'reasoning' => 'Thinking.',
                'reasoning_details' => $details,
            ])]),
            'vendor/model'
        );

        $parts = $model->generateTextResult([$this->createUserMessage('Is 91 prime?')])
            ->getCandidates()[0]->getMessage()->getParts();

        $this->assertCount(2, $parts);
        $this->assertTrue($parts[0]->getChannel()->isThought());
        $this->assertSame('Thinking.', $parts[0]->getText());
        $this->assertSame($details, json_decode((string) $parts[0]->getThoughtSignature(), true));
    }

    public function testGenerateTextResultUsesReasoningDetailsTextWithoutReasoning(): void
    {
        $model = $this->createModel(
            new MockHttpTransporter([$this->createChatResponse([
                'role' => 'assistant',
                'content' => 'No',
                'reasoning' => null,
                'reasoning_details' => [
                    ['type' => 'reasoning.summary', 'summary' => 'Checked divisors.', 'index' => 0],
                    ['type' => 'reasoning.text', 'text' => '7 × 13 = 91.', 'index' => 1],
                    ['type' => 'reasoning.encrypted', 'data' => 'abc', 'index' => 2],
                ],
            ])]),
            'vendor/model'
        );

        $parts = $model->generateTextResult([$this->createUserMessage('Is 91 prime?')])
            ->getCandidates()[0]->getMessage()->getParts();

        $this->assertSame("Checked divisors.\n7 × 13 = 91.", $parts[0]->getText());
    }

    public function testPrepareGenerateTextParamsSendsReasoningDetailsBack(): void
    {
        $details = [['type' => 'reasoning.text', 'text' => 'Thinking.', 'format' => 'unknown', 'index' => 0]];
        $config = new ModelConfig();
        $config->setSystemInstruction('Be terse.');

        $params = $this->prepareParamsForMessages($config, [
            $this->createUserMessage('Is 91 prime?'),
            new Message(MessageRoleEnum::model(), [
                new MessagePart('Thinking.', MessagePartChannelEnum::thought(), (string) json_encode($details)),
                new MessagePart('No'),
            ]),
            $this->createUserMessage('Why?'),
        ]);

        // The system instruction comes first.
        $this->assertSame('system', $params['messages'][0]['role']);
        $this->assertArrayNotHasKey('reasoning_details', $params['messages'][1]);
        $this->assertSame('assistant', $params['messages'][2]['role']);
        $this->assertSame($details, $params['messages'][2]['reasoning_details']);
        $this->assertSame([['type' => 'text', 'text' => 'No']], $params['messages'][2]['content']);
        $this->assertArrayNotHasKey('reasoning_details', $params['messages'][3]);
    }

    public function testPrepareGenerateTextParamsSendsReasoningDetailsOnlyForModelMessages(): void
    {
        $details = [['type' => 'reasoning.text', 'text' => 'Thinking.', 'index' => 0]];

        $params = $this->prepareParamsForMessages(new ModelConfig(), [
            new Message(MessageRoleEnum::user(), [
                new MessagePart('Thinking.', MessagePartChannelEnum::thought(), (string) json_encode($details)),
                new MessagePart('Hi'),
            ]),
        ]);

        $this->assertArrayNotHasKey('reasoning_details', $params['messages'][0]);
    }

    public function testPrepareGenerateTextParamsSkipsEntriesThatAreNotReasoningDetails(): void
    {
        $details = [['type' => 'reasoning.text', 'text' => 'Thinking.', 'index' => 0]];
        $signature = (string) json_encode(array_merge($details, [1, ['type' => 'other'], ['text' => 'x']]));

        $params = $this->prepareParamsForMessages(new ModelConfig(), [
            new Message(MessageRoleEnum::model(), [
                new MessagePart('Thinking.', MessagePartChannelEnum::thought(), $signature),
                new MessagePart('No'),
            ]),
        ]);

        $this->assertSame($details, $params['messages'][0]['reasoning_details']);
    }

    /**
     * @dataProvider dataUnusableThoughtSignatures
     */
    public function testPrepareGenerateTextParamsIgnoresUnusableThoughtSignatures(?string $signature): void
    {
        $params = $this->prepareParamsForMessages(new ModelConfig(), [
            new Message(MessageRoleEnum::model(), [
                new MessagePart('Thinking.', MessagePartChannelEnum::thought(), $signature),
                new MessagePart('No'),
            ]),
        ]);

        $this->assertArrayNotHasKey('reasoning_details', $params['messages'][0]);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public function dataUnusableThoughtSignatures(): array
    {
        return [
            'none' => [null],
            'invalid json' => ['{not json'],
            'not a list' => ['{"id":"rs_1"}'],
            'not reasoning details' => ['[1,2]'],
        ];
    }

    public function testPrepareGenerateTextParamsEnablesWebSearchPlugin(): void
    {
        $config = new ModelConfig();
        $config->setWebSearch(new WebSearch());

        $params = $this->prepareParams($config);

        $this->assertSame([['id' => 'web']], $params['plugins']);
    }

    /**
     * @dataProvider dataWebSearchDomains
     *
     * @param list<string> $allowedDomains
     * @param list<string> $disallowedDomains
     * @param array<string, mixed> $expectedPlugin
     */
    public function testPrepareGenerateTextParamsPassesWebSearchDomains(
        array $allowedDomains,
        array $disallowedDomains,
        array $expectedPlugin
    ): void {
        $config = new ModelConfig();
        $config->setWebSearch(new WebSearch($allowedDomains, $disallowedDomains));

        $params = $this->prepareParams($config);

        $this->assertSame([$expectedPlugin], $params['plugins']);
    }

    /**
     * @return array<string, array{0: list<string>, 1: list<string>, 2: array<string, mixed>}>
     */
    public function dataWebSearchDomains(): array
    {
        return [
            'allowed' => [
                ['wordpress.org', '*.wordpress.com'],
                [],
                ['id' => 'web', 'include_domains' => ['wordpress.org', '*.wordpress.com']],
            ],
            'disallowed' => [
                [],
                ['example.com'],
                ['id' => 'web', 'exclude_domains' => ['example.com']],
            ],
            // Most search engines do not accept both, and the allowed domains already restrict the results.
            'both' => [
                ['wordpress.org'],
                ['example.com'],
                ['id' => 'web', 'include_domains' => ['wordpress.org']],
            ],
        ];
    }

    public function testPrepareGenerateTextParamsAddsWebSearchToCustomPlugins(): void
    {
        $fileParser = ['id' => 'file-parser', 'pdf' => ['engine' => 'pdf-text']];
        $config = new ModelConfig();
        $config->setWebSearch(new WebSearch());
        $config->setCustomOptions(['plugins' => [$fileParser]]);

        $params = $this->prepareParams($config);

        $this->assertSame([$fileParser, ['id' => 'web']], $params['plugins']);
    }

    public function testPrepareGenerateTextParamsRejectsInvalidCustomPlugins(): void
    {
        $config = new ModelConfig();
        $config->setWebSearch(new WebSearch());
        $config->setCustomOptions(['plugins' => 'web']);

        $this->expectException(InvalidArgumentException::class);

        $this->prepareParams($config);
    }

    public function testGenerateTextResultSendsOpenRouterModelId(): void
    {
        $transporter = new MockHttpTransporter([$this->createChatResponse(['role' => 'assistant', 'content' => 'Hi'])]);
        $model = new OpenRouterTextGenerationModel(
            new ModelMetadata('claude-haiku-5.5', 'Claude Haiku 5.5', [CapabilityEnum::textGeneration()], []),
            OpenRouterProvider::metadata(),
            'anthropic/claude-haiku-5.5'
        );
        $model->setHttpTransporter($transporter);
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication('sk-or-test'));

        $model->generateTextResult([$this->createUserMessage('Hello')]);

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $data = $request->getData();
        $this->assertNotNull($data);
        $this->assertSame('anthropic/claude-haiku-5.5', $data['model']);
    }

    /**
     * @dataProvider dataChargedRequestsInFreeModelsOnlyMode
     *
     * @param array<string, mixed> $customOptions
     */
    public function testRejectsChargedRequestsInFreeModelsOnlyMode(
        string $modelId,
        bool $webSearch,
        array $customOptions
    ): void {
        putenv('OPENROUTER_FREE_MODELS_ONLY=true');
        try {
            $config = new ModelConfig();
            if ($webSearch) {
                $config->setWebSearch(new WebSearch());
            }
            $config->setCustomOptions($customOptions);
            $model = new OpenRouterTextGenerationModel(
                new ModelMetadata($modelId, $modelId, [CapabilityEnum::textGeneration()], []),
                OpenRouterProvider::metadata()
            );
            $model->setHttpTransporter(new MockHttpTransporter());
            $model->setRequestAuthentication(new ApiKeyRequestAuthentication('sk-or-test'));
            $model->setConfig($config);

            $this->expectException(InvalidArgumentException::class);

            $model->generateTextResult([$this->createUserMessage('Hello')]);
        } finally {
            putenv('OPENROUTER_FREE_MODELS_ONLY');
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: array<string, mixed>}>
     */
    public function dataChargedRequestsInFreeModelsOnlyMode(): array
    {
        return [
            'paid model' => ['anthropic/claude-haiku-5.5', false, []],
            'web search' => ['openrouter/free', true, []],
            'plugins' => ['openrouter/free', false, ['plugins' => [['id' => 'web']]]],
            'paid fallback models' => ['openrouter/free', false, ['models' => ['anthropic/claude-haiku-5.5']]],
        ];
    }

    public function testAllowsFreeRequestsInFreeModelsOnlyMode(): void
    {
        putenv('OPENROUTER_FREE_MODELS_ONLY=true');
        try {
            $transporter = new MockHttpTransporter([
                $this->createChatResponse(['role' => 'assistant', 'content' => 'Hi']),
            ]);
            $config = new ModelConfig();
            $config->setCustomOptions(['models' => ['nvidia/nemotron-3.5-super-vl:free']]);
            $model = new OpenRouterTextGenerationModel(
                new ModelMetadata('nemotron-3.5-super-vl:free', 'Nemotron', [CapabilityEnum::textGeneration()], []),
                OpenRouterProvider::metadata(),
                'nvidia/nemotron-3.5-super-vl:free'
            );
            $model->setHttpTransporter($transporter);
            $model->setRequestAuthentication(new ApiKeyRequestAuthentication('sk-or-test'));
            $model->setConfig($config);

            $this->assertSame('Hi', $model->generateTextResult([$this->createUserMessage('Hello')])->toText());
        } finally {
            putenv('OPENROUTER_FREE_MODELS_ONLY');
        }
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
        $message = $parts !== null
            ? new Message(MessageRoleEnum::user(), $parts)
            : $this->createUserMessage('Hi');

        return $this->prepareParamsForMessages($config, [$message]);
    }

    /**
     * @param list<Message> $messages
     * @return array<string, mixed>
     */
    private function prepareParamsForMessages(ModelConfig $config, array $messages): array
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

        return $model->exposePrepareGenerateTextParams($messages);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function createChatResponse(array $message): Response
    {
        return new Response(200, [], (string) json_encode([
            'id' => 'gen-1',
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => $message]],
        ]));
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
