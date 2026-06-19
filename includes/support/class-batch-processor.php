<?php
/**
 * Batch Processor Class
 *
 * Core batch processing logic with memory monitoring and adaptive batch sizing.
 * This is a plugin-agnostic library with zero dependencies on WordPress-specific code.
 *
 * @package BWS_Meta_Manager
 * @subpackage Libraries
 * @since 0.2.0
 */

namespace BWS\MetaConductor\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Batch Processor implementation
 *
 * Provides core functionality for:
 * - Memory-aware batch processing
 * - Adaptive batch sizing (5-100 items)
 * - Execution time tracking
 * - Progress reporting
 * - Resource limit monitoring
 */
class BatchProcessor implements BatchProcessorInterface {

    /**
     * Current batch size
     *
     * @var int
     */
    private $batch_size = 25;

    /**
     * Memory limit threshold (percentage)
     *
     * @var int
     */
    private $memory_threshold = 80;

    /**
     * Maximum execution time per batch (seconds)
     *
     * @var int
     */
    private $max_execution_time = 15;

    /**
     * Processing start time
     *
     * @var float
     */
    private $start_time = 0;

    /**
     * Items processed count
     *
     * @var int
     */
    private $items_processed = 0;

    /**
     * Processing statistics
     *
     * @var array
     */
    private $stats = [
        'total_items'      => 0,
        'processed_items'  => 0,
        'successful_items' => 0,
        'failed_items'     => 0,
        'execution_time'   => 0,
        'errors'           => [],
    ];

    /**
     * Minimum batch size
     *
     * @var int
     */
    private const MIN_BATCH_SIZE = 1;

    /**
     * Maximum batch size
     *
     * @var int
     */
    private const MAX_BATCH_SIZE = 1000;

    /**
     * Process items in batches with a callback function
     *
     * @param array    $items    Array of items to process
     * @param callable $callback Function to call for each item
     * @param array    $options  Processing options
     * @return array Processing result
     */
    public function process_batch( array $items, callable $callback, array $options = [] ): array {
        // Parse options
        $defaults = [
            'batch_size'     => $this->batch_size,
            'stop_on_error'  => false,
            'track_progress' => true,
        ];

        $options = array_merge( $defaults, $options );

        // Initialize processing
        $this->reset_stats();
        $this->start_time               = microtime( true );
        $this->stats['total_items']     = count( $items );
        $this->stats['stopped_early']   = false;

        $batch_size = $this->calculate_batch_size( $options['batch_size'], count( $items ) );
        $batches    = array_chunk( $items, $batch_size );

        // Process each batch
        foreach ( $batches as $batch ) {
            foreach ( $batch as $item ) {
                try {
                    $result = call_user_func( $callback, $item );

                    $this->items_processed++;
                    $this->stats['processed_items']++;

                    if ( $result === true || ( is_array( $result ) && ( $result['success'] ?? false ) ) ) {
                        $this->stats['successful_items']++;
                    } else {
                        $this->stats['failed_items']++;

                        if ( is_array( $result ) && isset( $result['error'] ) ) {
                            $this->stats['errors'][] = $result['error'];
                        }

                        if ( $options['stop_on_error'] ) {
                            break 2; // Break out of both loops
                        }
                    }
                } catch ( \Exception $e ) {
                    $this->stats['failed_items']++;
                    $this->stats['errors'][] = $e->getMessage();

                    if ( $options['stop_on_error'] ) {
                        break 2;
                    }
                }
            }

            // Check resource limits after each batch
            if ( $this->should_stop_processing() ) {
                $this->stats['stopped_early'] = true;
                break;
            }
        }

        // Calculate final execution time
        $this->stats['execution_time'] = microtime( true ) - $this->start_time;
        $this->stats['success']        = $this->stats['failed_items'] === 0 && ! $this->stats['stopped_early'];

        // Calculate items per second
        if ( $this->stats['execution_time'] > 0 ) {
            $this->stats['items_per_second'] = $this->stats['processed_items'] / $this->stats['execution_time'];
        } else {
            $this->stats['items_per_second'] = 0;
        }

        return $this->stats;
    }

