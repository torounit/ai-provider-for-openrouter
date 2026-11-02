<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Tests\unit\Provider;

use PHPUnit\Framework\TestCase;
use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProviderAvailability;
use ToroUnit\OpenRouterAiProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;

/**
 * @covers \ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProviderAvailability
 */
class OpenRouterProviderAvailabilityTest extends TestCase
{
    public function testIsNotConfiguredWithoutAuthentication(): void
    {
        $transporter = new MockHttpTransporter();
        $availability = new OpenRouterProviderAvailability();
        $availability->setHttpTransporter($transporter);

        $this->assertFalse($availability->isConfigured());
        $this->assertSame([], $transporter->getRequests());
    }

    public function testIsConfiguredWhenKeyEndpointSucceeds(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(200, [], '{"data":{"label":"sk-or-v1-abc"}}'),
        ]);
        $availability = $this->createAvailability($transporter, 'sk-or-valid');

        $this->assertTrue($availability->isConfigured());

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('https://openrouter.ai/api/v1/key', $request->getUri());
        $this->assertSame('Bearer sk-or-valid', $request->getHeaderAsString('Authorization'));
    }

    public function testIsNotConfiguredWhenKeyEndpointRejectsKey(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(401, [], '{"error":{"message":"No auth credentials found","code":401}}'),
        ]);
        $availability = $this->createAvailability($transporter, 'sk-or-invalid');

        $this->assertFalse($availability->isConfigured());
    }

    public function testIsNotConfiguredWhenRequestFails(): void
    {
        // No queued response makes the transporter throw.
        $availability = $this->createAvailability(new MockHttpTransporter(), 'sk-or-unreachable');

        $this->assertFalse($availability->isConfigured());
    }

    public function testRejectedKeyIsCached(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(401, [], '{"error":{}}'),
        ]);
        $availability = $this->createAvailability($transporter, 'sk-or-cached-rejected');

        $this->assertFalse($availability->isConfigured());
        $this->assertFalse($availability->isConfigured());
        $this->assertCount(1, $transporter->getRequests());
    }

    /**
     * @dataProvider dataTransientFailures
     */
    public function testTransientFailureIsNotCached(?Response $failure): void
    {
        $responses = $failure ? [$failure] : [];
        $transporter = new MockHttpTransporter($responses);
        $availability = $this->createAvailability($transporter, 'sk-or-transient-' . uniqid());

        $this->assertFalse($availability->isConfigured());

        // Once the API recovers, the key must be checked again instead of being reported as invalid.
        $transporter->queueResponse(new Response(200, [], '{"data":{}}'));
        $this->assertTrue($availability->isConfigured());
    }

    /**
     * @return array<string, array{0: Response|null}>
     */
    public function dataTransientFailures(): array
    {
        return [
            'rate limited' => [new Response(429, [], '{"error":{}}')],
            'server error' => [new Response(502, [], '{"error":{}}')],
            'request exception' => [null],
        ];
    }

    public function testResultIsCachedPerApiKey(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(200, [], '{"data":{}}'),
            new Response(401, [], '{"error":{}}'),
        ]);
        $availability = $this->createAvailability($transporter, 'sk-or-cached-valid');

        $this->assertTrue($availability->isConfigured());
        $this->assertTrue($availability->isConfigured());
        $this->assertCount(1, $transporter->getRequests());

        // A different API key must be checked again.
        $availability->setRequestAuthentication(new ApiKeyRequestAuthentication('sk-or-cached-invalid'));
        $this->assertFalse($availability->isConfigured());
        $this->assertCount(2, $transporter->getRequests());
    }

    public function testInvalidateCachesForcesNewCheck(): void
    {
        $transporter = new MockHttpTransporter([
            new Response(200, [], '{"data":{}}'),
            new Response(401, [], '{"error":{}}'),
        ]);
        $availability = $this->createAvailability($transporter, 'sk-or-revoked');

        $this->assertTrue($availability->isConfigured());

        $availability->invalidateCaches();

        $this->assertFalse($availability->isConfigured());
        $this->assertCount(2, $transporter->getRequests());
    }

    private function createAvailability(
        MockHttpTransporter $transporter,
        string $apiKey
    ): OpenRouterProviderAvailability {
        $availability = new OpenRouterProviderAvailability();
        $availability->setHttpTransporter($transporter);
        $availability->setRequestAuthentication(new ApiKeyRequestAuthentication($apiKey));
        return $availability;
    }
}
