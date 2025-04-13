<?php

namespace Tests\Services;

use App\Models\Transaction;
use App\Services\CommissionCalculator;
use App\Providers\Interfaces\BinLookupInterface;
use App\Providers\Interfaces\CurrencyRateInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(CommissionCalculator::class)]
class CommissionCalculatorTest extends TestCase
{
    private MockObject&BinLookupInterface $binLookupMock;
    private MockObject&CurrencyRateInterface $currencyRateMock;
    private CommissionCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->binLookupMock = $this->createMock(BinLookupInterface::class);
        $this->currencyRateMock = $this->createMock(CurrencyRateInterface::class);

        $this->calculator = new CommissionCalculator(
            $this->binLookupMock,
            $this->currencyRateMock,
            new NullLogger()
        );
    }

    #[Test]
    #[DataProvider('commissionCalculationProvider')]
    public function calculateCommissionCalculatesCorrectly(
        string $bin,
        string $amount,
        string $currency,
        string $mockCountryCode,
        float $mockRate,
        float $expectedCommission
    ): void {
        $this->binLookupMock->method('getCountryCode')
            ->with($bin)
            ->willReturn($mockCountryCode);

        $this->currencyRateMock->method('getRate')
            ->with(strtoupper($currency))
            ->willReturn($mockRate);

        $transaction = new Transaction($bin, $amount, $currency);
        $actualCommission = $this->calculator->calculateCommission($transaction);

        $this->assertEqualsWithDelta($expectedCommission, $actualCommission, 0.00001, "Failed calculation for {$currency}");
    }

    public static function commissionCalculationProvider(): array
    {
        return [
            'EU Card, EUR Currency' => ['45717360', '100.00', 'EUR', 'DE', 1.0, 1.00],
            'Non-EU Card, USD Currency' => ['516793', '50.00', 'USD', 'US', 1.1, 0.91],
            'Non-EU Card, JPY Currency (Zero Decimal)' => ['45417360', '10000.00', 'JPY', 'JP', 130.0, 1.54],
            'EU Card, GBP Currency' => ['4745030', '2000.00', 'GBP', 'FR', 0.85, 23.53],
            'Zero Amount' => ['123456', '0.00', 'USD', 'US', 1.2, 0.00],
            'Small Commission Ceiling (EU)' => ['999999', '1.00', 'USD', 'IE', 1.1, 0.01],
            'Small Commission Ceiling (Non-EU)' => ['999998', '1.00', 'JPY', 'CA', 150.0, 0.01],
            'Exact Cent Commission (EU)' => ['111111', '150.00', 'EUR', 'ES', 1.0, 1.50],
            'Exact Cent Commission (Non-EU)' => ['222222', '75.00', 'EUR', 'AU', 1.0, 1.50],
            'Just Above Cent Commission (EU)' => ['333333', '150.01', 'EUR', 'IT', 1.0, 1.51],
            'Just Above Cent Commission (Non-EU)' => ['444444', '75.01', 'EUR', 'CH', 1.0, 1.51],

        ];
    }

    #[Test]
    public function calculateCommissionThrowsExceptionWhenBinLookupFails(): void
    {
        $bin = '123456';
        $expectedException = new RuntimeException("BIN lookup failed");

        $this->binLookupMock->method('getCountryCode')
            ->with($bin)
            ->willThrowException($expectedException);

            $this->currencyRateMock->expects($this->never())->method('getRate');

        $this->expectExceptionObject($expectedException);

        $transaction = new Transaction($bin, '100.00', 'USD');
        $this->calculator->calculateCommission($transaction);
    }

    #[Test]
    public function calculateCommissionThrowsExceptionWhenRateLookupFails(): void
    {
        $currency = 'XYZ';
        $expectedException = new RuntimeException("Rate lookup failed");

        $this->binLookupMock->method('getCountryCode')->willReturn('DE');
        $this->currencyRateMock->method('getRate')
            ->with($currency)
            ->willThrowException($expectedException);

        $this->expectExceptionObject($expectedException);

        $transaction = new Transaction('45717360', '100.00', $currency);
        $this->calculator->calculateCommission($transaction);
    }

    #[Test]
    public function calculateCommissionThrowsExceptionWhenRateIsZeroOrNegative(): void
    {
        $currency = 'ABC';

        $this->binLookupMock->method('getCountryCode')->willReturn('US');
        $this->currencyRateMock->method('getRate')
            ->with($currency)
            ->willReturn(0.0); // Invalid rate

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid exchange rate received:/');

        $transaction = new Transaction('123456', '100.00', $currency);
        $this->calculator->calculateCommission($transaction);
    }
}
