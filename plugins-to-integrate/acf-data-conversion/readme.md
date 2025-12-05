# ACF Data Conversion Tool

A professional WordPress plugin for converting Advanced Custom Fields data between different field types, mapping field values to taxonomy terms, and performing complex option mappings with enterprise-level safety features.

## Features

### Core Conversion Types
- **Field to Field**: Move data between ACF fields with automatic type conversion
- **Field to Taxonomy**: Convert field values to taxonomy terms and assign to posts
- **Option Mapping**: Map field option values to new field options or taxonomy terms

### Safety & Performance
- **Dry Run Preview**: Test conversions on sample data before applying changes
- **Batch Processing**: Memory-aware processing for thousands of posts
- **Transaction Safety**: Comprehensive error handling with rollback capabilities
- **Administrator Only**: Restricted access with capability checking
- **AJAX Progress**: Real-time progress tracking with detailed logging

### Technical Excellence
- **PHP 8.1+ Compatible**: Modern PHP features with WordPress backward compatibility
- **WCAG 2.2 AA Compliant**: Full accessibility support
- **Security First**: Nonce verification, input sanitization, and output escaping
- **Performance Optimized**: Adaptive batch sizing and memory management

## Requirements

- WordPress 6.0 or higher
- PHP 8.1 or higher
- Advanced Custom Fields Pro 6.0 or higher
- Administrator privileges

## Installation

### Manual Installation

1. **Create Plugin Directory**
   ```
   wp-content/plugins/acf-data-conversion-tool/
   ```

2. **File Structure**
   ```
   acf-data-conversion-tool/
   ├── acf-data-conversion-tool.php (Main plugin file)
   ├── includes/
   │   ├── class-field-mapper.php
   │   ├── class-data-processor.php
   │   ├── class-preview-system.php
   │   └── class-admin-interface.php
   ├── assets/
   │   ├── css/
   │   │   └── admin.css
   │   └── js/
   │       └── admin.js
   └── languages/
       └── (translation files)
   ```

3. **Place Files**
   - Copy the main plugin file to the root directory
   - Copy all class files to the `includes/` directory
   - Copy CSS file to `assets/css/admin.css`
   - Copy JavaScript file to `assets/js/admin.js`

4. **Activate Plugin**
   - Navigate to WordPress Admin → Plugins
   - Find "ACF Data Conversion Tool"
   - Click "Activate"

## Usage

### Accessing the Tool

1. Navigate to **Tools → ACF Data Conversion** in WordPress admin
2. Choose your conversion type from the available tabs

### Field to Field Conversion

**Purpose**: Move data from one ACF field to another with automatic type conversion.

**Steps**:
1. Select **Source Field** (field containing data to convert)
2. Select **Target Field** (field where converted data will be stored)
3. Configure **Post Filtering** (post types, status)
4. Adjust **Batch Size** if needed (default: 25)
5. Click **Generate Preview** to test conversion
6. Review preview results and click **Start Conversion**

**Supported Conversions**:
- Text ↔ Textarea ↔ Number
- Select → Radio → Checkbox
- Complex fields (repeater, group) → Text (serialized)
- And many more with automatic type handling

### Field to Taxonomy Conversion

**Purpose**: Convert field values to taxonomy terms and assign them to posts.

**Steps**:
1. Select **Source Field** (field containing values)
2. Select **Target Taxonomy** (where terms will be created)
3. Choose **Term Assignment** method:
   - Replace existing terms
   - Add to existing terms
4. Configure post filtering and batch size
5. Generate preview and review results
6. Start conversion

**Value Extraction**:
- **Text fields**: Split by commas, semicolons, or line breaks
- **Select/Radio**: Single value becomes term
- **Checkbox**: Multiple values become multiple terms
- **Complex fields**: Extract meaningful text values

### Option Mapping

**Purpose**: Map field option values to new field options or taxonomy terms.

**Steps**:
1. Select **Source Field** (must have options: select, checkbox, radio)
2. Choose **Target Type**: Another Field or Taxonomy Terms
3. Select target field/taxonomy
4. **Map Each Option**: Interface appears showing source options
5. Enter target values for each source option
6. Generate preview and start conversion

**Use Cases**:
- Restructuring option values
- Migrating to new field with different options  
- Converting option-based fields to taxonomies

## Configuration Options

### Post Filtering

**Post Types**:
- Select specific post types or "All Post Types"
- Multiple selection supported

**Post Status**:
- Published (default)
- Draft
- Private
- Pending
- Multiple selection supported

### Batch Processing

**Batch Size**: 
- Default: 25 posts per batch
- Range: 5-100 posts
- Lower values use less memory
- Automatically adjusted based on available memory

