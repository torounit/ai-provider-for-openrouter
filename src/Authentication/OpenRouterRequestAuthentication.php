<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Authentication;

use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;

/**
 * Class for HTTP request authentication for OpenRouter.
 *
 * Extends the default API key authentication to include the app attribution headers
 * that OpenRouter uses to identify the calling application.
 *
 * @since 0.1.0
 *
 * @link https://openrouter.ai/docs/app-attribution
 */
class OpenRouterRequestAuthentication extends ApiKeyRequestAuthentication
{
    /**
     * The app URL sent as the `HTTP-Referer` attribution header.
     *
     * This intentionally identifies the plugin rather than the site, so that site URLs are not
     * exposed in OpenRouter's public app rankings.
     *
     * @since 0.1.0
     *
     * @var string
     */
    public const APP_URL = 'https://github.com/torounit/ai-provider-for-openrouter';

    /**
     * The app title sent as the `X-Title` attribution header.
     *
     * @since 0.1.0
     *
     * @var string
     */
    public const APP_TITLE = 'AI Provider for OpenRouter';

    /**
     * Wraps the given request authentication so that it includes the attribution headers.
     *
     * The AI Client registry and WordPress pass plain API key authentication instances, so this is used to
     * upgrade them right before they are applied to a request.
     *
     * @since 0.1.0
     *
     * @param RequestAuthenticationInterface $requestAuthentication The request authentication to wrap.
     * @return RequestAuthenticationInterface The wrapped request authentication, or the original one if it is not
     *                                        API key based.
     */
    public static function wrap(RequestAuthenticationInterface $requestAuthentication): RequestAuthenticationInterface
    {
        if (
            $requestAuthentication instanceof self ||
            !$requestAuthentication instanceof ApiKeyRequestAuthentication
        ) {
            return $requestAuthentication;
        }
        return new self($requestAuthentication->getApiKey());
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function authenticateRequest(Request $request): Request
    {
        $request = parent::authenticateRequest($request);
        $request = $request->withHeader('HTTP-Referer', self::APP_URL);
        $request = $request->withHeader('X-Title', self::APP_TITLE);

        return $request;
    }
}
