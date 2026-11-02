<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Tests\unit\Authentication;

use PHPUnit\Framework\TestCase;
use ToroUnit\OpenRouterAiProvider\Authentication\OpenRouterRequestAuthentication;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

/**
 * @covers \ToroUnit\OpenRouterAiProvider\Authentication\OpenRouterRequestAuthentication
 */
class OpenRouterRequestAuthenticationTest extends TestCase
{
    public function testAuthenticateRequestAddsApiKeyAndAttributionHeaders(): void
    {
        $authentication = new OpenRouterRequestAuthentication('sk-or-test');

        $request = $authentication->authenticateRequest(
            new Request(HttpMethodEnum::GET(), 'https://openrouter.ai/api/v1/key')
        );

        $this->assertSame('Bearer sk-or-test', $request->getHeaderAsString('Authorization'));
        $this->assertSame(
            'https://github.com/torounit/ai-provider-for-openrouter',
            $request->getHeaderAsString('HTTP-Referer')
        );
        $this->assertSame('AI Provider for OpenRouter', $request->getHeaderAsString('X-Title'));
    }

    public function testWrapUpgradesApiKeyAuthentication(): void
    {
        $wrapped = OpenRouterRequestAuthentication::wrap(new ApiKeyRequestAuthentication('sk-or-test'));

        $this->assertInstanceOf(OpenRouterRequestAuthentication::class, $wrapped);
        $this->assertSame('sk-or-test', $wrapped->getApiKey());
    }

    public function testWrapKeepsAlreadyWrappedAuthentication(): void
    {
        $authentication = new OpenRouterRequestAuthentication('sk-or-test');

        $this->assertSame($authentication, OpenRouterRequestAuthentication::wrap($authentication));
    }

    public function testWrapKeepsOtherAuthentication(): void
    {
        $authentication = $this->createMock(RequestAuthenticationInterface::class);

        $this->assertSame($authentication, OpenRouterRequestAuthentication::wrap($authentication));
    }
}
