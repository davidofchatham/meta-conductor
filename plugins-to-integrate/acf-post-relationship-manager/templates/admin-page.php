<?php
/**
 * Admin page template for ACF Post Relationship Manager
 * 
 * @package ACF_Post_Relationship_Manager
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$admin = BWS_ACF_Relationship_Admin::get_instance();
$config = BWS_ACF_Relationship_Config::get_instance();
$stats = $admin->get_admin_stats();
?>

<div class="wrap">
    <h1><?php esc_html_e('ACF Post Relationship Manager', 'acf-post-relationship-manager'); ?></h1>
    
    <div class="notice notice-info">
        <p><?php esc_html_e('This tool manages parent/child relationships between posts based on ACF field values.', 'acf-post-relationship-manager'); ?></p>
    </div>

    <?php if (empty($stats)): ?>
        <div class="notice notice-warning">
            <p><?php esc_html_e('No monitored post types found. Please check your configuration.', 'acf-post-relationship-manager'); ?></p>
        </div>
    <?php else: ?>
        
        <!-- Statistics Section -->
        <div class="card">
            <h2><?php esc_html_e('Relationship Statistics', 'acf-post-relationship-manager'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Post Type', 'acf-post-relationship-manager'); ?></th>
                        <th><?php esc_html_e('Total Posts', 'acf-post-relationship-manager'); ?></th>
                        <th><?php esc_html_e('With Parents', 'acf-post-relationship-manager'); ?></th>
                        <th><?php esc_html_e('With Children', 'acf-post-relationship-manager'); ?></th>
                        <th><?php esc_html_e('Orphaned', 'acf-post-relationship-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats as $post_type => $stat): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($stat['post_type_object']->labels->name); ?></strong>
                                <br>
                                <code><?php echo esc_html($post_type); ?></code>
                            </td>
                            <td><?php echo absint($stat['total_posts']); ?></td>
                            <td><?php echo absint($stat['posts_with_parents']); ?></td>
                            <td><?php echo absint($stat['posts_with_children']); ?></td>
                            <td><?php echo absint($stat['orphaned_posts']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Configuration Section -->
        <div class="card">
            <h2><?php esc_html_e('Current Configuration', 'acf-post-relationship-manager'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Post Type', 'acf-post-relationship-manager'); ?></th>
                        <th><?php esc_html_e('Parent Field', 'acf-post-relationship-manager'); ?></th>
                        <th><?php esc_html_e('Children Field', 'acf-post-relationship-manager'); ?></th>
                        <th><?php esc_html_e('Status', 'acf-post-relationship-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $configs = $config->get_all_configs();
                    foreach ($configs as $key => $cfg): 
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($cfg['post_type']); ?></strong>
                                <br>
                                <small class="description"><?php echo esc_html($key); ?></small>
                            </td>
                            <td>
                                <?php if (!empty($cfg['parent_field'])): ?>
                                    <code><?php echo esc_html($cfg['parent_field']); ?></code>
                                <?php else: ?>
                                    <span class="description"><?php esc_html_e('Not set', 'acf-post-relationship-manager'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($cfg['children_field'])): ?>
                                    <code><?php echo esc_html($cfg['children_field']); ?></code>
                                <?php else: ?>
                                    <span class="description"><?php esc_html_e('Not set', 'acf-post-relationship-manager'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($cfg['enabled'])): ?>
                                    <span class="status-enabled" style="color: green;">●</span>
                                    <?php esc_html_e('Enabled', 'acf-post-relationship-manager'); ?>
                                <?php else: ?>
                                    <span class="status-disabled" style="color: red;">●</span>
                                    <?php esc_html_e('Disabled', 'acf-post-relationship-manager'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Bulk Processing Section -->
        <div class="card">
            <h2><?php esc_html_e('Bulk Processing', 'acf-post-relationship-manager'); ?></h2>
            <p><?php esc_html_e('Process all posts at once to update their relationships based on current ACF field values. This is useful for initial setup or after configuration changes.', 'acf-post-relationship-manager'); ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field('bulk_process_relationships'); ?>
                <p>
                    <input type="submit" name="bulk_process" class="button button-primary" 
                           value="<?php esc_attr_e('Process All Relationships', 'acf-post-relationship-manager'); ?>"
                           onclick="return confirm('<?php esc_attr_e('This will process all posts for monitored post types. Continue?', 'acf-post-relationship-manager'); ?>');">
                </p>
                <p class="description">
                    <?php esc_html_e('Warning: This may take some time for sites with many posts. Consider running during off-peak hours.', 'acf-post-relationship-manager'); ?>
                </p>
            </form>
        </div>

        <!-- Help Section -->
        <div class="card">
            <h2><?php esc_html_e('How It Works', 'acf-post-relationship-manager'); ?></h2>
            <ul>
                <li><strong><?php esc_html_e('Automatic Processing:', 'acf-post-relationship-manager'); ?></strong> <?php esc_html_e('Relationships are updated automatically when posts are saved.', 'acf-post-relationship-manager'); ?></li>
                <li><strong><?php esc_html_e('Parent Relationships:', 'acf-post-relationship-manager'); ?></strong> <?php esc_html_e('If a post has a value in its parent field, it becomes a child of that post.', 'acf-post-relationship-manager'); ?></li>
                <li><strong><?php esc_html_e('Children Relationships:', 'acf-post-relationship-manager'); ?></strong> <?php esc_html_e('If a post has values in its children field, those posts become its children.', 'acf-post-relationship-manager'); ?></li>
                <li><strong><?php esc_html_e('Circular Prevention:', 'acf-post-relationship-manager'); ?></strong> <?php esc_html_e('The system prevents circular references (A→B→A scenarios).', 'acf-post-relationship-manager'); ?></li>
            </ul>
            
            <h3><?php esc_html_e('Configuration', 'acf-post-relationship-manager'); ?></h3>
            <p><?php esc_html_e('To modify the configuration, use the filter hook in your theme or plugin:', 'acf-post-relationship-manager'); ?></p>
            <pre><code>add_filter('bws_post_relationship_config', function($configs) {
    $configs['my_config'] = array(
        'post_type' => 'my_post_type',
        'parent_field' => 'my_parent_field',
        'children_field' => 'my_children_field',
        'enabled' => true,
    );
    return $configs;
});</code></pre>
        </div>

    <?php endif; ?>
</div>

<style>
.card {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin: 20px 0;
    padding: 20px;
}

.card h2 {
    margin-top: 0;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.status-enabled,
.status-disabled {
    font-size: 16px;
    margin-right: 5px;
}

pre {
    background: #f1f1f1;
    padding: 15px;
    border-left: 4px solid #0073aa;
    overflow-x: auto;
}

code {
    background: #f1f1f1;
    padding: 2px 5px;
    border-radius: 3px;
}
</style>