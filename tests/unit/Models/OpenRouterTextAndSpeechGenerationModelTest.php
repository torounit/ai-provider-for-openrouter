<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Tests\unit\Models;

use PHPUnit\Framework\TestCase;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterTextAndSpeechGenerationModel;
use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use ToroUnit\OpenRouterAiProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * @covers \ToroUnit\OpenRouterAiProvider\Models\OpenRouterTextAndSpeechGenerationModel
 */
class OpenRouterTextAndSpeechGenerationModelTest extends TestCase
{
    public function testGenerateTextResultDoesNotStream(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(200, [], (string) json_encode([
                'choices' => [
                    ['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Hi']],
                ],
            ])),
        ]);
        $model = $this->createModel($transporter);

        $this->assertSame('Hi', $model->generateTextResult([$this->createUserMessage('Hello')])->toText());

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $data = $request->getData();
        $this->assertNotNull($data);
        $this->assertArrayNotHasKey('stream', $data);
    }

    public function testConvertTextToSpeechResultUsesSharedConfig(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(
                200,
                [],
                'data: ' . json_encode(['choices' => [['delta' => ['audio' => ['data' => base64_encode('ab')]]]]])
                . "\n\ndata: [DONE]\n"
            ),
        ]);
        $config = new ModelConfig();
        $config->setOutputSpeechVoice('echo');
        $model = $this->createModel($transporter);
        $model->setConfig($config);

        $result = $model->convertTextToSpeechResult([$this->createUserMessage('Hello')]);

        $this->assertSame('audio/wav', $result->toFile()->getMimeType());
        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $data = $request->getData();
        $this->assertNotNull($data);
        $this->assertSame('echo', $data['audio']['voice']);
    }

    public function testSendsOpenRouterModelIdForTextAndSpeech(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(200, [], (string) json_encode([
                'choices' => [
                    ['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Hi']],
                ],
            ])),
            new Response(
                200,
                [],
                'data: ' . json_encode(['choices' => [['delta' => ['audio' => ['data' => base64_encode('ab')]]]]])
                . "\n\ndata: [DONE]\n"
            ),
        ]);
        $model = new OpenRouterTextAndSpeechGenerationModel(
            new ModelMetadata(
                'gpt-audio-mini',
                'GPT Audio Mini',
                [CapabilityEnum::textGeneration(), CapabilityEnum::textToSpeechConversion()],
                []
            ),
            OpenRouterProvider::metadata(),
            'openai/gpt-audio-mini'
        );
        $model->setHttpTransporter($transporter);
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication('sk-or-test'));

        $model->generateTextResult([$this->createUserMessage('Hello')]);
        $model->convertTextToSpeechResult([$this->createUserMessage('Hello')]);

        foreach ($transporter->getRequests() as $request) {
            $data = $request->getData();
            $this->assertNotNull($data);
            $this->assertSame('openai/gpt-audio-mini', $data['model']);
        }
    }

    private function createModel(MockHttpTransporter $transporter): OpenRouterTextAndSpeechGenerationModel
    {
        $model = new OpenRouterTextAndSpeechGenerationModel(
            new ModelMetadata(
                'openai/gpt-audio-mini',
                'GPT Audio Mini',
                [CapabilityEnum::textGeneration(), CapabilityEnum::textToSpeechConversion()],
                []
            ),
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
