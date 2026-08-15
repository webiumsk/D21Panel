<?php

namespace App\Services\RegWatch;

use App\Enums\RegWatchTopic;
use App\Models\RegWatchSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Claude API relevance classification of a detected source diff
 * (docs/LEGAL.md): the model only classifies and summarizes the supplied
 * diff text - it must never contribute legal facts, rates or thresholds.
 * Disabled (returns null) when no API key is configured; a failure never
 * blocks recording the change.
 */
class DiffClassifier
{
    public function enabled(): bool
    {
        return (string) config('regwatch.classifier.api_key') !== '';
    }

    /**
     * @return array{relevant: bool, confidence: string, topics: list<string>, summary: string}|null
     */
    public function classify(RegWatchSource $source, string $diff): ?array
    {
        if (! $this->enabled() || trim($diff) === '') {
            return null;
        }

        $topics = implode(', ', array_map(fn (RegWatchTopic $t) => $t->value, RegWatchTopic::cases()));

        $system = 'You classify diffs of official legal/tax web sources for a monitoring system. '
            .'Work ONLY with the diff text provided - never add legal facts, rates, thresholds or '
            .'deadlines from your own knowledge. Reply with a single JSON object, no markdown: '
            .'{"relevant": bool, "confidence": "low"|"medium"|"high", "topics": string[], "summary": string}. '
            ."\"relevant\" = the diff plausibly concerns any of these topics: {$topics}. "
            .'"topics" = the matching topic keys (empty if none). '
            .'"summary" = 1-3 sentences in Slovak describing what changed on the page, based strictly on the diff.';

        $user = "Source: {$source->name} ({$source->url})\nJurisdiction: {$source->jurisdiction?->code}\n\nDiff:\n{$diff}";

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('regwatch.classifier.api_key'),
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout((int) config('regwatch.classifier.timeout', 60))
                // Transient failures (connection, 429 rate limit, 5xx incl.
                // Anthropic's 529 overloaded) get a couple of retries before
                // the change is recorded unclassified.
                ->retry(
                    3,
                    (int) config('regwatch.classifier.retry_delay_ms', 1000),
                    fn ($exception) => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && ($exception->response->status() === 429 || $exception->response->status() >= 500)),
                    throw: false,
                )
                ->post(rtrim((string) config('regwatch.classifier.base_url'), '/').'/v1/messages', [
                    'model' => (string) config('regwatch.classifier.model'),
                    'max_tokens' => 1024,
                    'system' => $system,
                    'messages' => [
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('[regwatch] classifier API error', [
                    'source' => $source->slug,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $text = (string) $response->json('content.0.text', '');

            return $this->parseResult($text);
        } catch (\Throwable $e) {
            Log::warning('[regwatch] classifier failed', [
                'source' => $source->slug,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{relevant: bool, confidence: string, topics: list<string>, summary: string}|null
     */
    private function parseResult(string $text): ?array
    {
        // Tolerate a fenced or prefixed reply - take the outermost JSON object.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            return null;
        }

        $topics = array_values(array_filter(
            is_array($decoded['topics'] ?? null) ? $decoded['topics'] : [],
            'is_string',
        ));

        return [
            'relevant' => (bool) ($decoded['relevant'] ?? false),
            'confidence' => is_string($decoded['confidence'] ?? null) ? $decoded['confidence'] : 'low',
            'topics' => $topics,
            'summary' => is_string($decoded['summary'] ?? null) ? $decoded['summary'] : '',
        ];
    }
}
