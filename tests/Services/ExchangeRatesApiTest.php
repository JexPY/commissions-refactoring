<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Providers\Interfaces\CurrencyRateInterface;
use App\Services\ExchangeRatesApi;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(ExchangeRatesApi::class)]
class ExchangeRatesApiTest extends TestCase
{
    private MockObject&ClientInterface $clientMock;
    private MockObject&CacheItemPoolInterface $cachePoolMock;
    private MockObject&CacheItemInterface $cacheItemMock;
    private CurrencyRateInterface $service;

    private const VALID_API_RESPONSE_BODY = <<<JSON
{
    "success": true,
    "timestamp": 1744435392,
    "source": "USD",
    "quotes": {
        "USDEUR": 0.90,
        "USDJPY": 150.00,
        "USDGBP": 0.80,
        "USDAUD": 1.50
    }
}
JSON;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientMock = $this->createMock(ClientInterface::class);
        $this->cachePoolMock = $this->createMock(CacheItemPoolInterface::class);
        $this->cacheItemMock = $this->createMock(CacheItemInterface::class);

        $this->cachePoolMock->method('getItem')
            ->willReturn($this->cacheItemMock);

        $this->service = new ExchangeRatesApi(
            $this->clientMock,
            new NullLogger(),
            $this->cachePoolMock
        );
    }

    private function createHttpResponse(int $statusCode, string $body): Response
    {
        return new Response($statusCode, ['Content-Type' => 'application/json'], $body);
    }

    #[Test]
    public function getRateReturnsOneForEur(): void
    {
        $this->cachePoolMock->expects($this->never())->method('getItem');
        $this->clientMock->expects($this->never())->method('request');

        $this->assertSame(1.0, $this->service->getRate('EUR'));
        $this->assertSame(1.0, $this->service->getRate('eur'));
    }

    #[Test]
    #[DataProvider('rateCalculationProvider')]
    public function getRateCalculatesCorrectlyOnCacheHit(string $targetCurrency, float $expectedRate): void
    {
        $this->cacheItemMock->method('isHit')->willReturn(true);
        $this->cacheItemMock->method('get')->willReturn(self::VALID_API_RESPONSE_BODY);

        $this->clientMock->expects($this->never())->method('request');
        $this->cachePoolMock->expects($this->never())->method('save');

        $actualRate = $this->service->getRate($targetCurrency);

        $this->assertEqualsWithDelta($expectedRate, $actualRate, 0.00001);
    }

    #[Test]
    #[DataProvider('rateCalculationProvider')]
    public function getRateFetchesApiAndCalculatesCorrectlyOnCacheMiss(string $targetCurrency, float $expectedRate): void
    {
        $this->cacheItemMock->method('isHit')->willReturn(false);

        $mockResponse = $this->createHttpResponse(200, self::VALID_API_RESPONSE_BODY);
        $this->clientMock->expects($this->once())
            ->method('request')
            ->with('GET', '/live')
            ->willReturn($mockResponse);

        $this->cacheItemMock->expects($this->once())
            ->method('set')
            ->with(self::VALID_API_RESPONSE_BODY)
            ->willReturnSelf();
        $this->cacheItemMock->expects($this->once())
            ->method('expiresAfter')
            ->willReturnSelf();
        $this->cachePoolMock->expects($this->once())
            ->method('save')
            ->with($this->cacheItemMock);

        $actualRate = $this->service->getRate($targetCurrency);

        $this->assertEqualsWithDelta($expectedRate, $actualRate, 0.00001);
    }
    public static function rateCalculationProvider(): array
    {
        return [
            'JPY' => ['JPY', 166.666666],
            'GBP' => ['GBP', 0.888888],
            'USD' => ['USD', 1.111111],
            'AUD' => ['AUD', 1.666666],
        ];
    }

    #[Test]
    public function getRateThrowsExceptionOnApiErrorStatus(): void
    {
        $this->cacheItemMock->method('isHit')->willReturn(false);
        $request = new Request('GET', '/latest');
        $response = new Response(500);
        $exception = new ClientException('Server Error', $request, $response);
        $this->clientMock->method('request')->willThrowException($exception);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Rate API network request failed/');
        $this->cachePoolMock->expects($this->never())->method('save');

        $this->service->getRate('JPY');
    }

    #[Test]
    public function getRateThrowsExceptionOnApiNetworkError(): void
    {
        $this->cacheItemMock->method('isHit')->willReturn(false);
        $request = new Request('GET', '/latest');
        $exception = new ConnectException('Timeout', $request);
        $this->clientMock->method('request')->willThrowException($exception);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Rate API network request failed/');
        $this->cachePoolMock->expects($this->never())->method('save');

        $this->service->getRate('JPY');
    }

    #[Test]
    public function getRateThrowsExceptionOnInvalidJson(): void
    {
        $this->cacheItemMock->method('isHit')->willReturn(false);
        $mockResponse = $this->createHttpResponse(200, '{"quotes": {"USDEUR": 0.9}');
        $this->clientMock->method('request')->willReturn($mockResponse);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid JSON received/');
        $this->cachePoolMock->expects($this->never())->method('save');

        $this->service->getRate('JPY');
    }

    #[Test]
    public function getRateThrowsExceptionOnApiSuccessFalse(): void
    {
        $responseBody = json_encode([
            'success' => false,
            'error' => ['info' => 'API key invalid', 'type' => 'auth_error']
        ]);
        $this->cacheItemMock->method('isHit')->willReturn(false);
        $mockResponse = $this->createHttpResponse(200, $responseBody);
        $this->clientMock->method('request')->willReturn($mockResponse);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid API response: API key invalid/');
        $this->cachePoolMock->expects($this->never())->method('save');

        $this->service->getRate('JPY');
    }

    #[Test]
    #[DataProvider('missingDataResponseProvider')]
    public function getRateThrowsExceptionOnMissingData(string $responseBody, string $expectedMessage): void
    {
        $this->cacheItemMock->method('isHit')->willReturn(false);
        $mockResponse = $this->createHttpResponse(200, $responseBody);
        $this->clientMock->method('request')->willReturn($mockResponse);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches($expectedMessage);


        $this->service->getRate('JPY');
    }

    public static function missingDataResponseProvider(): array
    {
        return [
            'Missing quotes key' => [json_encode(['success' => true, 'source' => 'USD']), '/Missing \'quotes\'/'],
            'Quotes is null' => [json_encode(['success' => true, 'source' => 'USD', 'quotes' => null]), '/Missing \'quotes\'/'],
            'Missing USDEUR' => [json_encode(['success' => true, 'source' => 'USD', 'quotes' => ['USDJPY' => 150.0]]), '/Rate \'USDEUR\' not found/'],
            'Missing Target (USDJPY)' => [json_encode(['success' => true, 'source' => 'USD', 'quotes' => ['USDEUR' => 0.9]]), '/Rate \'USDJPY\' not found/'],
            'Invalid USDEUR (zero)' => [json_encode(['success' => true, 'source' => 'USD', 'quotes' => ['USDEUR' => 0, 'USDJPY' => 150.0]]), '/Invalid rate value for USDEUR/'],
            'Invalid USDEUR (non-numeric)' => [json_encode(['success' => true, 'source' => 'USD', 'quotes' => ['USDEUR' => 'abc', 'USDJPY' => 150.0]]), '/Invalid rate value for USDEUR/'],
            'Invalid Target (negative)' => [json_encode(['success' => true, 'source' => 'USD', 'quotes' => ['USDEUR' => 0.9, 'USDJPY' => -150.0]]), '/Invalid rate value for USDJPY/'],
        ];
    }

    #[Test]
    public function getRateFetchesFromApiOnInvalidCacheData(): void
    {
        $this->cacheItemMock->method('isHit')->willReturn(true);
        $this->cacheItemMock->method('get')->willReturn('{"invalid json');

        $mockResponse = $this->createHttpResponse(200, self::VALID_API_RESPONSE_BODY);
        $this->clientMock->expects($this->once())
            ->method('request')
            ->willReturn($mockResponse);

        $this->cacheItemMock->expects($this->once())->method('expiresAt'); 
        $this->cacheItemMock->expects($this->once())->method('set')->with(self::VALID_API_RESPONSE_BODY);
        $this->cacheItemMock->expects($this->once())->method('expiresAfter');
        $this->cachePoolMock->expects($this->exactly(2))
            ->method('save')
            ->with($this->cacheItemMock);


        $expectedRate = 166.666666;
        $actualRate = $this->service->getRate('JPY');
        $this->assertEqualsWithDelta($expectedRate, $actualRate, 0.00001);
    }

}
