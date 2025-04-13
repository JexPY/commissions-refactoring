<?php

/**
 * Main Application Configuration File
 *
 * Handles environment loading and returns service definitions
 * for the dependency injection container.
 */

declare(strict_types=1);

use Dotenv\Dotenv;
use App\Services\BinListLookup;
use App\Services\ExchangeRatesApi;
use App\Services\CommissionCalculator;
use App\Providers\Interfaces\BinLookupInterface;
use App\Providers\Interfaces\CurrencyRateInterface;
use App\Container\ServiceContainer;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;

try {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();

    $dotenv->required(['BIN_LOOKUP_URL', 'EXCHANGE_RATES_URL', 'LOG_LEVEL', 'EXCHANGE_RATES_API_KEY'])
        ->notEmpty();
} catch (\Throwable $e) {
    fwrite(STDERR, "[CRITICAL] Environment configuration load failed: " . $e->getMessage() . "\n");
    exit(3);
}


return [
    'client_bin' => fn(): ClientInterface => new Client([
        'base_uri' => $_ENV['BIN_LOOKUP_URL'],
        'timeout' => 15.0,
        'headers' => ['Accept' => 'application/json']
    ]),

    'client_rate' => fn(): ClientInterface => new Client([
        'base_uri' => $_ENV['EXCHANGE_RATES_URL'],
        'timeout' => 15.0,
        'headers' => ['Accept' => 'application/json']
    ]),


    BinLookupInterface::class => fn(ServiceContainer $c): BinLookupInterface => new BinListLookup(
        $c->get('client_bin'),
        $c->get(LoggerInterface::class),
        $c->get(CacheItemPoolInterface::class)

    ),

    CurrencyRateInterface::class => fn(ServiceContainer $c): CurrencyRateInterface => new ExchangeRatesApi(
        $c->get('client_rate'),
        $c->get(LoggerInterface::class),
        $c->get(CacheItemPoolInterface::class)
    ),

    CommissionCalculator::class => fn(ServiceContainer $c): CommissionCalculator => new CommissionCalculator(
        $c->get(BinLookupInterface::class),
        $c->get(CurrencyRateInterface::class),
        $c->get(LoggerInterface::class)
    ),
];
