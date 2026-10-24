<?php

namespace Tests\Support;

use Anthropic\Beta\Messages\BetaMessage;
use App\Modules\Ai\Services\ClaudeGateway;

/** Înlocuiește Claude API în teste: răspunsuri scriptate, cererile sunt păstrate pentru verificări. */
class FakeClaudeGateway extends ClaudeGateway
{
    /** @var array<int, array<string, mixed>> */
    public array $responses = [];

    /** @var array<int, array{system: array, messages: array, tools: array}> */
    public array $requests = [];

    public function isConfigured(): bool
    {
        return true;
    }

    public function model(): string
    {
        return 'claude-opus-5-5';
    }

    public function send(array $system, array $messages, array $tools): BetaMessage
    {
        $this->requests[] = ['system' => $system, 'messages' => json_decode(json_encode($messages), true), 'tools' => $tools];
        $next = array_shift($this->responses) ?? self::text('Bine.');

        return BetaMessage::fromArray($next);
    }

    public static function text(string $text): array
    {
        return self::message('end_turn', [['type' => 'text', 'text' => $text]]);
    }

    public static function toolUse(string $name, array $input, string $id = 'toolu_1'): array
    {
        return self::message('tool_use', [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-'.$id], ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]]);
    }

    public static function message(string $stopReason, array $content): array
    {
        return ['id' => 'msg_'.uniqid(), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => $stopReason, 'stop_sequence' => null,
            'content' => $content, 'usage' => ['input_tokens' => 1000, 'output_tokens' => 200, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0]];
    }
}
