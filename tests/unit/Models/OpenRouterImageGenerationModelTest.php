<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Tests\unit\Models;

use PHPUnit\Framework\TestCase;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterImageGenerationModel;
use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use ToroUnit\OpenRouterAiProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * @covers \ToroUnit\OpenRouterAiProvider\Models\OpenRouterImageGenerationModel
 */
class OpenRouterImageGenerationModelTest extends TestCase
{
    public function testGenerateImageResultSendsImagesRequest(): void
    {
        $transporter = new MockHttpTransporter([$this->createImageResponse()]);
        $model = $this->createModel($transporter);

        $result = $model->generateImageResult([$this->createUserMessage('A cat')]);

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('https://openrouter.ai/api/v1/images', $request->getUri());
        $this->assertSame('Bearer sk-or-test', $request->getHeaderAsString('Authorization'));
        // OpenRouter always returns base64 data and takes an aspect ratio instead of a size.
        $this->assertSame(['model' => 'google/gemini-3.1-flash-lite-image', 'prompt' => 'A cat'], $request->getData());

        $this->assertCount(1, $result->getCandidates());
    }

    public function testGenerateImageResultParsesImageWithMediaTypeAndUsage(): void
    {
        $model = $this->createModel(new MockHttpTransporter([$this->createImageResponse('image/jpeg')]));

        $result = $model->generateImageResult([$this->createUserMessage('A cat')]);

        $file = $result->toFile();
        $this->assertTrue($file->isInline());
        $this->assertSame('image/jpeg', $file->getMimeType());
        $this->assertSame(base64_encode('fake-image'), $file->getBase64Data());

        $tokenUsage = $result->getTokenUsage();
        $this->assertSame(10, $tokenUsage->getPromptTokens());
        $this->assertSame(1290, $tokenUsage->getCompletionTokens());
        $this->assertSame(1300, $tokenUsage->getTotalTokens());
    }

    public function testGenerateImageResultFallsBackToRequestedMimeType(): void
    {
        $config = new ModelConfig();
        $config->setOutputMimeType('image/webp');
        $model = $this->createModel(new MockHttpTransporter([$this->createImageResponse(null)]));
        $model->setConfig($config);

        $result = $model->generateImageResult([$this->createUserMessage('A cat')]);

        $this->assertSame('image/webp', $result->toFile()->getMimeType());
    }

    public function testGenerateImageResultSendsOptions(): void
    {
        $transporter = new MockHttpTransporter([$this->createImageResponse()]);
        $config = new ModelConfig();
        $config->setCandidateCount(2);
        $config->setOutputMimeType('image/webp');
        $config->setOutputMediaAspectRatio('16:9');
        $model = $this->createModel($transporter);
        $model->setConfig($config);

        $model->generateImageResult([$this->createUserMessage('A cat')]);

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame(
            [
                'model' => 'google/gemini-3.1-flash-lite-image',
                'prompt' => 'A cat',
                'n' => 2,
                'output_format' => 'webp',
                'aspect_ratio' => '16:9',
            ],
            $request->getData()
        );
    }

    /**
     * @dataProvider dataOrientations
     */
    public function testGenerateImageResultMapsOrientationToAspectRatio(
        MediaOrientationEnum $orientation,
        string $expectedAspectRatio
    ): void {
        $transporter = new MockHttpTransporter([$this->createImageResponse()]);
        $config = new ModelConfig();
        $config->setOutputMediaOrientation($orientation);
        $model = $this->createModel($transporter);
        $model->setConfig($config);

        $model->generateImageResult([$this->createUserMessage('A cat')]);

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $data = $request->getData();
        $this->assertNotNull($data);
        $this->assertSame($expectedAspectRatio, $data['aspect_ratio']);
    }

    /**
     * @return array<string, array{0: MediaOrientationEnum, 1: string}>
     */
    public function dataOrientations(): array
    {
        return [
            'square' => [MediaOrientationEnum::square(), '1:1'],
            'landscape' => [MediaOrientationEnum::landscape(), '3:2'],
            'portrait' => [MediaOrientationEnum::portrait(), '2:3'],
        ];
    }

    private function createImageResponse(?string $mediaType = 'image/png'): Response
    {
        $image = ['b64_json' => base64_encode('fake-image')];
        if ($mediaType !== null) {
            $image['media_type'] = $mediaType;
        }
        return new Response(200, [], (string) json_encode([
            'created' => 1793626154,
            'data' => [$image],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 1290, 'total_tokens' => 1300, 'cost' => 0.04],
        ]));
    }

    private function createModel(MockHttpTransporter $transporter): OpenRouterImageGenerationModel
    {
        $model = new OpenRouterImageGenerationModel(
            new ModelMetadata(
                'google/gemini-3.1-flash-lite-image',
                'Gemini Flash Lite Image',
                [CapabilityEnum::imageGeneration()],
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
