<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Container\ServiceContainer;
use App\Models\Transaction;
use App\Services\CommissionCalculator;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use App\Providers\Interfaces\BinLookupInterface;
use App\Providers\Interfaces\CurrencyRateInterface;

$definitions = require __DIR__ . '/config/configs.php';

$appEnv = getenv('APP_ENV') ?: 'prod';

$logLevel = Level::fromName($_ENV['LOG_LEVEL'] ?? 'INFO') ?? Level::Info;
$logger = new Logger('commission-calculator-app-' . $appEnv);
$logger->pushHandler(new StreamHandler('php://stderr', $logLevel));

$cachePool = ($appEnv === 'test')
    ? new ArrayAdapter()
    : (function () use ($logger): CacheItemPoolInterface {
          $cacheDirectory = __DIR__ . '/cache';
          if (!is_dir($cacheDirectory)) {
              if (!mkdir($cacheDirectory, 0775, true)) { $logger->error("Failed cache dir create: {$cacheDirectory}"); }
          } elseif (!is_writable($cacheDirectory)) { $logger->warning("Cache dir not writable: {$cacheDirectory}"); }
          return new FilesystemAdapter('app_cache', 0, $cacheDirectory);
      })();

$container = new ServiceContainer();
$container->set(LoggerInterface::class, fn() => $logger);
$container->set(CacheItemPoolInterface::class, fn() => $cachePool);

if ($appEnv === 'test') {
    $logger->info('TEST ENV: Binding Mock Services');
    $container->set(BinLookupInterface::class, fn() => new Tests\Mocks\MockBinLookup());
    $container->set(CurrencyRateInterface::class, fn() => new Tests\Mocks\MockExchangeRatesApi());

    $container->set(CommissionCalculator::class, fn(ServiceContainer $c): CommissionCalculator => new CommissionCalculator(
        $c->get(BinLookupInterface::class),
        $c->get(CurrencyRateInterface::class),
        $c->get(LoggerInterface::class)
    ));
} else {
    foreach ($definitions as $id => $factory) {
         if (is_callable($factory)) {
            $container->set((string)$id, $factory);
         } else {
            $logger->warning("Non-callable definition found for ID '{$id}'");
         }
    }

    if (!$container->has(CommissionCalculator::class)){
        if(isset($definitions[CommissionCalculator::class]) && is_callable($definitions[CommissionCalculator::class])) {
            $container->set(CommissionCalculator::class, $definitions[CommissionCalculator::class]);
        } else {
             $container->set(CommissionCalculator::class, fn(ServiceContainer $c): CommissionCalculator => new CommissionCalculator(
                 $c->get(BinLookupInterface::class),
                 $c->get(CurrencyRateInterface::class),
                 $c->get(LoggerInterface::class)
             ));
        }
    }
}

if ($argc < 2) {
    $logger->critical("Usage: php app.php <input_file>");
    exit(1);
}
$inputFile = $argv[1];

if (!file_exists($inputFile) || !is_readable($inputFile)) {
     $logger->critical("Input file not found or not readable: {$inputFile}");
     exit(1);
}

try {
    $calculator = $container->get(CommissionCalculator::class);
} catch (\Throwable $e) {
    $logger->critical("Failed to initialize CommissionCalculator: " . $e->getMessage(), ['exception' => $e]);
    exit(1);
}

$handle = fopen($inputFile, "r");
if (!$handle) {
    $logger->critical("Could not open input file: {$inputFile}");
    exit(1);
}

$lineNumber = 0;
while (($line = fgets($handle)) !== false) {
    $lineNumber++;
    $line = trim($line);
    if ($line === '') continue;
    if (!json_validate($line)) { $logger->warning("Invalid JSON line {$lineNumber}"); continue; }
    $data = json_decode($line, true);
    if (!isset($data['bin'], $data['amount'], $data['currency']) || !is_string($data['bin']) ||
        !is_numeric($data['amount']) || !is_string($data['currency'])) {
         $logger->warning("Invalid/missing fields line {$lineNumber}"); continue;
    }

    try {
        $transaction = new Transaction((string)$data['bin'], (float)$data['amount'], (string)$data['currency']);
        $commission = $calculator->calculateCommission($transaction);
        print $commission . PHP_EOL;
    } catch (\InvalidArgumentException $e) { $logger->error("[Validation Error] Line {$lineNumber}: " . $e->getMessage());
    } catch (\Throwable $e) { $logger->error("[Processing Error] Line {$lineNumber}: " . $e->getMessage()); }
}

fclose($handle);
$logger->info("Processing finished.");
exit(0);