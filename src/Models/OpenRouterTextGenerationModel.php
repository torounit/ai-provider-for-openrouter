<?php

declare(strict_types=1);

namespace ToroUnit\OpenRouterAiProvider\Models;

use ToroUnit\OpenRouterAiProvider\Provider\OpenRouterProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Class for an OpenRouter text generation model using the OpenAI compatible Chat Completions API.
 *
 * @since 0.1.0
 */
class OpenRouterTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        return new Request(
            $method,
            OpenRouterProvider::url($path),
            $headers,
            $data,
            $this->getRequestOptions()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $params = parent::prepareGenerateTextParams($prompt);

        // OpenRouter supports top_k for many models, which the OpenAI compatible implementation does not send.
        $topK = $this->getConfig()->getTopK();
        if ($topK !== null) {
            if (isset($params['top_k'])) {
                throw new InvalidArgumentException(
                    'The custom option "top_k" conflicts with an existing parameter.'
                );
            }
            $params['top_k'] = $topK;
        }

        return $params;
    }

    /**
     * {@inheritDoc}
     *
     * Sends the reasoning details from previous model messages back, which some models require to continue their
     * reasoning, e.g. across function calls.
     *
     * Based on the AI Provider for OpenAI, which sends reasoning items back the same way.
     *
     * @since 0.1.0
     *
     * @link https://openrouter.ai/docs/guides/best-practices/reasoning-tokens#preserving-reasoning
     * @link https://github.com/WordPress/ai-provider-for-openai/blob/24ba3be/src/Models/OpenAiTextGenerationModel.php
     */
    protected function prepareMessagesParam(array $messages, ?string $systemInstruction = null): array
    {
        $messagesParam = array_values(parent::prepareMessagesParam($messages, $systemInstruction));

        // The system instruction, if any, is prepended to the messages.
        $offset = count($messagesParam) - count($messages);
        foreach (array_values($messages) as $index => $message) {
            if (!$message->getRole()->isModel()) {
                continue;
            }
            $reasoningDetails = $this->getReasoningDetails($message);
            if ($reasoningDetails) {
                $messagesParam[$index + $offset]['reasoning_details'] = $reasoningDetails;
            }
        }

        return $messagesParam;
    }

    /**
     * Returns the reasoning details stored in the thought signatures of the given message.
     *
     * @since 0.1.0
     *
     * @param Message $message The message.
     * @return list<array<mixed>> The reasoning details, or an empty list if there are none.
     */
    private function getReasoningDetails(Message $message): array
    {
        $reasoningDetails = [];
        foreach ($message->getParts() as $part) {
            if (!$part->getChannel()->isThought()) {
                continue;
            }
            $signature = $part->getThoughtSignature();
            if ($signature === null || $signature === '') {
                continue;
            }
            $decoded = json_decode($signature, true);
            if (!is_array($decoded) || !array_is_list($decoded)) {
                continue;
            }
            foreach ($decoded as $entry) {
                // Skip signatures from other providers, which OpenRouter would reject.
                if (
                    is_array($entry) &&
                    isset($entry['type']) &&
                    is_string($entry['type']) &&
                    str_starts_with($entry['type'], 'reasoning.')
                ) {
                    $reasoningDetails[] = $entry;
                }
            }
        }
        return $reasoningDetails;
    }

    /**
     * {@inheritDoc}
     *
     * Parses OpenRouter's reasoning into a thought part. The reasoning details are stored as its thought signature,
     * so that they can be sent back in subsequent requests.
     *
     * Based on the AI Provider for OpenAI, which keeps reasoning items as thought signatures the same way.
     *
     * @since 0.1.0
     *
     * @link https://github.com/WordPress/ai-provider-for-openai/blob/24ba3be/src/Models/OpenAiTextGenerationModel.php
     */
    protected function parseResponseChoiceMessageParts(array $messageData, int $index): array
    {
        $parts = parent::parseResponseChoiceMessageParts($messageData, $index);

        $reasoningDetails = isset($messageData['reasoning_details']) && is_array($messageData['reasoning_details'])
            ? $messageData['reasoning_details']
            : [];
        $reasoning = isset($messageData['reasoning']) && is_string($messageData['reasoning'])
            ? $messageData['reasoning']
            : $this->getReasoningDetailsText($reasoningDetails);

        // The parent parses `reasoning_content` into a thought part, which this replaces to avoid duplicates.
        if (isset($parts[0]) && $parts[0]->getChannel()->isThought()) {
            if ($reasoning === '') {
                $reasoning = (string) $parts[0]->getText();
            }
            array_shift($parts);
        }

        if ($reasoning === '' && !$reasoningDetails) {
            return $parts;
        }

        $signature = $reasoningDetails ? json_encode($reasoningDetails) : null;
        array_unshift(
            $parts,
            new MessagePart($reasoning, MessagePartChannelEnum::thought(), $signature ?: null)
        );

        return $parts;
    }

    /**
     * Returns the readable text of the given reasoning details.
     *
     * @since 0.1.0
     *
     * @param array<mixed> $reasoningDetails The reasoning details from the API response.
     * @return string The text and summaries of the reasoning details, one per line.
     */
    private function getReasoningDetailsText(array $reasoningDetails): string
    {
        $texts = [];
        foreach ($reasoningDetails as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (isset($entry['text']) && is_string($entry['text'])) {
                $texts[] = $entry['text'];
            } elseif (isset($entry['summary']) && is_string($entry['summary'])) {
                $texts[] = $entry['summary'];
            }
        }
        return implode("\n", $texts);
    }

    /**
     * {@inheritDoc}
     *
     * Adds support for documents and text files, which are sent as `file` content parts, either inline or by URL.
     * Like the AI Provider for OpenAI, this leaves it to the API to reject file types that it does not support.
     *
     * Based on the AI Provider for OpenAI, which also sends all other files as files.
     *
     * @since 0.1.0
     *
     * @link https://openrouter.ai/docs/guides/overview/multimodal/pdfs
     * @link https://github.com/WordPress/ai-provider-for-openai/blob/24ba3be/src/Models/OpenAiTextGenerationModel.php
     */
    protected function getMessagePartContentData(MessagePart $part): ?array
    {
        $file = $part->getFile();
        if ($file !== null && ($file->isDocument() || $file->isText())) {
            return [
                'type' => 'file',
                'file' => [
                    'filename' => $this->getFilename($file),
                    'file_data' => $file->isRemote() ? $file->getUrl() : $file->getDataUri(),
                ],
            ];
        }

        return parent::getMessagePartContentData($part);
    }

    /**
     * Returns the filename to send for a file.
     *
     * @since 0.1.0
     *
     * @param File $file The file.
     * @return string The filename from the URL, or a generic filename, with an extension matching the MIME type.
     */
    private function getFilename(File $file): string
    {
        $filename = 'document';

        $url = $file->getUrl();
        if ($url !== null) {
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && basename($path) !== '') {
                $filename = rawurldecode(basename($path));
            }
        }

        if (pathinfo($filename, PATHINFO_EXTENSION) === '') {
            $filename .= '.' . $file->getMimeTypeObject()->toExtension();
        }

        return $filename;
    }

    /**
     * {@inheritDoc}
     *
     * The OpenAI compatible implementation passes the schema as `json_schema` directly, while the API expects it to
     * be wrapped in an object with a name. Strict mode is not enabled, since it only accepts schemas that meet
     * additional requirements, which vary between the providers that OpenRouter routes to.
     *
     * The schema name matches the AI Provider for OpenAI.
     *
     * @since 0.1.0
     *
     * @link https://openrouter.ai/docs/guides/features/structured-outputs
     * @link https://github.com/WordPress/ai-provider-for-openai/blob/24ba3be/src/Models/OpenAiTextGenerationModel.php
     */
    protected function prepareResponseFormatParam(?array $outputSchema): array
    {
        if (!is_array($outputSchema)) {
            return parent::prepareResponseFormatParam($outputSchema);
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'response_schema',
                'schema' => $outputSchema,
            ],
        ];
    }
}
