<?php
declare(strict_types=1);

namespace Remita\BigCommerce\Support;

/**
 * Minimal PSR-3-flavoured append-only file logger.
 *
 * Writes newline-delimited JSON records to {logDir}/bigcommerce-remita.log.
 * Uses flock() for safe concurrent appends. Logging failures are silenced
 * so that a full disk or permissions error never aborts the payment flow.
 */
final class LoggerService implements LoggerInterface
{
    private string $logFile;

    /**
     * @param string $logDir Writable directory for log files.
     * @throws \RuntimeException if the directory cannot be created.
     */
    public function __construct(string $logDir)
    {
        $dir = rtrim($logDir, '/');

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("LoggerService: cannot create log directory {$dir}");
        }

        $this->logFile = $dir . '/bigcommerce-remita.log';
    }

    /**
     * @param array<string,mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $line = json_encode([
            'ts'      => gmdate('c'),
            'level'   => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        // Append using exclusive file lock to serialise concurrent writers.
        $fp = @fopen($this->logFile, 'ab');
        if ($fp === false) {
            // Fail silently — logging must never crash the payment flow.
            return;
        }

        flock($fp, LOCK_EX);
        fwrite($fp, $line);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
