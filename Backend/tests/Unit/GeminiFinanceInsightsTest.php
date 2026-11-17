<?php

namespace Tests\Unit;

use App\Services\GeminiFinanceInsights;
use App\Services\GeminiNotConfiguredException;
use App\Services\GeminiUpstreamException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiFinanceInsightsTest extends TestCase
{
    public function test_it_requests_structured_recommendations_without_putting_the_key_in_the_url(): void
    {
        config(['services.gemini.api_key' => 'test-api-key', 'services.gemini.model' => 'gemini-3.8-flash']);
        $structuredText = json_encode([
            'summary' => 'Resumen financiero.',
            'good_habits' => ['Registras tus ingresos.'],
            'habits_to_improve' => ['Revisa gastos variables.'],
            'recommendations' => [['title' => 'Ahorro', 'detail' => 'Separa una cantidad fija.', 'priority' => 'medium']],
        ]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $structuredText]]]],
                ],
            ], 200),
        ]);

        $result = app(GeminiFinanceInsights::class)->generate(['currency' => 'DOP']);

        $this->assertSame('Resumen financiero.', $result['summary']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/models/gemini-3.8-flash:generateContent')
            && $request->hasHeader('x-goog-api-key', 'test-api-key')
            && !str_contains($request->url(), 'test-api-key')
            && data_get($request->data(), 'generationConfig.responseMimeType') === 'application/json');
    }

    public function test_it_fails_explicitly_when_gemini_is_not_configured(): void
    {
        config(['services.gemini.api_key' => null]);

        $this->expectException(GeminiNotConfiguredException::class);
        app(GeminiFinanceInsights::class)->generate([]);
    }

    public function test_it_retries_a_transient_gemini_server_error(): void
    {
        config(['services.gemini.api_key' => 'test-api-key', 'services.gemini.model' => 'gemini-3.8-flash']);
        $structuredText = json_encode([
            'summary' => 'Resumen financiero.',
            'good_habits' => [],
            'habits_to_improve' => [],
            'recommendations' => [],
        ]);
        Http::fakeSequence()
            ->push(['error' => ['message' => 'Temporary failure']], 503)
            ->push([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $structuredText]]]],
                ],
            ], 200);

        $result = app(GeminiFinanceInsights::class)->generate(['currency' => 'DOP']);

        $this->assertSame('Resumen financiero.', $result['summary']);
        Http::assertSentCount(2);
    }

    public function test_it_uses_the_configured_fallback_model_after_primary_model_overload(): void
    {
        config([
            'services.gemini.api_key' => 'test-api-key',
            'services.gemini.model' => 'gemini-3.8-flash',
            'services.gemini.fallback_models' => ['gemini-3.5-flash'],
        ]);
        $structuredText = json_encode([
            'summary' => 'Resumen financiero.',
            'good_habits' => [],
            'habits_to_improve' => [],
            'recommendations' => [],
        ]);
        Http::fakeSequence()
            ->push(['error' => ['message' => 'High demand']], 503)
            ->push(['error' => ['message' => 'High demand']], 503)
            ->push(['error' => ['message' => 'High demand']], 503)
            ->push([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $structuredText]]]],
                ],
            ], 200);

        $result = app(GeminiFinanceInsights::class)->generate(['currency' => 'DOP']);

        $this->assertSame('Resumen financiero.', $result['summary']);
        Http::assertSentCount(4);
        Http::assertSent(
            fn (Request $request) => str_contains($request->url(), '/models/gemini-3.5-flash:generateContent'),
        );
    }

    public function test_it_rejects_invalid_upstream_output(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => []], 200)]);

        $this->expectException(GeminiUpstreamException::class);
        app(GeminiFinanceInsights::class)->generate([]);
    }
}
