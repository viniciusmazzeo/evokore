<?php
declare(strict_types=1);

namespace EvoKore\Support;

final class RequestLogger
{
    private string $logFile;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
    }

    /**
     * @param array<string,mixed> $entry
     */
    public function write(array $entry): void
    {
        try {
            $logDir = dirname($this->logFile);
            if (!is_dir($logDir) && !@mkdir($logDir, 0750, true) && !is_dir($logDir)) {
                return;
            }

            $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Falha de log nunca deve quebrar o fluxo do webhook.
        }
    }
}
