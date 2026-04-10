<?php
declare(strict_types=1);

namespace EvoKore\Security;

final class RateLimiter
{
    private string $storageDir;
    private int $maxRequests;
    private int $windowSeconds;

    public function __construct(string $storageDir, int $maxRequests, int $windowSeconds)
    {
        $this->storageDir = rtrim($storageDir, '/\\');
        $this->maxRequests = max(1, $maxRequests);
        $this->windowSeconds = max(1, $windowSeconds);
    }

    /**
     * @return array{allowed:bool,remaining:int,retry_after:int}
     */
    public function hit(string $key): array
    {
        try {
            if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0750, true) && !is_dir($this->storageDir)) {
                return [
                    'allowed' => true,
                    'remaining' => $this->maxRequests - 1,
                    'retry_after' => 0,
                ];
            }

            $bucketFile = $this->storageDir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
            $now = time();

            $fp = @fopen($bucketFile, 'c+');
            if ($fp === false) {
                return [
                    'allowed' => true,
                    'remaining' => $this->maxRequests - 1,
                    'retry_after' => 0,
                ];
            }

            flock($fp, LOCK_EX);
            rewind($fp);
            $raw = stream_get_contents($fp);
            $data = json_decode($raw !== false ? $raw : '', true);
            $hits = is_array($data['hits'] ?? null) ? $data['hits'] : [];

            $validHits = [];
            foreach ($hits as $hit) {
                $hitTs = (int) $hit;
                if (($now - $hitTs) < $this->windowSeconds) {
                    $validHits[] = $hitTs;
                }
            }

            $currentCount = count($validHits);
            if ($currentCount >= $this->maxRequests) {
                $oldest = min($validHits);
                $retryAfter = max(1, $this->windowSeconds - ($now - $oldest));

                $this->saveBucket($fp, $validHits);

                return [
                    'allowed' => false,
                    'remaining' => 0,
                    'retry_after' => $retryAfter,
                ];
            }

            $validHits[] = $now;
            $this->saveBucket($fp, $validHits);

            return [
                'allowed' => true,
                'remaining' => max(0, $this->maxRequests - count($validHits)),
                'retry_after' => 0,
            ];
        } catch (\Throwable) {
            return [
                'allowed' => true,
                'remaining' => $this->maxRequests - 1,
                'retry_after' => 0,
            ];
        } finally {
            if (isset($fp) && is_resource($fp)) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
        }
    }

    /**
     * @param resource $fp
     * @param int[] $hits
     */
    private function saveBucket($fp, array $hits): void
    {
        rewind($fp);
        ftruncate($fp, 0);
        @fwrite($fp, json_encode(['hits' => $hits], JSON_UNESCAPED_UNICODE));
        fflush($fp);
    }
}
