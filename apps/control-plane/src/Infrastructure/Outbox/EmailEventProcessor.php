<?php
declare(strict_types=1);
namespace ControlPlane\Infrastructure\Outbox;
interface EmailEventProcessor { public function processOne(): bool; }