**Memory Management**:
- Monitors memory usage during processing
- Stops automatically if memory threshold exceeded
- Adaptive batch sizing for optimal performance

## Safety Features

### Dry Run Preview

**Purpose**: Test conversions safely before applying changes.

**Features**:
- Processes sample posts (default: 10)
- Shows before/after values
- Identifies potential issues
- No changes made to live data
- Detailed conversion notes

**Preview Results**:
- Conversion summary statistics
- Sample-by-sample results
- Error identification
- Validation warnings

### Validation System

**Field Compatibility**:
- Checks source and target field types
- Identifies risky conversions
- Warns about potential data loss
- Prevents invalid conversions

**Taxonomy Validation**:
- Verifies taxonomy exists
- Checks field compatibility
- Warns about complex mappings

### Error Handling

**Graceful Degradation**:
- Individual post failures don't stop batch
- Detailed error logging
- Automatic retry for temporary failures
- Transaction rollback on critical errors

## Technical Details

### Security Implementation

**Access Control**:
- Administrator capability required
- Nonce verification for all requests
- CSRF protection on forms

**Input Sanitization**:
- All user inputs sanitized
- SQL injection prevention
- XSS protection

**Output Escaping**:
- Context-specific escaping
- Safe HTML output
- JSON encoding for AJAX

### Performance Optimization

**Database Efficiency**:
- Prepared statements
- Optimized queries
- Batch processing
- Memory monitoring

**Caching Strategy**:
- Field data caching (1 hour)
- Taxonomy data caching
- Transient storage
- Object cache support

### Accessibility Compliance

**WCAG 2.2 AA Standards**:
- Keyboard navigation support
- Screen reader compatibility
- High contrast mode support
- Focus management
- ARIA labels and descriptions

## Troubleshooting

### Common Issues

**Memory Errors**:
- Reduce batch size to 10-15
- Check hosting memory limits
- Process during low-traffic periods

**Field Not Found**:
- Refresh field cache (deactivate/reactivate plugin)
- Verify ACF Pro is active
- Check field group location rules

**Conversion Failures**:
- Review preview results first
- Check field type compatibility
- Verify source data format

### Debug Information

**Enable Debugging**:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

**Check Logs**:
- WordPress debug log
- Plugin detailed logging
- Browser console for AJAX errors

### Support

**Before Requesting Support**:
1. Test with preview first
2. Check browser console for errors
3. Verify WordPress/PHP/ACF versions
4. Test with default theme
5. Deactivate conflicting plugins

## Best Practices

### Before Converting

1. **Always backup your database**
2. **Test on staging site first**
3. **Generate and review preview**
4. **Start with small batch sizes**
5. **Monitor memory usage**

### During Conversion

1. **Don't navigate away from page**
2. **Monitor progress log**
3. **Let batches complete naturally**
4. **Have backup restoration plan ready**

### After Conversion

1. **Verify conversion results**
2. **Test site functionality**
3. **Clear any caches**
4. **Update field group settings if needed**
5. **Document changes made**

## Advanced Configuration

### Custom Post Queries

You can modify the post selection logic by hooking into the conversion process:

```php
add_filter('bws_acf_conversion_post_query_args', function($args, $config) {
    // Modify query arguments
    $args['date_query'] = [
        'after' => '2023-01-01'
    ];
    return $args;
}, 10, 2);
```

### Field Validation

Add custom validation for specific field types:

```php
add_filter('bws_acf_conversion_validate_field', function($validation, $source_field, $target_field) {
    // Add custom validation logic
    if ($source_field['type'] === 'custom_type') {
        $validation['warnings'][] = 'Custom validation message';
    }
    return $validation;
}, 10, 3);
```

### Performance Tuning

Adjust batch processing for your server:

```php
add_filter('bws_acf_conversion_batch_size', function($batch_size, $config) {
    // Increase for powerful servers
    return min($batch_size * 2, 50);
}, 10, 2);
```

## Version History

### Version 1.0.0
- Initial release
- Field to field conversion
- Field to taxonomy conversion
- Option mapping functionality
- Preview system
- Batch processing
- Security implementation
- Accessibility compliance

## License

GPL v2 or later

## Contributing

This tool follows WordPress coding standards and modern PHP practices. When contributing:

1. Follow PSR-12 coding standards
2. Use namespace prefix `bws_`
3. Include `function_exists` checks
4. Prioritize security and accessibility
5. Add comprehensive tests
6. Update documentation

---

**Note**: This tool performs irreversible data modifications. Always backup your database and test thoroughly before using in production environments.