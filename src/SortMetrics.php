<?php

declare(strict_types=1);

namespace Andileco\CsvSort;

/**
 * Tracks performance metrics during sorting
 */
class SortMetrics
{
    private float $startTime;
    private ?float $endTime = null;
    
    public int $recordsProcessed = 0;
    public int $chunksCreated = 0;
    public int $mergePasses = 0;
    public int $tempFilesCreated = 0;
    public int $peakMemory = 0;
    public int $totalBytesRead = 0;
    public int $totalBytesWritten = 0;
    
    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->peakMemory = memory_get_peak_usage(true);
    }
    
    /**
     * Mark the sort as complete
     */
    public function finish(): void
    {
        $this->endTime = microtime(true);
        $this->peakMemory = memory_get_peak_usage(true);
    }
    
    /**
     * Update peak memory usage
     */
    public function updatePeakMemory(): void
    {
        $current = memory_get_peak_usage(true);
        if ($current > $this->peakMemory) {
            $this->peakMemory = $current;
        }
    }
    
    /**
     * Get total elapsed time
     */
    public function getTotalTime(): float
    {
        $end = $this->endTime ?? microtime(true);
        return round($end - $this->startTime, 3);
    }
    
    /**
     * Get records processed per second
     */
    public function getRecordsPerSecond(): float
    {
        $time = $this->getTotalTime();
        return $time > 0 ? round($this->recordsProcessed / $time, 2) : 0;
    }
    
    /**
     * Get a summary of all metrics
     * 
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_time' => $this->getTotalTime(),
            'records_processed' => $this->recordsProcessed,
            'records_per_second' => $this->getRecordsPerSecond(),
            'chunks_created' => $this->chunksCreated,
            'merge_passes' => $this->mergePasses,
            'temp_files_created' => $this->tempFilesCreated,
            'peak_memory_bytes' => $this->peakMemory,
            'peak_memory_mb' => round($this->peakMemory / 1024 / 1024, 2),
            'total_bytes_read' => $this->totalBytesRead,
            'total_bytes_written' => $this->totalBytesWritten,
        ];
    }
}
