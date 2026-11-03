<?php

/**
 * PSR-4 autoloader for the AI Provider for OpenRouter package.
 *
 * Ported from the AI Provider for OpenAI, with only the namespace changed.
 *
 * @since 0.1.0
 *
 * @link https://github.com/WordPress/ai-provider-for-openai/blob/24ba3be/src/autoload.php
 *
 * @package ToroUnit\OpenRouterAiProvider
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'ToroUnit\\OpenRouterAiProvider\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);

    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
