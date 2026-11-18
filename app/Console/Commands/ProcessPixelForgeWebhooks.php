<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use App\Services\PixelForge\WebhookEventProcessor;
use Illuminate\Console\Command;
use Throwable;

class ProcessPixelForgeWebhooks extends Command
{
    protected $signature = 'pixelforge:process-webhooks';

    protected $description = 'Retry Pixelforge webhook events that could not be applied yet';

    public function handle(WebhookEventProcessor $processor): int
    {
        $applied = 0;
        $waiting = 0;
        $failed = 0;

        // Oldest first, so a processing event is applied before its success.
        WebhookEvent::query()
            ->whereNull('processed_at')
            ->orderBy('received_at')
            ->orderBy('id')
            ->each(function (WebhookEvent $event) use ($processor, &$applied, &$waiting, &$failed): void {
                try {
                    $processor->process($event) ? $applied++ : $waiting++;
                } catch (Throwable $e) {
                    report($e);
                    $failed++;
                }
            });

        $this->info("Applied {$applied}, waiting {$waiting}, failed {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
