<?php

namespace Tests\Mocks;

use App\Providers\Interfaces\CurrencyRateInterface;
use RuntimeException;

final class MockExchangeRatesApi implements CurrencyRateInterface
{
     // Define predictable EUR-based rates for tests
     // 1 EUR = X Currency
     private const RATES = [
         'USD' => 1.10, // 1 EUR = 1.10 USD
         'JPY' => 150.00, // 1 EUR = 150 JPY
         'GBP' => 0.85, // 1 EUR = 0.85 GBP
         'AUD' => 1.65, // 1 EUR = 1.65 AUD
         'RATE_ERROR' => null, // Simulate failure to get rate
     ];

    public function getRate(string $currency): float
    {
        $currency = strtoupper($currency);

        if ($currency === 'EUR') {
            return 1.0;
        }

        if ($currency === 'RATE_ERROR') {
             throw new RuntimeException("Simulated rate lookup failure for {$currency}");
        }

        $rate = self::RATES[$currency] ?? null;

        if ($rate === null) {
             throw new RuntimeException("Mock Rate not found for currency '{$currency}'.");
        }

        return $rate;
    }
}