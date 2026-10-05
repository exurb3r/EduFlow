<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiProviders;

use App\Models\AiProvider;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Proves a configured provider answers before it is relied on.
 *
 * Deliberately does not send a completion request, so it costs nothing and
 * cannot leak student data. It only lists models.
 *
 * The `/v1` handling is not decoration. OpenAI-compatible gateways commonly
 * serve a marketing site or 404 page at the bare host, so probing the exact
 * base URL returns HTML with a 404 and looks like a bad key. Falling back to
 * `/v1` distinguishes "wrong path" from "wrong credentials", which is the
 * difference between a fixable typo and an unusable key.
 */
final class AiProviderConnectionTest
{
    public static function run(AiProvider $record): void
    {
        if (! $record->isUsable()) {
            self::failed('Provider is not usable', 'It is inactive, has no model, or is openai-compatible without a base URL.');

            return;
        }

        if ($record->driver !== 'openai-compatible' || blank($record->base_url)) {
            self::failed(
                'Saved without testing',
                "Only openai-compatible endpoints can be probed this way. {$record->name} was saved; make a real call to confirm it."
            );

            return;
        }

        $headers = array_merge(
            $record->headers ?? [],
            filled($record->api_key) ? ['Authorization' => 'Bearer '.$record->api_key] : [],
        );

        $base = rtrim((string) $record->base_url, '/');

        try {
            $direct = self::get($headers, $base.'/models');

            if ($direct->successful()) {
                self::succeeded($record, $direct);

                return;
            }

            // A 404 here usually means the host is serving a site, not an API.
            $withV1 = self::get($headers, $base.'/v1/models');

            if ($withV1->successful()) {
                self::succeeded($record, $withV1, suggestedUrl: $base.'/v1');

                return;
            }

            self::failed(
                'Endpoint returned '.$direct->status(),
                self::explain($direct, $withV1),
            );
        } catch (Throwable $e) {
            self::failed('Could not reach the endpoint', $e->getMessage());
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function get(array $headers, string $url): Response
    {
        return Http::withHeaders($headers)->timeout(10)->get($url);
    }

    private static function succeeded(AiProvider $record, Response $response, ?string $suggestedUrl = null): void
    {
        $models = array_column((array) ($response->json('data') ?? []), 'id');
        $count = count($models);
        $hasModel = in_array($record->model, $models, true);

        $body = "{$record->name} answered on /models: {$count} model".($count === 1 ? '' : 's').' available.';

        if (! $hasModel) {
            // The endpoint works, but the configured model is not served here.
            $body .= ' Warning: "'.$record->model.'" is not in that list, so calls will fail.';
        }

        if ($suggestedUrl !== null) {
            $body .= ' Save the base URL as '.$suggestedUrl.' — the bare host serves a web page, not the API.';
        }

        self::failed('Endpoint reachable', $body, 'success');
    }

    private static function failed(string $title, string $body, string $type = 'danger'): void
    {
        Notification::make()
            ->{$type}()
            ->title($title)
            ->body($body)
            ->persistent()
            ->send();
    }

    private static function explain(Response $direct, Response $withV1): string
    {
        // A JSON error body is the endpoint talking, so the key is the problem.
        $message = $direct->json('error.message');

        if (is_string($message) && $message !== '') {
            return 'The endpoint responded: '.$message;
        }

        // HTML means we never reached an API, so the path is the problem.
        if (str_contains($direct->body(), '<!DOCTYPE')) {
            return 'The host served an HTML page, not an API. Check the base URL — OpenAI-compatible '
                .'gateways almost always live under /v1.';
        }

        return 'Both "'.$direct->effectiveUri()->getPath().'" and the /v1 variant failed ('
            .$direct->status().'/'.$withV1->status().'). Check the base URL, key, and any extra headers.';
    }
}
