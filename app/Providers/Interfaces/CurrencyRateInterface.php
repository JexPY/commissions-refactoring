<?php

declare(strict_types=1);

namespace App\Providers\Interfaces;

interface CurrencyRateInterface
{
    /**
     * Get the exchange rate for a given currency relative to EUR.
     * @param string $currency The 3-letter ISO 4217 currency code.
     * @return float The exchange rate (1 EUR = X Currency). Returns 1.0 if currency is EUR.
     * @throws \Exception If the rate lookup fails or the currency rate is not found.
     */
    public function getRate(string $currency): float;
}