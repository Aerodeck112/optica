<?php

namespace App\Listeners;

use App\Domain\Messaging\MessageDispatcher;
use App\Domain\Orders\OrderReady;
use Throwable;

/** Pickup message in the patient's language, without clinical details (CMD-02). */
class QueueOrderReadyMessage
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function handle(OrderReady $event): void
    {
        try {
            $this->dispatcher->forOrder($event->order, 'order_ready');
        } catch (Throwable $e) {
            report($e);
        }
    }
}
