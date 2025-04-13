<?php

declare(strict_types=1);

namespace App\Models;

use InvalidArgumentException;

readonly class Transaction
{
    public function __construct(
        public string $bin,
        public float $amount,
        public string $currency,
    ) {
        $this->validateBin(bin: $this->bin);
        $this->validateAmountValue(amount: $this->amount);
        $this->validateCurrency(currency: $this->currency);
    }

    /**
     * Validation methods are private helpers for the constructor
     * @param string $bin
     * @throws \InvalidArgumentException
     * @return void
     */
    private function validateBin(string $bin): void
    {
        if (!preg_match('/^\d{6,16}$/', $bin)) {
            throw new InvalidArgumentException("Invalid BIN format provided: {$bin}");
        }
    }

    /**
     * Validates the transaction amount
     * @param float $amount
     * @throws \InvalidArgumentException
     * @return void
     */
    private function validateAmountValue(float $amount): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException("Invalid amount provided: {$amount}. Must be non-negative.");
        }
    }

    /**
     * Validates the currency code
     * @param string $currency
     * @throws \InvalidArgumentException
     * @return void
     */
    private function validateCurrency(string $currency): void
    {
        if (!preg_match('/^[A-Z]{3}$/i', $currency)) {
            throw new InvalidArgumentException("Invalid currency code provided: {$currency}. Must be 3 letters.");
        }
    }

    /**
     * Gets the BIN
     * @return string
     */
    public function getBin(): string
    {
        return $this->bin;
    }

    /**
     * Gets the transaction amount
     * @return float
     */
    public function getAmount(): float
    {
        return $this->amount;
    }

    /**
     * Gets the currency code in uppercase
     * @return string
     */
    public function getCurrency(): string
    {
        return strtoupper($this->currency);
    }
}
