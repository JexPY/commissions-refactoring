<?php

namespace Tests\Mocks;

use App\Providers\Interfaces\BinLookupInterface;
use RuntimeException;

final class MockBinLookup implements BinLookupInterface
{
    private const BIN_COUNTRY_MAP = [
        '45717360' => 'DE', // EU
        '516793' => 'US', // Non-EU
        '400000' => 'GB', // Non-EU (Example)
        '411111' => 'FR', // EU (Example)
        'BIN_NOT_FOUND' => null, // Simulate a BIN not found by API
        'BIN_ERROR' => 'THROW_ERROR', // Simulate general lookup failure
    ];

    public function getCountryCode(string $bin): string
    {
        $result = self::BIN_COUNTRY_MAP[$bin] ?? null;

        if ($result === 'THROW_ERROR') {
            throw new RuntimeException("Simulated BIN lookup failure for {$bin}");
        }

        if ($result === null) {
             throw new RuntimeException("Mock BIN {$bin} not found.", 404);
        }

        return $result;
    }
}