    /**
     * Set batch size
     *
     * @param int $size Batch size
     * @return bool True if size was set successfully
     */
    public function set_batch_size( int $size ): bool {
        if ( $size < self::MIN_BATCH_SIZE || $size > self::MAX_BATCH_SIZE ) {
            return false;
        }

        $this->batch_size = $size;
        return true;
    }

    /**
     * Get current batch size
     *
     * @return int Current batch size
     */
    public function get_batch_size(): int {
        return $this->batch_size;
    }

    /**
     * Set memory limit threshold (percentage)
     *
     * @param int $percent Memory threshold percentage
     * @return bool True if limit was set successfully
     */
    public function set_memory_limit( int $percent ): bool {
        if ( $percent < 0 || $percent > 100 ) {
            return false;
        }

        $this->memory_threshold = $percent;
        return true;
    }

    /**
     * Get current memory limit threshold
     *
     * @return int Current memory threshold percentage
     */
    public function get_memory_limit(): int {
        return $this->memory_threshold;
    }

    /**
     * Set maximum execution time per batch (seconds)
     *
     * @param int $seconds Maximum execution time in seconds
     * @return bool True if time was set successfully
     */
    public function set_max_execution_time( int $seconds ): bool {
        if ( $seconds < 1 ) {
            return false;
        }

        $this->max_execution_time = $seconds;
        return true;
    }

    /**
     * Get current maximum execution time
     *
     * @return int Maximum execution time in seconds
     */
    public function get_max_execution_time(): int {
        return $this->max_execution_time;
    }

