<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiProviders;

use App\Models\AiProvider;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Proves a configured provider actually answers before it is relied on.
 *
 * Deliberately does not send a completion request, so it costs nothing and
 * cannot leak student data. It only checks that the endpoint exists and speaks
 * the expected shape.
 */
final class AiProviderConnectionTest
{
    public static function run(AiProvider $record): void
    {
        if (! $record->isUsable()) {
            Notification::make()
                ->danger()
                ->title('Provider is not usable')
                ->body('It is inactive, has no model, or is openai-compatible without a base URL.')
                ->send();

            return;
        }

        if ($record->driver !== 'openai-compatible' || blank($record->base_url)) {
            Notification::make()
                ->warning()
                ->title('Saved without testing')
                ->body("Only openai-compatible endpoints can be probed this way. {$record->name} was saved; make a real call to confirm it.")
                ->send();

            return;
        }

        try {
            // /models is a cheap, read-only, provider-agnostic probe.
            $response = Http::withHeaders(array_merge(
                $record->headers ?? [],
                filled($record->api_key) ? ['Authorization' => 'Bearer '.$record->api_key] : [],
            ))
                ->timeout(10)
                ->get(rtrim((string) $record->base_url, '/').'/models');

            if ($response->successful()) {
                Notification::make()
                    ->success()
                    ->title('Endpoint reachable')
                    ->body("{$record->name} answered on /models. Model selection still has to match what it serves.")
                    ->send();

                return;
            }

            Notification::make()
                ->danger()
                ->title('Endpoint returned '.$response->status())
                ->body('The URL responded but not successfully. Check the base URL and key.')
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Could not reach the endpoint')
                ->body($e->getMessage())
                ->send();
        }
    }
}
