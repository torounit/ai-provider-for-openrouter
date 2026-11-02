# AI Provider for OpenRouter

An AI Provider for [OpenRouter](https://openrouter.ai/) for the [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK. Works as both a Composer package and a WordPress plugin.

OpenRouter gives access to hundreds of models from many providers (Anthropic, Google, OpenAI, and others) through a single API key.

## Requirements

- PHP 7.4 or higher
- When using with WordPress, requires WordPress 7.2 or higher
- An [OpenRouter API key](https://openrouter.ai/settings/keys)

## Installation

### As a Composer Package

```bash
composer require torounit/ai-provider-for-openrouter
```

The package is not published on Packagist, so add this repository to the `repositories` section of your `composer.json` first:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/torounit/ai-provider-for-openrouter"
        }
    ]
}
```

### As a WordPress Plugin

1. Download the plugin files
2. Upload to `/wp-content/plugins/ai-provider-for-openrouter/`
3. Activate the plugin through the WordPress admin
4. Enter your API key in **Settings > Connectors**

This plugin is distributed through GitHub only. A different plugin with the same slug exists on WordPress.org, so this plugin sets the `Update URI` header to prevent WordPress from offering that plugin as an update.

## Usage

### With WordPress

The provider automatically registers itself with the AI Client on the `init` hook.

```php
$text = wp_ai_client_prompt('Hello, world!')
    ->using_model_preference(['openrouter', 'anthropic/claude-sonnet-5.5'])
    ->generate_text();
```

### As a Standalone Package

```php
use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use WordPress\AiClient\AiClient;

// Register the provider
$registry = AiClient::defaultRegistry();
$registry->registerProvider(OpenRouterProvider::class);

// Set your API key
putenv('OPENROUTER_API_KEY=your-api-key');

// Generate text
$result = AiClient::prompt('Explain quantum computing')
    ->usingModelPreference(['openrouter', 'openrouter/free'])
    ->generateTextResult();

echo $result->toText();
```

## Supported Models

Available models and their supported options are dynamically discovered from the OpenRouter API. Model IDs are OpenRouter's own IDs, e.g. `anthropic/claude-sonnet-5.5` or `nvidia/nemotron-3.5-super-vl:free`. See the [OpenRouter models list](https://openrouter.ai/models) for all models.

- `openrouter/auto` is listed first, so it is used when no model is specified. It picks a suitable model for each prompt.
- `openrouter/free` routes to free models only.
- Models that cannot be used through the Chat Completions API (e.g. `:batch` variants) are not listed.

## Configuration

The provider uses the `OPENROUTER_API_KEY` environment variable or PHP constant for authentication. In WordPress, the API key can also be entered in **Settings > Connectors**.

Requests include the `HTTP-Referer` and `X-Title` [app attribution](https://openrouter.ai/docs/app-attribution) headers. They identify this plugin, not your site.

## Development

```bash
composer install
composer lint   # PHP_CodeSniffer and PHPStan
composer test   # PHPUnit
```

A local WordPress environment is available through [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env):

```bash
npx @wordpress/env start
```

To use your API key in that environment, create a `.wp-env.override.json` file (ignored by Git):

```json
{
    "config": {
        "OPENROUTER_API_KEY": "your-api-key"
    }
}
```

## License

GPL-2.0-or-later
