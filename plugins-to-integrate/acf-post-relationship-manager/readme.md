# ACF Post Relationship Manager

A WordPress plugin that automatically manages parent/child post relationships based on Advanced Custom Fields (ACF) post object or relationship field values.

## Features

- ✅ **Automatic Relationship Management**: Updates post parent/child relationships when posts are saved
- ✅ **Multi Post Type Support**: Configure multiple post types with different field mappings
- ✅ **Circular Reference Prevention**: Prevents infinite loops and invalid relationship hierarchies
- ✅ **Admin Interface**: View relationships in post list columns and dedicated admin page
- ✅ **Bulk Processing**: Process all posts at once for initial setup or configuration changes
- ✅ **Security Focused**: Proper validation, sanitization, and capability checks
- ✅ **Performance Optimized**: Only updates relationships when changes are detected

## Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 8.1 or higher
- **ACF Pro**: Required for post object/relationship fields
- **Post Types**: Custom post types with ACF fields configured

## Installation

1. **Download/Clone** this repository into your `wp-content/plugins/` directory
2. **Activate** the plugin through the WordPress admin
3. **Configure** your relationship mappings (see Configuration section)
4. **Ensure ACF Pro** is installed and your fields are set up correctly

## Directory Structure

```
acf-post-relationship-manager/
├── acf-post-relationship-manager.php    # Main plugin file
├── inc/
│   ├── class-config.php                 # Configuration management
│   ├── class-core.php                   # Core relationship processing
│   └── class-admin.php                  # Admin interface
├── templates/
│   └── admin-page.php                   # Admin page template
├── assets/
│   └── admin.css                        # Admin styles
├── uninstall.php                        # Cleanup on uninstall
└── README.md                            # This file
```

## Configuration

### Basic Setup

The plugin uses a filterable configuration array. Add this to your theme's `functions.php` or a custom plugin:

```php
add_filter('bws_post_relationship_config', function($configs) {
    $configs['my_events'] = array(
        'post_type' => 'event',
        'parent_field' => 'parent_event',        // ACF field containing parent post ID
        'children_field' => 'sub_events',        // ACF field containing child post IDs
        'enabled' => true,
    );
    
    return $configs;
});
```

### Multiple Post Types

```php
add_filter('bws_post_relationship_config', function($configs) {
    // Events configuration
    $configs['events'] = array(
        'post_type' => 'event',
        'parent_field' => 'parent_event',
        'children_field' => 'sub_events',
        'enabled' => true,
    );
    
    // Products configuration
    $configs['products'] = array(
        'post_type' => 'product',
        'parent_field' => 'parent_product',
        'children_field' => 'related_products',
        'enabled' => true,
    );
    
    return $configs;
});
```

### ACF Field Setup

#### Parent Field (Post Object/Relationship)
- **Field Type**: Post Object or Relationship
- **Return Format**: Post ID
- **Allow Multiple**: No (for single parent) or Yes (uses first ID if multiple)

#### Children Field (Relationship)
- **Field Type**: Post Object or Relationship  
- **Return Format**: Post ID
- **Allow Multiple**: Yes

## How It Works

### Automatic Processing

When a post is saved, the plugin:

1. **Checks Configuration**: Verifies if the post type is monitored
2. **Processes Parent**: If `parent_field` has a value, sets that post as the parent
3. **Processes Children**: If `children_field` has values, sets those posts as children
4. **Prevents Loops**: Validates against circular references
5. **Updates Only Changes**: Only modifies relationships when they actually change

### Example Scenario

Given this configuration:
```php
'post_type' => 'athletics_event',
'parent_field' => 'athletics_event_parent_event',
'children_field' => 'athletics_event_sub_events',
```

- **Event A** has `athletics_event_sub_events` = [Event B, Event C]
- **Event B** has `athletics_event_parent_event` = Event A

**Result**: Event A becomes parent of Events B and C

## Admin Features

### Relationship Columns

The plugin adds a "Relationships" column to post list tables showing:
- Parent post with edit link
- Child posts with edit links (limited to 5 for performance)
- "No relationships" indicator for orphaned posts

### Admin Dashboard

Navigate to **Tools → Post Relationships** to access:
- **Statistics**: View relationship counts by post type
- **Current Configuration**: See active field mappings
- **Bulk Processing**: Process all posts at once
- **Help Documentation**: Configuration examples and usage guide

### Bulk Actions

Post list tables include a "Process Relationships" bulk action for updating multiple posts at once.

## Security Features

- **Input Validation**: All post IDs sanitized with `absint()`
- **Capability Checks**: Admin functions require appropriate permissions
- **Nonce Verification**: CSRF protection on admin forms
- **Circular Prevention**: Validates relationship hierarchies
- **Post Existence**: Verifies posts exist before creating relationships

## Performance Considerations

- **Change Detection**: Only updates when relationships actually change
- **Hook Management**: Temporarily removes hooks to prevent infinite loops
- **Limited Display**: Admin columns show maximum 5 children
- **Efficient Queries**: Uses WordPress native functions for optimal performance

## Troubleshooting

### Relationships Not Updating?

1. **Verify ACF Pro** is active and licensed
2. **Check field names** match your configuration exactly
3. **Confirm return format** is set to "Post ID"
4. **Ensure posts exist** and are not in trash
5. **Check post statuses** (plugin processes published, private, and draft posts)

### Circular Reference Warnings?

The plugin prevents these automatically, but if you see issues:
- Avoid Event A → Event B → Event A relationships
- Check that parent/child fields don't conflict
- Review your field logic for potential loops

### Performance Issues?

- **Limit children** in relationship fields (recommend < 10 per post)
- **Use bulk processing** during off-peak hours
- **Monitor memory usage** on sites with thousands of posts

## Developer Hooks

### Filters

```php
// Modify configuration
add_filter('bws_post_relationship_config', $callback);
```

### Functions

```php
// Get configuration instance
$config = BWS_ACF_Relationship_Config::get_instance();

// Get core processing instance  
$core = BWS_ACF_Relationship_Core::get_instance();

// Manually process a post
$success = $core->manual_process_post_relationships($post_id);

// Get relationship statistics
$stats = $core->get_relationship_stats('post_type');
```

## Changelog

### 1.0.0
- Initial release
- Basic parent/child relationship management
- Admin interface and bulk processing
- Security and performance optimizations

## Support

For issues, feature requests, or contributions:

1. **Check existing issues** in the repository
2. **Review troubleshooting** section above
3. **Create detailed bug reports** with configuration and ACF field setup
4. **Include WordPress/PHP version** information

## License

GPL v2 or later - see LICENSE file for details.

## Credits

Developed following WordPress coding standards and accessibility best practices. Compatible with GeneratePress Pro, GenerateBlocks Pro, and ACF Pro.