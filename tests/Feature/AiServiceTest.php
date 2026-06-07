<?php

namespace Tests\Feature;

use App\Services\Ai\AiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiServiceTest extends TestCase
{
    public function test_it_is_disabled_without_an_api_key(): void
    {
        config(['ai.provider' => 'claude', 'ai.drivers.claude.key' => null]);

        $this->assertFalse(app(AiService::class)->enabled());
    }

    public function test_it_is_enabled_when_a_key_is_present(): void
    {
        config(['ai.provider' => 'claude', 'ai.drivers.claude.key' => 'sk-test']);

        $this->assertTrue(app(AiService::class)->enabled());
    }

    public function test_it_parses_activities_from_claude(): void
    {
        config([
            'ai.provider' => 'claude',
            'ai.drivers.claude.key' => 'sk-test',
            'ai.drivers.claude.base_url' => 'https://api.anthropic.com',
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => '[{"name":"Walk","points":5,"icon":"🚶"},{"name":"Reading","points":3,"icon":"📖"}]',
                ]],
            ]),
        ]);

        $result = app(AiService::class)->parseActivities('walked and read a bit', [
            ['name' => 'Walk', 'points' => 5, 'icon' => '🚶'],
        ]);

        $this->assertSame([
            ['name' => 'Walk', 'points' => 5, 'icon' => '🚶'],
            ['name' => 'Reading', 'points' => 3, 'icon' => '📖'],
        ], $result);

        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'sk-test')
            && $request->url() === 'https://api.anthropic.com/v1/messages');
    }

    public function test_it_tolerates_code_fences_in_suggestions_from_openai(): void
    {
        config([
            'ai.provider' => 'openai',
            'ai.drivers.openai.key' => 'sk-openai',
            'ai.drivers.openai.base_url' => 'https://api.openai.com',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => "```json\n[{\"name\":\"Meditate\",\"points\":4,\"icon\":\"🧘\"}]\n```"],
                ]],
            ]),
        ]);

        $result = app(AiService::class)->suggestActivities(['Walk']);

        $this->assertSame([['name' => 'Meditate', 'points' => 4, 'icon' => '🧘']], $result);
    }
}
