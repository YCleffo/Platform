<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider\YooKassa;

use ControlPlane\Payment\Provider\ProviderAccount;

final class YooKassaFiscalPolicy
{
    /** @var list<string> */
    private const PAYMENT_SUBJECTS = [
        'commodity', 'excise', 'job', 'service', 'gambling_bet', 'gambling_prize',
        'lottery', 'lottery_prize', 'intellectual_activity', 'payment',
        'agent_commission', 'composite', 'another', 'property_right',
        'non_operating_gain', 'insurance_premium', 'sales_tax', 'resort_fee',
        'marked', 'non_marked', 'fine', 'tax', 'lien', 'cost',
        'pension_insurance_without_payouts', 'pension_insurance_with_payouts',
        'health_insurance_without_payouts', 'health_insurance_with_payouts',
        'health_insurance', 'casino_chips', 'agent_payment',
        'excisable_goods_without_marking_code', 'excisable_goods_with_marking_code',
        'goods_without_marking_code', 'goods_with_marking_code',
    ];

    public static function validate(ProviderAccount $account): void
    {
        if ($account->providerCode !== 'yookassa' || $account->receiptMode !== 'yookassa'
            || $account->receiptVatCode === null || $account->receiptVatCode < 1 || $account->receiptVatCode > 12
            || !is_string($account->receiptPaymentSubject) || !in_array($account->receiptPaymentSubject, self::PAYMENT_SUBJECTS, true)
            || $account->receiptPaymentMode !== 'full_payment') {
            throw new \DomainException('YooKassa fiscal receipt configuration is incomplete or unsupported.');
        }
    }

    public static function validateValues(int $vatCode, string $paymentSubject, string $paymentMode): void
    {
        self::validate(new ProviderAccount('validation', 'yookassa', 'validation', 'validation', 'yookassa', $vatCode, $paymentSubject, $paymentMode));
    }
}
