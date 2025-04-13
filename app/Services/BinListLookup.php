<?php

declare(strict_types=1);

namespace App\Services;

use App\Providers\Interfaces\BinLookupInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use DateTime;


final class BinListLookup implements BinLookupInterface
{
    /** Cache Time-To-Live: 30 days (BIN data changes infrequently) */
    private const int CACHE_TTL = 2592000;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache
    ) {}

    /**
     * Get the ISO 3166-1 alpha-2 country code for a given BIN.
     * Uses cache with a long TTL.
     *
     * @param string $bin The Bank Identification Number.
     * @return string The two-letter country code (uppercase).
     * @throws RuntimeException If lookup fails, country code is invalid, or rate limit hit.
     */
    public function getCountryCode(string $bin): string
    {
        $cacheKey = sprintf('bin_lookup_%s', preg_replace('/[^A-Za-z0-9_.]/', '_', $bin));
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            $cachedCountryCode = $cacheItem->get();
            if (is_string($cachedCountryCode) && strlen($cachedCountryCode) === 2) {
                $this->logger->debug("Cache HIT for BIN {$bin}.");
                return $cachedCountryCode;
            }
            $this->logger->warning("Invalid data in cache for BIN {$bin}. Discarding.");
            $cacheItem->expiresAt(new DateTime('-1 second'));
            $this->cache->save($cacheItem);
        }

        $this->logger->debug("Cache MISS for BIN {$bin}. Fetching from API.");
        try {
            $response = $this->client->request(method: 'GET', uri: $bin);

            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException("BIN API returned status code: " . $response->getStatusCode());
            }

            $body = $response->getBody()->getContents();

            if (!json_validate($body)) throw new RuntimeException("Invalid JSON received from BIN API.");
            $data = json_decode($body, true);
            if ($data === null) throw new RuntimeException("Failed to decode JSON from BIN API.");

            $countryCode = $data['country']['alpha2'] ?? null;

            if (!is_string($countryCode) || strlen($countryCode) !== 2) {
                $this->logger->warning("Country code missing or invalid in BIN API response for BIN {$bin}.");
                throw new RuntimeException("Country code missing or invalid in BIN API response for BIN: {$bin}");
            }

            $countryCode = strtoupper($countryCode);

            $cacheItem->set($countryCode);
            $cacheItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($cacheItem);
            $this->logger->info("Fetched and cached country code for BIN {$bin}.");

            return $countryCode;
        } catch (ClientException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            switch ($statusCode) {
                case 429:
                    $this->logger->error("RATE LIMIT HIT (429) on BIN lookup for {$bin}. Check API provider limits.");
                    throw new RuntimeException("BIN lookup failed due to API rate limit (429).", 429, $e);
                case 404:
                    $this->logger->warning("BIN lookup returned 404 Not Found for BIN: {$bin}.");
                    throw new RuntimeException("BIN {$bin} not found via lookup service.", 404, $e);
                default:
                    $this->logger->error("ClientException (Status: {$statusCode}) on BIN lookup for {$bin}: " . $e->getMessage(), ['exception' => $e]);
                    throw new RuntimeException("BIN lookup client error: " . $e->getMessage(), $statusCode, $e);
            }
        } catch (GuzzleException $e) {
            $this->logger->error("GuzzleException on BIN lookup for {$bin}: " . $e->getMessage(), ['exception' => $e]);
            throw new RuntimeException("BIN lookup network request failed: " . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {

            $this->logger->error("Unexpected exception during BIN lookup for {$bin}: " . $e->getMessage(), ['exception' => $e]);
            throw new RuntimeException("BIN lookup processing failed: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }
}
