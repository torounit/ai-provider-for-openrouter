<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Tests\unit\Models;

use PHPUnit\Framework\TestCase;
use ToroUnit\OpenRouterAiProvider\Models\OpenRouterTextToSpeechConversionModel;
use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use ToroUnit\OpenRouterAiProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * @covers \ToroUnit\OpenRouterAiProvider\Models\OpenRouterTextToSpeechConversionModel
 */
class OpenRouterTextToSpeechConversionModelTest extends TestCase
{
    public function testConvertTextToSpeechResultSendsStreamingAudioRequest(): void
    {
        $transporter = new MockHttpTransporter([$this->createStreamResponse(['AAAA'])]);
        $model = $this->createModel($transporter);

        $model->convertTextToSpeechResult([$this->createUserMessage('Hello WordPress.')]);

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $request->getUri());
        $this->assertSame('Bearer sk-or-test', $request->getHeaderAsString('Authorization'));

        $data = $request->getData();
        $this->assertNotNull($data);
        $this->assertSame('openai/gpt-audio-mini', $data['model']);
        $this->assertSame(['text', 'audio'], $data['modalities']);
        // Audio output requires streaming, which only supports raw 16-bit PCM.
        $this->assertSame(['voice' => 'alloy', 'format' => 'pcm16'], $data['audio']);
        $this->assertTrue($data['stream']);
        $this->assertSame('system', $data['messages'][0]['role']);
        // The text is quoted, so that the model reads it instead of responding to it.
        $this->assertSame(
            [
                'role' => 'user',
                'content' => "Read the following text aloud verbatim, and say nothing else:\n"
                    . "\"\"\"\nHello WordPress.\n\"\"\"",
            ],
            $data['messages'][1]
        );
    }

    public function testConvertTextToSpeechResultUsesConfiguredVoiceAndCustomOptions(): void
    {
        $transporter = new MockHttpTransporter([$this->createStreamResponse(['AAAA'])]);
        $config = new ModelConfig();
        $config->setOutputSpeechVoice('nova');
        $config->setCustomOptions(['temperature' => 0.6]);
        $model = $this->createModel($transporter);
        $model->setConfig($config);

        $model->convertTextToSpeechResult([$this->createUserMessage('Hello')]);

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $data = $request->getData();
        $this->assertNotNull($data);
        $this->assertSame('nova', $data['audio']['voice']);
        $this->assertSame(0.6, $data['temperature']);
    }

    public function testConvertTextToSpeechResultReturnsWavFile(): void
    {
        $pcm = pack('v*', 1, 2, 3, 4, 5, 6);
        $model = $this->createModel(new MockHttpTransporter([
            $this->createStreamResponse(
                [base64_encode(substr($pcm, 0, 6)), base64_encode(substr($pcm, 6))],
                ['Hello ', 'WordPress.']
            ),
        ]));

        $result = $model->convertTextToSpeechResult([$this->createUserMessage('Hello WordPress.')]);

        $this->assertSame('gen-audio-1', $result->getId());
        $file = $result->toFile();
        $this->assertSame('audio/wav', $file->getMimeType());

        $wav = (string) base64_decode((string) $file->getBase64Data());
        $this->assertSame('RIFF', substr($wav, 0, 4));
        $this->assertSame('WAVE', substr($wav, 8, 4));
        $header = unpack('vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', substr($wav, 20, 16));
        $this->assertSame(
            [
                'format' => 1,
                'channels' => 1,
                'sampleRate' => 24000,
                'byteRate' => 48000,
                'blockAlign' => 2,
                'bitsPerSample' => 16,
            ],
            $header
        );
        $this->assertSame('data', substr($wav, 36, 4));
        $this->assertSame($pcm, substr($wav, 44));

        $this->assertSame('Hello WordPress.', $result->getAdditionalData()['transcript']);
        $this->assertSame(30, $result->getTokenUsage()->getTotalTokens());
    }

    public function testConvertTextToSpeechResultRejectsUnsupportedMimeType(): void
    {
        $config = new ModelConfig();
        $config->setOutputMimeType('audio/mpeg');
        $model = $this->createModel(new MockHttpTransporter());
        $model->setConfig($config);

        $this->expectException(InvalidArgumentException::class);

        $model->convertTextToSpeechResult([$this->createUserMessage('Hello')]);
    }

    public function testConvertTextToSpeechResultRejectsPromptWithoutText(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createModel(new MockHttpTransporter())->convertTextToSpeechResult([]);
    }

    public function testConvertTextToSpeechResultRejectsConflictingCustomOptions(): void
    {
        $config = new ModelConfig();
        $config->setCustomOptions(['stream' => false]);
        $model = $this->createModel(new MockHttpTransporter());
        $model->setConfig($config);

        $this->expectException(InvalidArgumentException::class);

        $model->convertTextToSpeechResult([$this->createUserMessage('Hello')]);
    }

    public function testConvertTextToSpeechResultThrowsWithoutAudio(): void
    {
        $model = $this->createModel(new MockHttpTransporter([$this->createStreamResponse([])]));

        $this->expectException(ResponseException::class);

        $model->convertTextToSpeechResult([$this->createUserMessage('Hello')]);
    }

    /**
     * @param list<string> $audioChunks Base64 encoded audio chunks.
     * @param list<string> $transcriptChunks Transcript chunks.
     */
    private function createStreamResponse(array $audioChunks, array $transcriptChunks = []): Response
    {
        $lines = [': OPENROUTER PROCESSING', ''];
        foreach ($audioChunks as $index => $chunk) {
            $audio = ['data' => $chunk];
            if (isset($transcriptChunks[$index])) {
                $audio['transcript'] = $transcriptChunks[$index];
            }
            $lines[] = 'data: ' . json_encode([
                'id' => 'gen-audio-1',
                'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'audio' => $audio]]],
            ]);
            $lines[] = '';
        }
        $lines[] = 'data: ' . json_encode([
            'id' => 'gen-audio-1',
            'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30],
        ]);
        $lines[] = '';
        $lines[] = 'data: [DONE]';

        return new Response(200, ['Content-Type' => 'text/event-stream'], implode("\n", $lines) . "\n");
    }

    private function createModel(MockHttpTransporter $transporter): OpenRouterTextToSpeechConversionModel
    {
        $model = new OpenRouterTextToSpeechConversionModel(
            new ModelMetadata(
                'openai/gpt-audio-mini',
                'GPT Audio Mini',
                [CapabilityEnum::textToSpeechConversion()],
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
