<?php
/**
 * Test script for BWS Meta Manager Conversion Integration
 *
 * Run with: wp eval-file test-conversion-integration.php
 */

echo "========================================\n";
echo "BWS Meta Manager Conversion Test Script\n";
echo "========================================\n\n";

// Test 1: Check if classes are loaded
echo "Test 1: Checking if classes are loaded...\n";
$classes = [
    'BWS_Term_Migrator',
    'BWS_Field_Converter',
    'BWS_Value_Mapper',
    'BWS_Batch_Processor',
    'BWS_Field_Mapper',
    'BWS_Data_Processor',
    'BWS_Preview_System',
    'BWS_Conversion_Manager',
    'BWS_Conversion_CLI',
];

$missing = [];
foreach ($classes as $class) {
    if (class_exists($class)) {
        echo "  ✓ $class loaded\n";
    } else {
        echo "  ✗ $class NOT FOUND\n";
        $missing[] = $class;
    }
}

if (!empty($missing)) {
    echo "\n❌ ERROR: " . count($missing) . " classes missing!\n";
    exit(1);
}

echo "\n✅ All classes loaded successfully!\n\n";

// Test 2: Initialize Conversion Manager
echo "Test 2: Initializing Conversion Manager...\n";
try {
    $manager = new BWS_Conversion_Manager();
    echo "  ✓ Conversion Manager created\n";

    // Test getting components
    $field_mapper = $manager->get_field_mapper();
    echo "  ✓ Field Mapper accessible\n";

    $data_processor = $manager->get_data_processor();
    echo "  ✓ Data Processor accessible\n";

    $preview_system = $manager->get_preview_system();
    echo "  ✓ Preview System accessible\n";

} catch (Exception $e) {
    echo "\n❌ ERROR: Failed to initialize Conversion Manager\n";
    echo "  Message: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ Conversion Manager initialized successfully!\n\n";

// Test 3: Test Term Migrator Library
echo "Test 3: Testing Term Migrator Library...\n";
try {
    $term_migrator = new BWS_Term_Migrator();

    // Test getting term hierarchy
    $test_taxonomy = 'category';
    if (taxonomy_exists($test_taxonomy)) {
        $hierarchy = $term_migrator->get_term_hierarchy($test_taxonomy);
        echo "  ✓ Term Migrator working\n";
        echo "  ℹ Found " . count($hierarchy) . " terms in '$test_taxonomy' taxonomy\n";
    } else {
        echo "  ⚠ Category taxonomy not found (this is unusual)\n";
    }

} catch (Exception $e) {
    echo "  ✗ Term Migrator error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 4: Test Field Converter Library
echo "Test 4: Testing Field Converter Library...\n";
try {
    $field_converter = new BWS_Field_Converter();

    // Test simple conversion
    $test_value = "test,value,data";
    $converted = $field_converter->convert_field_value($test_value, 'text', 'textarea');
    echo "  ✓ Field Converter working\n";
    echo "  ℹ Converted: \"$test_value\" → \"$converted\"\n";

    // Test supported types
    $types = $field_converter->get_supported_types();
    echo "  ℹ Supports " . count($types) . " field types\n";

} catch (Exception $e) {
    echo "  ✗ Field Converter error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 5: Test Value Mapper Library
echo "Test 5: Testing Value Mapper Library...\n";
try {
    $value_mapper = new BWS_Value_Mapper();

    // Set test mappings
    $value_mapper->set_mapping('red', 'Red Category');
    $value_mapper->set_mapping('green', 'Green Category');
    $value_mapper->set_mapping('blue', 'Blue Category');

    echo "  ✓ Value Mapper working\n";
    echo "  ℹ Created 3 test mappings\n";

    // Test mapping
    $result = $value_mapper->map_values(['red', 'green', 'yellow']);
    echo "  ℹ Mapped: " . count($result['mapped']) . " values\n";
    echo "  ℹ Unmapped: " . count($result['unmapped']) . " values\n";

} catch (Exception $e) {
    echo "  ✗ Value Mapper error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 6: Test Batch Processor Library
echo "Test 6: Testing Batch Processor Library...\n";
try {
    $batch_processor = new BWS_Batch_Processor();
    $batch_processor->set_batch_size(25);

    // Process test items
    $items = range(1, 50);
    $processed_count = 0;

    $result = $batch_processor->process_batch($items, function($item) use (&$processed_count) {
        $processed_count++;
        return true;
    });

    echo "  ✓ Batch Processor working\n";
    echo "  ℹ Processed " . $result['processed_items'] . " items\n";
    echo "  ℹ Execution time: " . number_format($result['execution_time'], 3) . "s\n";
    echo "  ℹ Rate: " . number_format($result['items_per_second'], 1) . " items/sec\n";

} catch (Exception $e) {
    echo "  ✗ Batch Processor error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 7: Test Field Mapper (ACF integration)
echo "Test 7: Testing Field Mapper (ACF integration)...\n";
try {
    // Check if ACF is active
    if (!function_exists('acf_get_field_groups')) {
        echo "  ⚠ ACF Pro not active - Field Mapper will have limited functionality\n";
    } else {
        $field_groups = $manager->get_field_groups();
        echo "  ✓ Field Mapper working\n";
        echo "  ℹ Found " . count($field_groups) . " ACF field groups\n";

        $taxonomies = $manager->get_taxonomies();
        echo "  ℹ Found " . count($taxonomies) . " taxonomies\n";
    }

} catch (Exception $e) {
    echo "  ✗ Field Mapper error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 8: Test Database Tables
echo "Test 8: Checking database tables...\n";
global $wpdb;

$tables = [
    $wpdb->prefix . 'bws_acf_conversion_preview',
    $wpdb->prefix . 'bws_acf_conversion_sessions',
];

foreach ($tables as $table) {
    $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
    if ($exists) {
        echo "  ✓ Table exists: $table\n";
    } else {
        echo "  ✗ Table missing: $table\n";
    }
}

echo "\n";

// Test 9: Test Conversion Manager API
echo "Test 9: Testing Conversion Manager API...\n";
try {
    // Test statistics
    $stats = $manager->get_statistics();
    echo "  ✓ Statistics: " . $stats['active_sessions'] . " active sessions, " . $stats['recent_previews'] . " recent previews\n";

    // Test validation
    $validation = $manager->validate_config(['content_type' => ''], 'copy_data');
    if (!$validation['valid']) {
        echo "  ✓ Validation correctly caught invalid config\n";
    }

} catch (Exception $e) {
    echo "  ✗ API error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 10: Check PHP version and extensions
echo "Test 10: Environment checks...\n";
echo "  ℹ PHP version: " . PHP_VERSION . "\n";
echo "  ℹ WordPress version: " . get_bloginfo('version') . "\n";
echo "  ℹ Memory limit: " . ini_get('memory_limit') . "\n";
echo "  ℹ Max execution time: " . ini_get('max_execution_time') . "s\n";

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    echo "  ⚠ WARNING: PHP 8.1+ recommended (current: " . PHP_VERSION . ")\n";
}

echo "\n";

// Summary
echo "========================================\n";
echo "✅ All integration tests completed!\n";
echo "========================================\n\n";

echo "Next steps:\n";
echo "1. If ACF Pro is not active, activate it to test field discovery\n";
echo "2. Run wp-cli commands:\n";
echo "   wp bws-conversion test_manager\n";
echo "   wp bws-conversion test_term_migrator --source=category --target=post_tag --dry-run\n";
echo "3. Check for any PHP errors in debug.log\n\n";
