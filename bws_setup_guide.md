# BWS Taxonomy Manager - Complete Setup Guide

## Directory Structure

Create the following directory structure in your `wp-content/plugins/` directory:

```
bws-taxonomy-manager/
├── bws-taxonomy-manager.php                 # Main plugin file
├── readme.txt                               # WordPress plugin readme
├── assets/
│   ├── css/
│   │   ├── admin.css                       # Admin interface styles
│   │   └── admin-columns.css               # Admin Columns integration styles
│   └── js/
│       ├── admin.js                        # Admin interface JavaScript
│       └── quick-edit.js                   # Quick Edit enhancements
├── includes/
│   ├── class-bws-taxonomy-manager.php      # Main plugin class
│   ├── class-bws-settings.php              # Settings management
│   ├── abstracts/
│   │   └── class-bws-handler-base.php      # Base handler abstract class
│   ├── handlers/
│   │   ├── class-bws-hierarchical-handler.php    # Hierarchical terms handler
│   │   ├── class-bws-propagation-handler.php     # Parent-child propagation handler
│   │   ├── class-bws-related-handler.php         # Related terms handler
│   │   └── class-bws-time-based-handler.php      # Time-based terms handler
│   └── integrations/
│       ├── class-bws-acf-integration.php         # ACF Pro integration
│       └── class-bws-admin-columns-integration.php # Admin Columns Pro integration
└── languages/
    └── bws-taxonomy-manager.pot            # Translation template
```

## Installation Instructions

### 1. File Setup

1. Create the main plugin directory: `wp-content/plugins/bws-taxonomy-manager/`
2. Copy all the provided PHP files to their respective directories
3. Create the assets directories and add the CSS/JS files
4. Ensure all file permissions are set correctly (644 for files, 755 for directories)

### 2. Plugin Activation

1. Go to WordPress Admin → Plugins
2. Find "BWS Taxonomy Manager" in the plugins list
3. Click "Activate"
4. Navigate to Settings → Taxonomy Manager to configure

### 3. Configuration Examples

Based on your specific use cases, here are the configuration examples:

#### Example 1: Page Term Inheritance (site-section taxonomy)
```
Rule Type: Propagation Rule
Post Type: page
Taxonomy: site-section
Conflict Handling: Replace existing terms
```

#### Example 2: Athletics Event Status Hierarchy
```
Rule Type: Hierarchical Rule
Taxonomy: athletics-event-status
Inheritance Depth: All ancestors
Post Types: (leave empty for all post types)
```

#### Example 3: Teams → Athletics Category
```
Rule Type: Related Terms Rule
Post Type: post
Trigger Type: Any term from taxonomy
Trigger Taxonomy: teams
Target Term: Athletics (from category taxonomy)
Bidirectional: No
```

#### Example 4: School Year Time-Based Application
```
Rule Type: Time-Based Rule
Post Type: post
Target Term: 2025-2026 (from school_year taxonomy)
Start Date: 2025-08-26
End Date: 2026-05-31
Filter Terms: Athletics (from category taxonomy)
```

## Required Dependencies

### Core Requirements
- **PHP 8.1+** (enforced by plugin)
- **WordPress 5.0+**
- **GeneratePress Pro** (your theme)
- **GenerateBlocks Pro** (your page builder)

### Optional Integrations
- **Advanced Custom Fields Pro** (for ACF taxonomy field support)
- **Admin Columns Pro** (for Quick Edit functionality)

## Security Features

The plugin includes several security measures:
- Nonce verification for all AJAX requests
- Capability checks (`manage_options` required)
- Input sanitization and validation
- SQL injection prevention through WordPress APIs
- XSS protection through proper escaping

## Performance Considerations

### Optimization Features
- Batch processing for existing posts (50 posts per batch)
- Efficient database queries using WordPress taxonomy APIs
- Caching of rule configurations
- Lazy loading of integrations (only when plugins are active)

### Recommended Settings
- Enable object caching if processing large numbers of posts
- Consider running manual processing during low-traffic periods
- Monitor database performance when using time-based rules on large sites

## Debugging and Troubleshooting

### Enable Debug Logging
Add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Debug messages will appear in `/wp-content/debug.log` with the prefix `[BWS Taxonomy Manager]`.

### Common Issues and Solutions

1. **Rules not applying**: Check that rules are enabled and post types match
2. **ACF fields not updating**: Ensure ACF Pro is active and fields are properly configured
3. **Performance issues**: Reduce batch size or enable object caching
4. **Conflicts with other plugins**: Check for conflicting taxonomy hooks

## API and Hooks

### Available Filters
```php
// Modify rule processing
add_filter('bws_taxonomy_manager_process_rule', $callback, 10, 3);

// Customize conflict handling
add_filter('bws_taxonomy_manager_conflict_handling', $callback, 10, 2);

// Modify batch processing size
add_filter('bws_taxonomy_manager_batch_size', $callback, 10, 2);
```

### Available Actions
```php
// Before rule processing
add_action('bws_taxonomy_manager_before_process', $callback, 10, 2);

// After rule processing
add_action('bws_taxonomy_manager_after_process', $callback, 10, 2);

// Custom cleanup
add_action('bws_taxonomy_manager_cleanup', $callback);
```

## Extending the Plugin

### Adding Custom Handlers

1. Create a new handler class extending `BWS_Handler_Base`
2. Implement required abstract methods
3. Register the handler in the main plugin class
4. Add configuration UI in the settings class

### Custom Rule Types

Follow the pattern established by existing handlers:
- Validation methods
- Processing logic
- Settings UI
- AJAX endpoints

## Testing Checklist

Before deploying to production:

- [ ] Test each rule type individually
- [ ] Verify ACF integration works correctly
- [ ] Test Admin Columns Pro Quick Edit functionality
- [ ] Confirm time-based rules activate/deactivate correctly
- [ ] Test batch processing with existing posts
- [ ] Verify security measures (nonces, capabilities)
- [ ] Check performance with large post counts
- [ ] Test conflict handling scenarios
- [ ] Validate settings form submission
- [ ] Confirm cleanup scheduled tasks work

## Support and Maintenance

### Regular Maintenance Tasks
1. Monitor the cleanup scheduled task
2. Review time-based rules before they expire
3. Check debug logs for any issues
4. Update rule configurations as site needs change

### Backup Considerations
Always backup your database before:
- Installing the plugin
- Running batch processing
- Making significant rule changes
- Updating the plugin

## Version History

### v1.0.0 (Initial Release)
- Hierarchical term inheritance
- Parent-child term propagation
- Related terms linking
- Time-based term application
- ACF Pro integration
- Admin Columns Pro integration
- Comprehensive admin interface
- Batch processing capabilities
- Security and performance optimizations

---

This comprehensive taxonomy management system provides all the functionality you requested with proper security, performance, and extensibility considerations. The modular architecture makes it easy to maintain and extend as your needs evolve.
