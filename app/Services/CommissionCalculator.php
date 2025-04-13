<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transaction;
use App\Providers\Interfaces\BinLookupInterface;
use App\Providers\Interfaces\CurrencyRateInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class CommissionCalculator
{

    /** * @var float*/
    private const float EU_COMMISSION_RATE = 0.01;

    /** * @var float*/
    private const float NON_EU_COMMISSION_RATE = 0.02;

    /** * @var array*/
    private const array EU_COUNTRIES = [
        'AT',
        'BE',
        'BG',
        'CY',
        'CZ',
        'DE',
        'DK',
        'EE',
        'ES',
        'FI',
        'FR',
        'GR',
        'HR',
        'HU',
        'IE',
        'IT',
        'LT',
        'LU',
        'LV',
        'MT',
        'NL',
        'PL',
        'PT',
        'RO',
        'SE',
        'SI',
        'SK',
    ];

    public function __construct(
        private readonly BinLookupInterface $binLookup,
        private readonly CurrencyRateInterface $currencyRateProvider,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Calculates the commission for a transaction in EUR.
     * 
     * @param \App\Models\Transaction $transaction
     * @throws \RuntimeException
     * @return float
     */
    public function calculateCommission(Transaction $transaction): float
    {
        $this->logger->info("Calculating commission for BIN: {$transaction->getBin()}");

        $countryCode = $this->binLookup->getCountryCode($transaction->getBin());
        $isEu = $this->isEu($countryCode);
        $this->logger->debug("Country Code: {$countryCode}, Is EU: " . ($isEu ? 'Yes' : 'No'));

        $rate = $this->currencyRateProvider->getRate($transaction->getCurrency());
        if ($rate <= 0) {
            throw new RuntimeException("Invalid exchange rate received: {$rate} for {$transaction->getCurrency()}");
        }
        $this->logger->debug("Exchange Rate ({$transaction->getCurrency()}/EUR): {$rate}");

        $amountInEur = ($transaction->getCurrency() === 'EUR')
            ? $transaction->getAmount()
            : $transaction->getAmount() / $rate;

        $this->logger->debug("Amount in EUR: {$amountInEur}");

        $commissionRate = $isEu ? self::EU_COMMISSION_RATE : self::NON_EU_COMMISSION_RATE;
        $this->logger->debug("Commission Rate applied: {$commissionRate}");

        $commission = $amountInEur * $commissionRate;
        $this->logger->debug("Raw Commission: {$commission}");

        $finalCommission = ceil($commission * 100) / 100;

        $this->logger->info("Calculated Commission: {$finalCommission} EUR");

        return $finalCommission;
    }

    /**
     * Checks if a country is in the EU.
     * 
     * @param string $countryCode
     * @return bool
     */
    private function isEu(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), self::EU_COUNTRIES, true);
    }
}
