<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Tests\unit\Models;

use PHPUnit\Framework\TestCase;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterTextAndImageGenerationModel;
use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use ToroUnit\OpenRouterAiProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * @covers \ToroUnit\OpenRouterAiProvider\Models\OpenRouterTextAndImageGenerationModel
 */
class OpenRouterTextAndImageGenerationModelTest extends TestCase
{
    public function testGenerateTextResultUsesChatCompletions(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(200, [], (string) json_encode([
                'id' => 'gen-1',
                'choices' => [
                    ['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Hi']],
                ],
            ])),
        ]);
        $model = $this->createModel($transporter);

        $this->assertSame('Hi', $model->generateTextResult([$this->createUserMessage('Hello')])->toText());

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $request->getUri());
    }

    public function testGenerateImageResultUsesImagesEndpointWithSharedConfig(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(200, [], (string) json_encode([
                'data' => [['b64_json' => base64_encode('fake-image'), 'media_type' => 'image/png']],
            ])),
        ]);
        $config = new ModelConfig();
        $config->setOutputMediaAspectRatio('1:1');
        $model = $this->createModel($transporter);
        $model->setConfig($config);

        $result = $model->generateImageResult([$this->createUserMessage('A cat')]);

        $this->assertSame('image/png', $result->toFile()->getMimeType());
        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('https://openrouter.ai/api/v1/images', $request->getUri());
        $data = $request->getData();
        $this->assertNotNull($data);
        $this->assertSame('1:1', $data['aspect_ratio']);
    }

    public function testSettersApplyToBothModels(): void
    {
        $model = $this->createModel(new MockHttpTransporter());
        $config = new ModelConfig();
        $requestOptions = new RequestOptions();
        $model->setConfig($config);
        $model->setRequestOptions($requestOptions);

        $this->assertSame($config, $model->getConfig());
        $this->assertSame($requestOptions, $model->getRequestOptions());
        $this->assertSame('google/gemini-3.1-flash-lite-image', $model->metadata()->getId());
        $this->assertSame('openrouter', $model->providerMetadata()->getId());
    }

    private function createModel(MockHttpTransporter $transporter): OpenRouterTextAndImageGenerationModel
    {
        $model = new OpenRouterTextAndImageGenerationModel(
            new ModelMetadata(
                'google/gemini-3.1-flash-lite-image',
                'Gemini Flash Lite Image',
                [CapabilityEnum::textGeneration(), CapabilityEnum::imageGeneration()],
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
