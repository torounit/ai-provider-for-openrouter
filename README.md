# AI Provider for OpenRouter

An AI Provider for [OpenRouter](https://openrouter.ai/) for the [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK. Works as both a Composer package and a WordPress plugin.

OpenRouter gives access to hundreds of models from many providers (Anthropic, Google, OpenAI, and others) through a single API key.

## Requirements

- PHP 8.3 or higher
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
$result = wp_ai_client_prompt('Hello, world!')
    ->using_model_preference('claude-opus-4.7', 'gemini-3.1-pro-preview', 'gpt-5.4')
    ->generate_text_result();
```

Images are generated with models that support OpenRouter's images API, such as `gemini-3.1-flash-lite-image`. Use an aspect ratio or orientation instead of a size:

```php
$image = wp_ai_client_prompt('A red apple on a white background.')
    ->using_model_preference(['openrouter', 'gemini-3.1-flash-lite-image'])
    ->as_output_media_aspect_ratio('16:9')
    ->generate_image();
```

Text to speech uses models that take and output audio, such as `gpt-audio-mini`. OpenRouter only streams raw PCM audio, so the speech is returned as a WAV file:

```php
$speech = wp_ai_client_prompt('Hello from WordPress.')
    ->using_model_preference(['openrouter', 'gpt-audio-mini'])
    ->as_output_speech_voice('nova')
    ->convert_text_to_speech();
```

Embeddings are generated through the WordPress embedding API. Only text inputs are supported, and only OpenAI's `text-embedding-3-*` models accept `using_dimensions()`:

```php
$embeddings = wp_ai_client_embedding(['Hello', 'World'])
    ->using_provider_model('openrouter', 'nemotron-3.5-embed-1b:free')
    ->generate_embeddings();
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

Available models and their supported options are dynamically discovered from the OpenRouter API. See the [OpenRouter models list](https://openrouter.ai/models) for all models.

Like the Vercel AI Gateway provider, model IDs are exposed without the vendor prefix, e.g. `claude-opus-4.7` for `anthropic/claude-opus-4.7`, so that model preferences match the model IDs of other providers. Anthropic models are also available with dashes, e.g. `claude-opus-4-7`, as in the Anthropic provider. OpenRouter's own models keep their prefix (`openrouter/free`), as does a model whose ID is already taken by another vendor's model. To use OpenRouter's model IDs instead, set the `OPENROUTER_USE_FULL_MODEL_IDS` environment variable or constant to `true`.

A model preference that matches no model is skipped, so check the spelling of model IDs.

- `openrouter/free` is listed first, so it is used when no model is specified. It routes to free models only, so prompts without a model preference cost nothing.
- `openrouter/auto` picks a suitable model for each prompt from all models, including paid ones.
- Models that cannot be used through the Chat Completions API (e.g. `:batch` variants) are not listed.

Image, audio, and file inputs (PDFs and other documents or text files) can be sent to models that support them. Files are sent as inline data or by URL, and OpenRouter decides which file types it accepts.

## Configuration

The provider uses the `OPENROUTER_API_KEY` environment variable or PHP constant for authentication. In WordPress, the API key can also be entered in **Settings > Connectors**.

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
