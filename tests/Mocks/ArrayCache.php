<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Tests\Mocks;

use Psr\SimpleCache\CacheInterface;

/**
 * In-memory PSR-16 cache, standing in for a persistent object cache.
 *
 * Parameters are untyped and return types are declared, so that it implements both psr/simple-cache 1.x and 3.x.
 */
class ArrayCache implements CacheInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    public function get($key, $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set($key, $value, $ttl = null): bool
    {
        $this->values[$key] = $value;
        return true;
    }

    public function delete($key): bool
    {
        unset($this->values[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->values = [];
        return true;
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }
        return $values;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
        return true;
    }

    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has($key): bool
    {
        return array_key_exists($key, $this->values);
    }
}
