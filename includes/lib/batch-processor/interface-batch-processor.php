<?php
/**
 * Batch Processor Interface
 *
 * Defines the contract for memory-aware batch processing operations.
 * This is a plugin-agnostic library for processing large datasets in batches
 * with memory monitoring and progress tracking.
 *
 * @package BWS_Meta_Manager
 * @subpackage Libraries
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface for batch processing operations
 *
 * Implementations of this interface should provide batch processing
 * without depending on any specific plugin architecture.
 */
interface BWS_Batch_Processor_Interface {

    /**
     * Process items in batches with a callback function
     *
     * @param array    $items    Array of items to process
     * @param callable $callback Function to call for each item. Should return bool for success/failure.
     * @param array    $options {
     *     Optional. Processing options.
     *
     *     @type int  $batch_size      Number of items per batch. Default 25.
     *     @type bool $stop_on_error   Whether to stop processing on first error. Default false.
     *     @type bool $track_progress  Whether to track progress. Default true.
     * }
     * @return array {
     *     Processing result.
     *
     *     @type bool  $success          Whether processing completed
     *     @type int   $total_items      Total items to process
     *     @type int   $processed_items  Number of items processed
     *     @type int   $successful_items Number of successful items
     *     @type int   $failed_items     Number of failed items
     *     @type float $execution_time   Total execution time in seconds
     *     @type array $errors           Array of error messages
     *     @type bool  $stopped_early    Whether processing stopped due to limits
     * }
     */
    public function process_batch( array $items, callable $callback, array $options = [] ): array;

    /**
     * Set batch size
     *
     * @param int $size Batch size (min 1, max 1000)
     * @return bool True if size was set successfully
     */
    public function set_batch_size( int $size ): bool;

    /**
     * Get current batch size
     *
     * @return int Current batch size
     */
    public function get_batch_size(): int;

    /**
     * Set memory limit threshold (percentage)
     *
     * @param int $percent Memory threshold percentage (0-100)
     * @return bool True if limit was set successfully
     */
    public function set_memory_limit( int $percent ): bool;

    /**
     * Get current memory limit threshold
     *
     * @return int Current memory threshold percentage
     */
    public function get_memory_limit(): int;

    /**
     * Set maximum execution time per batch (seconds)
     *
     * @param int $seconds Maximum execution time in seconds
     * @return bool True if time was set successfully
     */
    public function set_max_execution_time( int $seconds ): bool;

    /**
     * Get current maximum execution time
     *
     * @return int Maximum execution time in seconds
     */
    public function get_max_execution_time(): int;

    /**
     * Check if processing should stop due to resource limits
     *
     * @return bool True if should stop
     */
    public function should_stop_processing(): bool;

    /**
     * Get processing statistics
     *
     * @return array {
     *     Processing statistics.
     *
     *     @type int   $memory_usage     Current memory usage in bytes
     *     @type int   $memory_limit     PHP memory limit in bytes
     *     @type float $memory_percent   Memory usage percentage
     *     @type float $execution_time   Elapsed execution time in seconds
     *     @type int   $items_processed  Number of items processed so far
     *     @type float $items_per_second Processing rate
     * }
     */
    public function get_processing_stats(): array;

    /**
     * Calculate optimal batch size based on current memory usage
     *
     * @param int $default_size  Default batch size
     * @param int $total_items   Total items to process
     * @return int Calculated batch size
     */
    public function calculate_batch_size( int $default_size, int $total_items ): int;

    /**
     * Reset processing statistics
     *
     * @return bool True if statistics were reset
     */
    public function reset_stats(): bool;

    /**
     * Get estimated time remaining
     *
     * @param int $items_remaining Number of items remaining
     * @return float Estimated seconds remaining, or -1 if cannot estimate
     */
    public function estimate_time_remaining( int $items_remaining ): float;

    /**
     * Get memory usage information
     *
     * @return array {
     *     Memory usage information.
     *
     *     @type int   $current    Current memory usage in bytes
     *     @type int   $peak       Peak memory usage in bytes
     *     @type int   $limit      PHP memory limit in bytes
     *     @type float $percent    Current usage as percentage of limit
     *     @type int   $available  Available memory in bytes
     * }
     */
    public function get_memory_info(): array;
}
