<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Tests\Mocks;

use RuntimeException;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

/**
 * HTTP transporter that returns queued responses and records the sent requests.
 */
class MockHttpTransporter implements HttpTransporterInterface
{
    /**
     * @var list<Response>
     */
    private array $responses;

    /**
     * @var list<Request>
     */
    private array $requests = [];

    /**
     * @param list<Response> $responses The responses to return, in order.
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function send(Request $request, ?RequestOptions $options = null): Response
    {
        $this->requests[] = $request;

        $response = array_shift($this->responses);
        if ($response === null) {
            throw new RuntimeException('No response queued for ' . $request->getUri());
        }
        return $response;
    }

    public function queueResponse(Response $response): void
    {
        $this->responses[] = $response;
    }

    /**
     * @return list<Request>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    public function getLastRequest(): ?Request
    {
        return $this->requests ? $this->requests[count($this->requests) - 1] : null;
    }
}
