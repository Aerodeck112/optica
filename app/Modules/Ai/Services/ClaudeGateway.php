<?php

namespace App\Modules\Ai\Services;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use App\Modules\Core\Models\Integration;

/**
 * Singurul punct de apel către Claude API. Cheia API se citește din integrarea „ai” (criptată în baza de date)
 * și nu ajunge niciodată în browser. Poate fi înlocuit în teste cu o implementare falsă.
 */
class ClaudeGateway
{
    public function isConfigured(): bool
    {
        $integration = Integration::query()->where('key', 'ai')->first();

        return $integration && $integration->driver === 'anthropic' && $integration->isUsable() && $integration->credential('api_key');
    }

    public function model(): string
    {
        return (string) (Integration::query()->where('key', 'ai')->first()?->options['model'] ?? config('aw.ai.model'));
    }

    public function effort(): string
    {
        $effort = (string) (Integration::query()->where('key', 'ai')->first()?->options['effort'] ?? config('aw.ai.effort'));

        return in_array($effort, ['low', 'medium', 'high'], true) ? $effort : 'medium';
    }

    /**
     * @param  array<int, array<string, mixed>>  $system
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     */
    public function send(array $system, array $messages, array $tools): BetaMessage
    {
        $client = new Client(apiKey: (string) Integration::query()->where('key', 'ai')->first()?->credential('api_key'));

        return $client->beta->messages->create(
            model: $this->model(),
            maxTokens: (int) config('aw.ai.max_output_tokens', 8000),
            system: $system,
            messages: $messages,
            tools: $tools,
            outputConfig: ['effort' => $this->effort()],
            // la un refuz din motive de siguranță, serverul reîncearcă automat pe modelul de rezervă recomandat
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );
    }
}
