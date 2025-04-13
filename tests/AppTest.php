<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
class AppTest extends TestCase
{
    private static string $phpPath = PHP_BINARY;
    private static string $appPath = __DIR__ . '/../app.php';
    private static string $fixturesPath = __DIR__ . '/fixtures';

    private static array $testEnv = [
        'APP_ENV' => 'test',
        'LOG_LEVEL' => 'INFO',
        'BIN_LOOKUP_URL' => 'http://mock.test',
        'EXCHANGE_RATES_URL' => 'http://mock.test',
        'EXCHANGE_RATES_API_KEY' => 'mock_key',
    ];

    private function runApp(string $inputFile): Process
    {
        $filePath = self::$fixturesPath . '/' . $inputFile;
        if (!file_exists($filePath)) {
            $this->fail("Fixture file not found: {$filePath}");
        }

        $process = new Process(
            command: [self::$phpPath, self::$appPath, $filePath],
            cwd: dirname(self::$appPath),
            env: self::$testEnv
        );
        $process->run();
        return $process;
    }

    public function testAppWithValidInput(): void
    {
        $expectedOutput = "1" . PHP_EOL . "0.91" . PHP_EOL . "47.06" . PHP_EOL . "0.67" . PHP_EOL;

        $process = $this->runApp('input_valid.txt');

        $this->assertTrue($process->isSuccessful(), "Process failed: " . $process->getErrorOutput());
        $this->assertEquals(0, $process->getExitCode());
        $this->assertEquals($expectedOutput, $process->getOutput());
        $this->assertStringContainsString('Processing finished.', $process->getErrorOutput());
        $this->assertStringNotContainsString('[ERROR]', $process->getErrorOutput());
        $this->assertStringNotContainsString('[WARNING]', $process->getErrorOutput());
    }

    public function testAppWithMixedInput(): void
    {
         $expectedOutput = "1" . PHP_EOL . "0.91" . PHP_EOL . "0.67" . PHP_EOL;

         $process = $this->runApp('input_mixed.txt');

         $stderr = $process->getErrorOutput();

         $this->assertTrue($process->isSuccessful(), "Process failed: " . $stderr);
         $this->assertEquals(0, $process->getExitCode());
         $this->assertEquals($expectedOutput, $process->getOutput());

         $this->assertStringContainsString('[Validation Error] Line 3: Invalid BIN format provided: BROKEN', $stderr);
         $this->assertStringContainsString('[Validation Error] Line 4: Invalid amount provided: -50. Must be non-negative.', $stderr);
         $this->assertStringContainsString('Processing finished.', $stderr);
    }

     public function testAppHandlesMissingInputFileArgument(): void
     {
         $process = new Process([self::$phpPath, self::$appPath], dirname(self::$appPath), self::$testEnv);
         $process->run();

         $this->assertFalse($process->isSuccessful(), "Process should have failed.");
         $this->assertNotEquals(0, $process->getExitCode());
         $this->assertStringContainsString('Usage: php app.php <input_file>', $process->getErrorOutput());
     }

     public function testAppHandlesNonExistentInputFile(): void
     {
         $process = new Process([self::$phpPath, self::$appPath, 'non_existent_file.txt'], dirname(self::$appPath), self::$testEnv);
         $process->run();

         $this->assertFalse($process->isSuccessful());
         $this->assertNotEquals(0, $process->getExitCode());
         $this->assertStringContainsString('Input file not found or not readable', $process->getErrorOutput());
     }
}