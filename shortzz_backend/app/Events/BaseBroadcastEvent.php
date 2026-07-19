<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Log;

// Generic realtime event: name + payload + channels. Broadcast synchronously
// (ShouldBroadcastNow) because the shared host runs no queue worker. Use
// fire() from controllers — a transport outage must never fail the request;
// clients recover missed events through the REST polling fallback.
class BaseBroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public string $name;
    public array $payload;
    public array $channels;

    public function __construct(string $name, array $payload, array $channels)
    {
        $this->name = $name;
        $this->payload = $payload;
        $this->channels = $channels;
    }

    public static function fire(string $name, array $payload, array $channels)
    {
        try {
            broadcast(new self($name, $payload, $channels));
        } catch (\Throwable $e) {
            Log::error("broadcast $name failed: " . $e->getMessage());
        }
    }

    public function broadcastOn(): array
    {
        return $this->channels;
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
