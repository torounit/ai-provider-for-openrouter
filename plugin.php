<?php

/**
 * Plugin Name:       AI Provider for OpenRouter
 * Plugin URI:        https://github.com/torounit/ai-provider-for-openrouter
 * Description:       AI Provider for OpenRouter for the WordPress AI Client.
 * Requires at least: 7.2
 * Requires PHP:      8.3
 * Version:           0.1.0
 * Author:            Toro_Unit
 * Author URI:        https://torounit.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       ai-provider-for-openrouter
 * Update URI:        https://github.com/torounit/ai-provider-for-openrouter
 *
 * @package ToroUnit\OpenRouterAiProvider
 */

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider;

use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use WordPress\AiClient\AiClient;

if (!defined('ABSPATH')) {
    return;
}

// Preserve the path beneath WP_PLUGIN_DIR when WordPress loads the plugin through a symlink.
if (!defined('AI_PROVIDER_FOR_OPENROUTER_PLUGIN_DIR')) {
    define(
        'AI_PROVIDER_FOR_OPENROUTER_PLUGIN_DIR',
        WP_PLUGIN_DIR . '/' . dirname(plugin_basename(__FILE__))
    );
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the AI Provider for OpenRouter with the AI Client.
 *
 * Ported from the AI Provider for OpenAI, except that the provider ID is checked instead of the class name.
 *
 * @since 0.1.0
 *
 * @link https://github.com/WordPress/ai-provider-for-openai/blob/24ba3be/plugin.php
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    // Another plugin may already provide an OpenRouter provider with the same ID.
    if ($registry->hasProvider('openrouter')) {
        return;
    }

    $registry->registerProvider(OpenRouterProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);
