<?php

declare(strict_types=1);

namespace App\Providers\Interfaces;

interface BinLookupInterface
{
    /**
     * Get the ISO 3166-1 alpha-2 country code for a given BIN.
     * @param string $bin The Bank Identification Number.
     * @return string The two-letter country code.
     * @throws \Exception If the lookup fails or country code is not found.
     */
    public function getCountryCode(string $bin): string;
}
