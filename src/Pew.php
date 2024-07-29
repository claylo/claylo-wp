<?php

namespace Claylo\Wp;

class Pew
{
    private array $durations = [];
    private array $startTimes = [];
    private int $conversionFactor;
    private ?string $logFilePath;

    public function __construct(string $timeUnit = 'milli', ?string $logFilePath = null)
    {
        $this->conversionFactor = $timeUnit === 'micro' ? 1e+3 : 1e+6;
        $this->logFilePath = $logFilePath;

        if ($this->logFilePath !== null) {
            register_shutdown_function([$this, 'writeLog']);
        }
    }

    public function begin(string $tag)
    {
        $start = hrtime(true);
        $this->startTimes[$tag] = $start;

        return new class($tag, $start, $this, $this->conversionFactor)
        {
            private string $tag;
            private float $startTime;
            private Pew $pew;
            private int $conversionFactor;

            public function __construct(string $tag, float $startTime, Pew $pew, int $conversionFactor)
            {
                $this->tag = $tag;
                $this->startTime = $startTime;
                $this->pew = $pew;
                $this->conversionFactor = $conversionFactor;
            }

            public function end(): void
            {
                $duration = (hrtime(true) - $this->startTime) / $this->conversionFactor;
                $this->pew->recordDuration($this->tag, $duration);
            }
        };
    }

    public function recordDuration(string $tag, float $duration): void
    {
        $this->durations[$tag] = ($this->durations[$tag] ?? 0) + $duration;
    }

    public function getDuration(string $tag): float
    {
        return $this->durations[$tag] ?? 0;
    }

    public function getTotalDuration(): float
    {
        return array_sum($this->durations);
    }

    public function getSummary(): array
    {
        return $this->durations;
    }

    public function getJsonSummary(): string
    {
        $data = ['timestamp' => date('c')] + $this->durations;
        return json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    public function writeLog(): void
    {
        if ($this->logFilePath !== null) {
            $logEntry = $this->getJsonSummary() . "\n";
            file_put_contents($this->logFilePath, $logEntry, FILE_APPEND);
        }
    }
}
