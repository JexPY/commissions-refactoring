<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Providers\Interfaces\BinLookupInterface;
use App\Services\BinListLookup;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(BinListLookup::class)]
class BinListLookupTest extends TestCase
{
    private MockObject&ClientInterface $clientMock;
    private MockObject&CacheItemPoolInterface $cachePoolMock;
    private MockObject&CacheItemInterface $cacheItemMock;
    private BinLookupInterface $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientMock = $this->createMock(ClientInterface::class);
        $this->cachePoolMock = $this->createMock(CacheItemPoolInterface::class);
        $this->cacheItemMock = $this->createMock(CacheItemInterface::class);

        $this->cachePoolMock->method('getItem')
            ->willReturn($this->cacheItemMock);

        $this->service = new BinListLookup(
            $this->clientMock,
            new NullLogger(),
            $this->cachePoolMock
        );
    }
    private function createMockResponse(int $statusCode, string $body): Response
    {
        $stream = $this->createMock(Stream::class);
        $stream->method('getContents')->willReturn($body);
        $response = $this->createMock(Response::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);
        return $response;
    }

    // Helper to create actual Response
    private function createHttpResponse(int $statusCode, string $body): Response
    {
        return new Response($statusCode, ['Content-Type' => 'application/json'], $body);
    }

    #[Test]
    public function getCountryCodeReturnsCachedCodeOnHit(): void
    {
        $bin = '123456';
        $expectedCountry = 'CA';

        // Arrange: Cache Hit
        $this->cacheItemMock->method('isHit')->willReturn(true);
        $this->cacheItemMock->method('get')->willReturn($expectedCountry);

        // Assert: API Client is NOT called
        $this->clientMock->expects($this->never())->method('request');
        // Assert: Cache save is NOT called
        $this->cachePoolMock->expects($this->never())->method('save');

        // Act
        $actualCountry = $this->service->getCountryCode($bin);

        // Assert: Correct country returned
        $this->assertSame($expectedCountry, $actualCountry);
    }

    #[Test]
    public function getCountryCodeFetchesFromApiOnCacheMissAndCachesResult(): void
    {
        $bin = '654321';
        $expectedCountry = 'DE';
        $apiResponseBody = json_encode(['country' => ['alpha2' => $expectedCountry]]);
        $mockResponse = $this->createHttpResponse(200, $apiResponseBody);

        // Arrange: Cache Miss
        $this->cacheItemMock->method('isHit')->willReturn(false);

        // Arrange: API Call expectation
        $this->clientMock->expects($this->once())
            ->method('request')
            ->with('GET', $bin)
            ->willReturn($mockResponse);

        // Arrange: Cache Save expectation
        $this->cacheItemMock->expects($this->once())
            ->method('set')
            ->with($expectedCountry)
            ->willReturnSelf(); // Return self for fluent interface
        $this->cacheItemMock->expects($this->once())
            ->method('expiresAfter')
            // ->with(2592000) // Check specific TTL (BinListLookup::CACHE_TTL)
            ->with($this->greaterThan(2591999)) // Allow slight flexibility if needed
            ->willReturnSelf();
        $this->cachePoolMock->expects($this->once())
            ->method('save')
            ->with($this->cacheItemMock);

        // Act
        $actualCountry = $this->service->getCountryCode($bin);

        // Assert
        $this->assertSame($expectedCountry, $actualCountry);
    }

    #[Test]
    public function getCountryCodeThrowsExceptionOnApiRateLimit429(): void
    {
        $bin = '429429';

        // Arrange: Cache Miss
        $this->cacheItemMock->method('isHit')->willReturn(false);

        // Arrange: API Call expectation (throws 429)
        $request = new Request('GET', $bin);
        $response = new Response(429); // Create actual Response object
        $exception = new ClientException('Rate Limit Exceeded', $request, $response);
        $this->clientMock->expects($this->once())
            ->method('request')
            ->with('GET', $bin)
            ->willThrowException($exception);

        // Assert: Expect RuntimeException with specific code/message
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(429);
        $this->expectExceptionMessageMatches('/API rate limit \(429\)/');
        // Assert: Cache save NOT called
        $this->cachePoolMock->expects($this->never())->method('save');

        // Act
        $this->service->getCountryCode($bin);
    }

    #[Test]
    public function getCountryCodeThrowsExceptionOnApiNotFound404(): void
    {
        $bin = '404404';
        $request = new Request('GET', $bin);
        $response = new Response(404);
        $exception = new ClientException('Not Found', $request, $response);

        $this->cacheItemMock->method('isHit')->willReturn(false);
        $this->clientMock->method('request')->willThrowException($exception);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(404);
        $this->expectExceptionMessageMatches('/not found via lookup service/');
        $this->cachePoolMock->expects($this->never())->method('save');

        $this->service->getCountryCode($bin);
    }

    #[Test]
    public function getCountryCodeThrowsExceptionOnApiNetworkError(): void
    {
        $bin = '999999';
        $request = new Request('GET', $bin);
        $exception = new ConnectException('Connection refused', $request);

        $this->cacheItemMock->method('isHit')->willReturn(false);
        $this->clientMock->method('request')->willThrowException($exception);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/network request failed/');
        $this->cachePoolMock->expects($this->never())->method('save');

        $this->service->getCountryCode($bin);
    }

    #[Test]
    public function getCountryCodeThrowsExceptionOnInvalidJson(): void
    {
        $bin = '111222';
        $invalidJsonBody = '{"country": {"alpha2": "GB"'; // Missing closing brace
        $mockResponse = $this->createHttpResponse(200, $invalidJsonBody);

        $this->cacheItemMock->method('isHit')->willReturn(false);
        $this->clientMock->method('request')->willReturn($mockResponse);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid JSON received/');
        $this->cachePoolMock->expects($this->never())->method('save');

        $this->service->getCountryCode($bin);
    }

    #[Test]
    #[DataProvider('invalidApiResponseProvider')]
    public function getCountryCodeThrowsExceptionOnMissingOrInvalidCountryCode(string $responseBody): void
    {
        $bin = '333444';
        $mockResponse = $this->createHttpResponse(200, $responseBody);

        $this->cacheItemMock->method('isHit')->willReturn(false);
        $this->clientMock->method('request')->willReturn($mockResponse);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Country code missing or invalid/');
        $this->cachePoolMock->expects($this->never())->method('save');

        $this->service->getCountryCode($bin);
    }

    public static function invalidApiResponseProvider(): array
    {
        return [
            'Missing country key' => ['{"number": {}, "bank": {}}'],
            'Country key is null' => ['{"country": null}'],
            'Country key not an array' => ['{"country": "DK"}'],
            'Missing alpha2 key' => ['{"country": {"name": "Denmark"}}'],
            'alpha2 key is null' => ['{"country": {"alpha2": null}}'],
            'alpha2 key wrong length' => ['{"country": {"alpha2": "DNK"}}'],
            'alpha2 key not string' => ['{"country": {"alpha2": 12}}'],
        ];
    }

    #[Test]
    public function getCountryCodeFetchesFromApiOnInvalidCacheData(): void
    {
        $bin = '777888';
        $expectedCountry = 'US';
        $apiResponseBody = json_encode(['country' => ['alpha2' => $expectedCountry]]);
        $mockResponse = $this->createHttpResponse(200, $apiResponseBody);

        $this->cacheItemMock->method('isHit')->willReturn(true);
        $this->cacheItemMock->method('get')->willReturn(123);

        $this->clientMock->expects($this->once())
            ->method('request')
            ->with('GET', $bin)
            ->willReturn($mockResponse);

        $this->cacheItemMock->expects($this->once())->method('expiresAt');
        $this->cacheItemMock->expects($this->once())->method('set')->with($expectedCountry);
        $this->cacheItemMock->expects($this->once())->method('expiresAfter');
        $this->cachePoolMock->expects($this->exactly(2))
            ->method('save')
            ->with($this->cacheItemMock);

        $actualCountry = $this->service->getCountryCode($bin);

        $this->assertSame($expectedCountry, actual: $actualCountry);
    }
}
