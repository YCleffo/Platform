<?php

declare(strict_types=1);

namespace App\Core\Security\Domain;

enum SessionRiskLevel: string
{
    case NORMAL = 'NORMAL';
    case REVIEW = 'REVIEW';
    case HIGH_RISK = 'HIGH_RISK';
}
