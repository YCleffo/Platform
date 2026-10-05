<?php
declare(strict_types=1);
namespace ControlPlane\Payment\Provider;
/** Safe, authoritative provider payment-method snapshot. */
final readonly class ProviderPaymentMethod
{
    public function __construct(public string $id, public string $type, public bool $saved, public ?string $title = null, public ?string $brand = null, public ?string $last4 = null, public ?int $expiryMonth = null, public ?int $expiryYear = null, public ?string $status = null) {}
}
