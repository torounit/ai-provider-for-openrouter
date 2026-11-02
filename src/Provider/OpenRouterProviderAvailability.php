<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Provider;

use Exception;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Contracts\CachesDataInterface;
use WordPress\AiClient\Common\Traits\WithDataCachingTrait;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;

/**
 * Class to check whether OpenRouter is configured with a valid API key.
 *
 * OpenRouter's models endpoint is publicly accessible, so listing models (as other providers do) would report any API
 * key as valid. Instead, this checks the API key against the authenticated key information endpoint.
 *
 * @since 0.1.0
 *
 * @link https://openrouter.ai/docs/api/api-reference/api-keys/get-current-key
 */
class OpenRouterProviderAvailability implements
    ProviderAvailabilityInterface,
    WithHttpTransporterInterface,
    WithRequestAuthenticationInterface,
    CachesDataInterface
{
    use WithHttpTransporterTrait;
    use WithRequestAuthenticationTrait;
    use WithDataCachingTrait;

    /**
     * Time in seconds to cache a successful API key check.
     *
     * The check runs whenever the AI Client resolves models, so it must not result in a request every time.
     *
     * @since 0.1.0
     *
     * @var int
     */
    private const VALID_CACHE_TTL = 3600;

    /**
     * Time in seconds to cache a rejected API key.
     *
     * @since 0.1.0
     *
     * @var int
     */
    private const INVALID_CACHE_TTL = 300;

    /**
     * Cached value for an API key that was accepted.
     *
     * A non-falsy value is used for both results, so that cache backends cannot confuse it with a cache miss.
     *
     * @since 0.1.0
     *
     * @var string
     */
    private const STATUS_VALID = 'valid';

    /**
     * Cached value for an API key that was rejected.
     *
     * @since 0.1.0
     *
     * @var string
     */
    private const STATUS_INVALID = 'invalid';

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function isConfigured(): bool
    {
        try {
            $requestAuthentication = $this->getRequestAuthentication();
        } catch (Exception $e) {
            // No credentials have been provided.
            return false;
        }

        $cacheKey = $this->getApiKeyCacheKey($requestAuthentication);
        if ($cacheKey !== null && $this->hasCache($cacheKey)) {
            return $this->getCache($cacheKey) === self::STATUS_VALID;
        }

        try {
            $request = new Request(HttpMethodEnum::GET(), OpenRouterProvider::url('key'));
            $request = $requestAuthentication->authenticateRequest($request);
            $response = $this->getHttpTransporter()->send($request);
        } catch (Exception $e) {
            // The API could not be reached, which says nothing about the API key, so this is not cached.
            return false;
        }

        if ($response->isSuccessful()) {
            $status = self::STATUS_VALID;
        } elseif (in_array($response->getStatusCode(), [401, 403], true)) {
            $status = self::STATUS_INVALID;
        } else {
            // Rate limits and server errors are transient, so the API key is checked again next time.
            return false;
        }

        if ($cacheKey !== null) {
            $this->setCache(
                $cacheKey,
                $status,
                $status === self::STATUS_VALID ? self::VALID_CACHE_TTL : self::INVALID_CACHE_TTL
            );
        }

        return $status === self::STATUS_VALID;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function getCachedKeys(): array
    {
        try {
            $cacheKey = $this->getApiKeyCacheKey($this->getRequestAuthentication());
        } catch (Exception $e) {
            return [];
        }
        return $cacheKey !== null ? [$cacheKey] : [];
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function getBaseCacheKey(): string
    {
        return 'ai_client_' . AiClient::VERSION . '_' . md5(static::class);
    }

    /**
     * Returns the cache key for the result of checking the given API key.
     *
     * The cache key is derived from a hash of the API key, so that changing the API key results in a new check, and
     * the API key itself is never stored in the cache.
     *
     * @since 0.1.0
     *
     * @param RequestAuthenticationInterface $requestAuthentication The request authentication.
     * @return string|null The cache key, or null if the request authentication is not API key based.
     */
    private function getApiKeyCacheKey(RequestAuthenticationInterface $requestAuthentication): ?string
    {
        if (!$requestAuthentication instanceof ApiKeyRequestAuthentication) {
            return null;
        }
        return 'key_' . hash('sha256', $requestAuthentication->getApiKey());
    }
}
