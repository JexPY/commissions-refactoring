<?php

declare(strict_types=1);

namespace App\Services;

use App\Providers\Interfaces\CurrencyRateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use DateTime;

/**
 * A service class to fetch exchange rates against EUR using the ExchangeRate.host API.
 * 
 * This class implements the CurrencyRateInterface and uses USD-based rates from the API,
 * performing a calculation (USD/TARGET ÷ USD/EUR = EUR/TARGET) to provide EUR-based rates.
 * The API provides rates refreshed every 60 minutes for free/basic plans, with USD as the only
 * source currency in the free tier (see https://exchangerate.host/documentation). To optimize
 * performance, responses are cached for 1 hour (CACHE_TTL).
 */

final class ExchangeRatesApi implements CurrencyRateInterface
{
    /**
     * Cache time-to-live in seconds (3600 = 1 hour). Matches the API's 60-minute refresh rate
     * for free/basic plans to avoid unnecessary requests.
     * 
     * @var int
     */
    private const CACHE_TTL = 3600;

    /**
     * Cache key for storing the latest USD-based exchange rates.
     * @var string
     */
    private const CACHE_KEY = 'latest_usd_rates';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache
    ) {}

    /**
     * Retrieves the exchange rate of a target currency against EUR.
     * 
     * @param string $currency
     * @throws \RuntimeException
     * @return float
     */
    public function getRate(string $currency): float
    {
        $currency = strtoupper($currency);
        if ($currency === 'EUR') {
            $this->logger->debug("Requested rate for EUR against EUR, returning 1.0");
            return 1.0;
        }

        $data = $this->getLatestRates();

        try {
            $quotes = $data['quotes'] ?? null;
            if ($quotes === null || !is_array($quotes)) {
                throw new RuntimeException("Missing or invalid 'quotes' data in retrieved rates.");
            }

            $rateUsdToEurKey = 'USDEUR';
            $rateUsdToEur = $quotes[$rateUsdToEurKey] ?? null;

            if ($rateUsdToEur === null) throw new RuntimeException("Rate '{$rateUsdToEurKey}' not found in API quotes.");
            if (!is_numeric($rateUsdToEur) || $rateUsdToEur <= 0) throw new RuntimeException("Invalid rate value for {$rateUsdToEurKey}: {$rateUsdToEur}");
            $rateUsdToEur = (float)$rateUsdToEur;

            $rateUsdToTarget = null;
            if ($currency === 'USD') {
                $rateUsdToTarget = 1.0;
            } else {
                $rateUsdToTargetKey = sprintf('USD%s', $currency);
                $rateUsdToTarget = $quotes[$rateUsdToTargetKey] ?? null;

                if ($rateUsdToTarget === null) throw new RuntimeException("Rate '{$rateUsdToTargetKey}' not found in API quotes.");
                if (!is_numeric($rateUsdToTarget) || $rateUsdToTarget < 0) throw new RuntimeException("Invalid rate value for {$rateUsdToTargetKey}: {$rateUsdToTarget}");
                $rateUsdToTarget = (float)$rateUsdToTarget;
            }

            $finalRate = $rateUsdToTarget / $rateUsdToEur;

            $this->logger->debug("Calculated EUR-based rate (1 EUR = X {$currency}): {$finalRate} using cached/fetched USD rates.");
            return $finalRate;
        } catch (\Throwable $e) {
            $this->logger->error("Exception during exchange rate data processing: " . $e->getMessage(), ['exception' => $e]);
            if ($e instanceof RuntimeException) throw $e;
            throw new RuntimeException("Exchange rate data processing failed: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Fetches the latest full set of rates from cache or API.
     *
     * @return array The decoded JSON data containing quotes.
     * @throws RuntimeException If fetching or decoding fails.
     */
    private function getLatestRates(): array
    {
        $cacheItem = $this->cache->getItem(self::CACHE_KEY);

        if ($cacheItem->isHit()) {
            $this->logger->debug("Cache HIT for key: " . self::CACHE_KEY);
            $responseBody = $cacheItem->get();
            $data = json_decode((string)$responseBody, true);

            if ($data !== null && isset($data['quotes'])) {
                return $data;
            } else {
                $this->logger->warning("Invalid data found in cache for key " . self::CACHE_KEY . ". Fetching fresh data.");

                $cacheItem->expiresAt(new DateTime('-1 second'));
                $this->cache->save($cacheItem);
            }
        }

        $this->logger->debug("Cache MISS for key: " . self::CACHE_KEY . ". Fetching from API.");
        try {
            $queryParams = [
                'access_key' => $_ENV['EXCHANGE_RATES_API_KEY'] ?? null
            ];

            $this->logger->debug("Requesting latest rates from API endpoint with params:", $queryParams);

            $response = $this->client->request(
                method: 'GET',
                uri: '/live',
                options: ['query' => $queryParams]
            );

            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException("API lookup failed status: " . $response->getStatusCode());
            }

            $responseBody = $response->getBody()->getContents();
            if (!json_validate($responseBody)) {
                throw new RuntimeException("Invalid JSON received from API.");
            }

            $data = json_decode($responseBody, true);

            if ((isset($data['success']) && $data['success'] === false) || !isset($data['quotes']) || !isset($data['source']) || $data['source'] !== 'USD') {
                $apiError = 'Unknown API Response Structure Issue';
                if (isset($data['success']) && $data['success'] === false) {
                    $apiError = $data['error']['info'] ?? $data['error']['type'] ?? 'Unknown API error';
                } elseif (!isset($data['quotes'])) {
                    $apiError = "Missing 'quotes' field";
                } elseif (!isset($data['source']) || $data['source'] !== 'USD') {
                    $apiError = "Missing or incorrect 'source' field (expected USD)";
                }
                $this->logger->error("Invalid or unsuccessful API response: {$apiError}");
                throw new RuntimeException("Invalid API response: {$apiError}");
            }

            $cacheItem->set($responseBody);
            $cacheItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($cacheItem);
            $this->logger->info("Fetched and cached latest rates. Key: " . self::CACHE_KEY);

            return $data;
        } catch (GuzzleException $e) {
            $this->logger->error("GuzzleException fetching latest rates: " . $e->getMessage(), ['exception' => $e]);
            throw new RuntimeException("Rate API network request failed: " . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            $this->logger->error("Exception fetching latest rates: " . $e->getMessage(), ['exception' => $e]);
            if ($e instanceof RuntimeException) throw $e;
            throw new RuntimeException("Rate API fetch failed: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }
}
