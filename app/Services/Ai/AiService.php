<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Provider-agnostic AI helper for Streakly.
 *
 * Talks to either Anthropic Claude or OpenAI (selected via config/ai.php) using
 * Laravel's HTTP client, so no provider SDK is required. All features degrade
 * gracefully: when no API key is configured, enabled() returns false and the
 * UI hides every AI affordance.
 */
class AiService
{
    /** Whether AI features are usable (a key is configured for the active provider). */
    public function enabled(): bool
    {
        return filled($this->driver()['key'] ?? null);
    }

    public function provider(): string
    {
        return (string) config('ai.provider', 'claude');
    }

    /**
     * Parse a free-text description of a day into discrete activities.
     *
     * @param  array<int,array{name:string,points:int,icon:string}>  $knownTypes
     * @return array<int,array{name:string,points:int,icon:string}>
     */
    public function parseActivities(string $text, array $knownTypes = []): array
    {
        $catalog = collect($knownTypes)
            ->map(fn ($t) => "- {$t['name']} ({$t['points']} pts){$this->iconNote($t['icon'] ?? '')}")
            ->implode("\n");

        $system = <<<'PROMPT'
        You convert a person's free-text description of their day into a list of logged activities for a habit tracker.
        Rules:
        - Return ONLY a JSON array, no prose, no code fences.
        - Each item: {"name": string (<=80 chars), "points": integer 0-999, "icon": single emoji or ""}.
        - Reuse a known activity's exact name, points and icon when the text clearly refers to it.
        - For anything not in the known list, invent a short title, a sensible point value (1-10), and a fitting emoji.
        - If the text describes no real activity, return [].
        PROMPT;

        $user = "Known activities:\n".($catalog ?: '(none)')."\n\nDescription:\n".$text;

        $decoded = $this->jsonFromResponse($this->chat($system, $user));

        return collect(is_array($decoded) ? $decoded : [])
            ->map(fn ($row) => [
                'name'   => trim((string) ($row['name'] ?? '')),
                'points' => max(0, min(999, (int) ($row['points'] ?? 1))),
                'icon'   => trim((string) ($row['icon'] ?? '')),
            ])
            ->filter(fn ($row) => $row['name'] !== '')
            ->values()
            ->all();
    }

    /**
     * A short, encouraging insight about the user's recent activity.
     *
     * @param  array<string,int|float>  $stats
     * @param  array<string,int>        $recentDays  date => points
     */
    public function weeklyInsight(array $stats, array $recentDays): string
    {
        $system = <<<'PROMPT'
        You are a warm, concise habit coach for an app called Streakly.
        Given a user's stats and recent daily points, write 2-4 short sentences:
        celebrate what's going well, gently flag any slump, and give one concrete, friendly suggestion.
        Plain text only (no markdown headings, no lists). Keep it under 80 words. Address the user as "you".
        PROMPT;

        $user = 'Stats: '.json_encode($stats)
            ."\nLast 14 days (date => points): ".json_encode($recentDays);

        return trim($this->chat($system, $user));
    }

    /**
     * Suggest new activity types the user isn't tracking yet.
     *
     * @param  array<int,string>  $existingNames
     * @return array<int,array{name:string,points:int,icon:string}>
     */
    public function suggestActivities(array $existingNames): array
    {
        $system = <<<'PROMPT'
        You suggest healthy daily habits for a personal activity tracker.
        Return ONLY a JSON array of 4 items, no prose, no code fences.
        Each item: {"name": string (<=40 chars), "points": integer 1-15, "icon": single emoji}.
        Suggest varied, common, beneficial habits. Never repeat any habit already in the user's list.
        PROMPT;

        $user = "Already tracking: ".(implode(', ', $existingNames) ?: '(nothing yet)');

        $decoded = $this->jsonFromResponse($this->chat($system, $user));

        return collect(is_array($decoded) ? $decoded : [])
            ->map(fn ($row) => [
                'name'   => trim((string) ($row['name'] ?? '')),
                'points' => max(1, min(15, (int) ($row['points'] ?? 5))),
                'icon'   => trim((string) ($row['icon'] ?? '')),
            ])
            ->filter(fn ($row) => $row['name'] !== '')
            ->values()
            ->all();
    }

    // ---- Provider plumbing ------------------------------------------------

    /** @return array<string,mixed> */
    protected function driver(): array
    {
        $provider = $this->provider();

        return config("ai.drivers.{$provider}", []);
    }

    /** Send a single system+user turn and return the assistant's text. */
    protected function chat(string $system, string $user): string
    {
        if (! $this->enabled()) {
            throw new RuntimeException('AI is not configured.');
        }

        // Claude uses the native Anthropic Messages API; every other provider
        // (openai, groq, openrouter, ollama, …) speaks OpenAI chat-completions.
        return $this->provider() === 'claude'
            ? $this->chatClaude($system, $user)
            : $this->chatOpenAiCompatible($system, $user);
    }

    protected function chatClaude(string $system, string $user): string
    {
        $d = $this->driver();

        $response = Http::timeout((int) config('ai.timeout', 30))
            ->withHeaders([
                'x-api-key'         => $d['key'],
                'anthropic-version' => $d['version'] ?? '2023-06-01',
            ])
            ->post(rtrim($d['base_url'], '/').'/v1/messages', [
                'model'      => $d['model'],
                'max_tokens' => (int) config('ai.max_tokens', 1024),
                'system'     => $system,
                'messages'   => [
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Claude request failed: '.$response->json('error.message', $response->status()));
        }

        return collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');
    }

    protected function chatOpenAiCompatible(string $system, string $user): string
    {
        $d = $this->driver();

        // OpenRouter asks integrators to identify themselves (optional, harmless elsewhere).
        $headers = $this->provider() === 'openrouter'
            ? ['HTTP-Referer' => (string) config('app.url'), 'X-Title' => (string) config('app.name')]
            : [];

        $response = Http::timeout((int) config('ai.timeout', 30))
            ->withToken($d['key'])
            ->withHeaders($headers)
            ->post(rtrim($d['base_url'], '/').'/v1/chat/completions', [
                'model'       => $d['model'],
                'max_tokens'  => (int) config('ai.max_tokens', 1024),
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(ucfirst($this->provider()).' request failed: '.$response->json('error.message', $response->status()));
        }

        return (string) $response->json('choices.0.message.content', '');
    }

    /** Extract the first JSON value from a model response (tolerates code fences/prose). */
    protected function jsonFromResponse(string $text): mixed
    {
        $text = trim($text);

        // Strip ```json ... ``` fences if present.
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $m)) {
            $text = trim($m[1]);
        }

        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Fall back to the first {...} or [...] block.
        if (preg_match('/(\[.*\]|\{.*\})/s', $text, $m)) {
            return json_decode($m[1], true);
        }

        return null;
    }

    protected function iconNote(string $icon): string
    {
        return $icon !== '' ? " icon {$icon}" : '';
    }
}