    /**
     * Check if processing should stop due to resource limits
     *
     * @return bool True if should stop
     */
    public function should_stop_processing(): bool {
        // Check memory usage
        $memory_info = $this->get_memory_info();
        if ( $memory_info['percent'] >= $this->memory_threshold ) {
            return true;
        }

        // Check execution time
        if ( $this->start_time > 0 ) {
            $elapsed = microtime( true ) - $this->start_time;
            if ( $elapsed >= $this->max_execution_time ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get processing statistics
     *
     * @return array Processing statistics
     */
    public function get_processing_stats(): array {
        $elapsed = $this->start_time > 0 ? microtime( true ) - $this->start_time : 0;
        $rate    = $elapsed > 0 ? $this->items_processed / $elapsed : 0;

        $memory_info = $this->get_memory_info();

        return [
            'memory_usage'     => $memory_info['current'],
            'memory_limit'     => $memory_info['limit'],
            'memory_percent'   => $memory_info['percent'],
            'execution_time'   => $elapsed,
            'items_processed'  => $this->items_processed,
            'items_per_second' => $rate,
        ];
    }

    /**
     * Calculate optimal batch size based on current memory usage
     *
     * @param int $default_size Default batch size
     * @param int $total_items  Total items to process
     * @return int Calculated batch size
     */
    public function calculate_batch_size( int $default_size, int $total_items ): int {
        $memory_info = $this->get_memory_info();

        // If memory usage is high, reduce batch size
        if ( $memory_info['percent'] > 70 ) {
            $size = max( 5, intval( $default_size * 0.5 ) );
        } elseif ( $memory_info['percent'] > 50 ) {
            $size = max( 10, intval( $default_size * 0.75 ) );
        } else {
            $size = $default_size;
        }

        // Don't exceed total items
        if ( $size > $total_items ) {
            $size = $total_items;
        }

        // Ensure within bounds
        $size = max( self::MIN_BATCH_SIZE, min( $size, self::MAX_BATCH_SIZE ) );

        return $size;
    }

    /**
     * Reset processing statistics
     *
     * @return bool True if statistics were reset
     */
    public function reset_stats(): bool {
        $this->stats = [
            'total_items'      => 0,
            'processed_items'  => 0,
            'successful_items' => 0,
            'failed_items'     => 0,
            'execution_time'   => 0,
            'errors'           => [],
            'stopped_early'    => false,
        ];

        $this->start_time      = 0;
        $this->items_processed = 0;

        return true;
    }

    /**
     * Get estimated time remaining
     *
     * @param int $items_remaining Number of items remaining
     * @return float Estimated seconds remaining, or -1 if cannot estimate
     */
    public function estimate_time_remaining( int $items_remaining ): float {
        if ( $this->items_processed === 0 || $this->start_time === 0 ) {
            return -1.0;
        }

        $elapsed = microtime( true ) - $this->start_time;
        $rate    = $this->items_processed / $elapsed;

        if ( $rate <= 0 ) {
            return -1.0;
        }

        return $items_remaining / $rate;
    }

    /**
     * Get memory usage information
     *
     * @return array Memory usage information
     */
    public function get_memory_info(): array {
        $current = memory_get_usage( true );
        $peak    = memory_get_peak_usage( true );
        $limit   = $this->get_memory_limit_bytes();

        $percent   = $limit > 0 ? ( $current / $limit ) * 100 : 0;
        $available = $limit > 0 ? $limit - $current : 0;

        return [
            'current'   => $current,
            'peak'      => $peak,
            'limit'     => $limit,
            'percent'   => $percent,
            'available' => max( 0, $available ),
        ];
    }

    /**
     * Get PHP memory limit in bytes
     *
     * @return int Memory limit in bytes
     */
    private function get_memory_limit_bytes(): int {
        $limit = ini_get( 'memory_limit' );

        if ( $limit === '-1' ) {
            return PHP_INT_MAX; // Unlimited
        }

        return $this->convert_to_bytes( $limit );
    }

    /**
     * Convert memory limit string to bytes
     *
     * @param string $value Memory limit string (e.g., "128M")
     * @return int Bytes
     */
    private function convert_to_bytes( string $value ): int {
        $value = trim( $value );
        $unit  = strtolower( substr( $value, -1 ) );
        $value = intval( $value );

        switch ( $unit ) {
            case 'g':
                $value *= 1024;
                // Fall through
            case 'm':
                $value *= 1024;
                // Fall through
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Get human-readable memory size
     *
     * @param int $bytes Bytes
     * @return string Human-readable size
     */
    public function format_bytes( int $bytes ): string {
        $units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
        $power = $bytes > 0 ? floor( log( $bytes, 1024 ) ) : 0;
        $power = min( $power, count( $units ) - 1 );

        return sprintf( '%.2f %s', $bytes / pow( 1024, $power ), $units[ $power ] );
    }

    /**
     * Get processing progress percentage
     *
     * @return float Progress percentage (0-100)
     */
    public function get_progress_percent(): float {
        if ( $this->stats['total_items'] === 0 ) {
            return 0.0;
        }

        return ( $this->stats['processed_items'] / $this->stats['total_items'] ) * 100;
    }

    /**
     * Check if processing is complete
     *
     * @return bool True if all items processed
     */
    public function is_complete(): bool {
        return $this->stats['total_items'] > 0 &&
               $this->stats['processed_items'] >= $this->stats['total_items'];
    }

    /**
     * Get processing summary
     *
     * @return array Processing summary with human-readable values
     */
    public function get_summary(): array {
        $memory_info = $this->get_memory_info();

        return [
            'total_items'      => $this->stats['total_items'],
            'processed_items'  => $this->stats['processed_items'],
            'successful_items' => $this->stats['successful_items'],
            'failed_items'     => $this->stats['failed_items'],
            'success_rate'     => $this->stats['processed_items'] > 0
                ? ( $this->stats['successful_items'] / $this->stats['processed_items'] ) * 100
                : 0,
            'execution_time'   => sprintf( '%.2f', $this->stats['execution_time'] ),
            'memory_used'      => $this->format_bytes( $memory_info['current'] ),
            'memory_peak'      => $this->format_bytes( $memory_info['peak'] ),
            'memory_percent'   => sprintf( '%.1f%%', $memory_info['percent'] ),
            'progress_percent' => sprintf( '%.1f%%', $this->get_progress_percent() ),
            'stopped_early'    => $this->stats['stopped_early'],
            'errors'           => $this->stats['errors'],
        ];
    }
}
