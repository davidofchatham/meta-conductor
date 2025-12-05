<?php
/**
 * Entity Abstraction Layer
 *
 * Provides unified interface for posts, terms, and future entity types
 *
 * @package BWS_Meta_Manager
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BWS_Entity {

    /**
     * Entity type (post, term, user, comment)
     *
     * @var string
     */
    protected $entity_type;

    /**
     * Entity ID
     *
     * @var int
     */
    protected $entity_id;

    /**
     * Loaded entity object
     *
     * @var WP_Post|WP_Term|WP_User|WP_Comment|null
     */
    protected $entity_object;

    /**
     * Constructor
     *
     * @param string $entity_type Entity type (post, term, user, comment)
     * @param int $entity_id Entity ID
     */
    public function __construct($entity_type, $entity_id) {
        $this->entity_type = $entity_type;
        $this->entity_id = (int) $entity_id;
        $this->load_entity();
    }

    /**
     * Load entity object from WordPress
     */
    protected function load_entity() {
        switch ($this->entity_type) {
            case 'post':
                $this->entity_object = get_post($this->entity_id);
                break;

            case 'term':
                $this->entity_object = get_term($this->entity_id);
                break;

            case 'user':
                $this->entity_object = get_user_by('id', $this->entity_id);
                break;

            case 'comment':
                $this->entity_object = get_comment($this->entity_id);
                break;
        }
    }

    /**
     * Get entity meta value (unified for posts, terms, users, comments)
     *
     * @param string $meta_key Meta key to retrieve
     * @param bool $single Whether to return single value
     * @return mixed Meta value
     */
    public function get_meta($meta_key, $single = true) {
        switch ($this->entity_type) {
            case 'post':
                return get_post_meta($this->entity_id, $meta_key, $single);

            case 'term':
                return get_term_meta($this->entity_id, $meta_key, $single);

            case 'user':
                return get_user_meta($this->entity_id, $meta_key, $single);

            case 'comment':
                return get_comment_meta($this->entity_id, $meta_key, $single);
        }

        return null;
    }

    /**
     * Set entity meta value
     *
     * @param string $meta_key Meta key
     * @param mixed $meta_value Meta value
     * @return bool Success
     */
    public function set_meta($meta_key, $meta_value) {
        switch ($this->entity_type) {
            case 'post':
                return update_post_meta($this->entity_id, $meta_key, $meta_value);

            case 'term':
                return update_term_meta($this->entity_id, $meta_key, $meta_value);

            case 'user':
                return update_user_meta($this->entity_id, $meta_key, $meta_value);

            case 'comment':
                return update_comment_meta($this->entity_id, $meta_key, $meta_value);
        }

        return false;
    }

    /**
     * Delete entity meta
     *
     * @param string $meta_key Meta key to delete
     * @return bool Success
     */
    public function delete_meta($meta_key) {
        switch ($this->entity_type) {
            case 'post':
                return delete_post_meta($this->entity_id, $meta_key);

            case 'term':
                return delete_term_meta($this->entity_id, $meta_key);

            case 'user':
                return delete_user_meta($this->entity_id, $meta_key);

            case 'comment':
                return delete_comment_meta($this->entity_id, $meta_key);
        }

        return false;
    }

    /**
     * Get entity terms (for posts) or parent/children (for terms)
     *
     * @param string|null $taxonomy Taxonomy name (for posts)
     * @return array Terms or hierarchy info
     */
    public function get_terms($taxonomy = null) {
        if ($this->entity_type === 'post') {
            return wp_get_post_terms($this->entity_id, $taxonomy ?: get_taxonomies());
        } elseif ($this->entity_type === 'term') {
            // Return term hierarchy info
            return [
                'parent' => $this->entity_object->parent,
                'children' => get_term_children($this->entity_id, $this->entity_object->taxonomy),
                'ancestors' => get_ancestors($this->entity_id, $this->entity_object->taxonomy, 'taxonomy')
            ];
        }

        return [];
    }

    /**
     * Apply terms to entity (posts only for now)
     *
     * @param array|string $terms Term IDs or slugs
     * @param string $taxonomy Taxonomy name
     * @param bool $append Whether to append or replace
     * @return array|WP_Error|false Term taxonomy IDs on success
     */
    public function apply_terms($terms, $taxonomy, $append = true) {
        if ($this->entity_type === 'post') {
            return wp_set_post_terms($this->entity_id, $terms, $taxonomy, $append);
        }

        return false; // Terms can't have terms (yet)
    }

    /**
     * Remove terms from entity
     *
     * @param array|string $terms Term IDs or slugs
     * @param string $taxonomy Taxonomy name
     * @return bool|WP_Error Success or error
     */
    public function remove_terms($terms, $taxonomy) {
        if ($this->entity_type === 'post') {
            return wp_remove_object_terms($this->entity_id, $terms, $taxonomy);
        }

        return false;
    }

    /**
     * Get ACF field value (works for both posts and terms)
     *
     * @param string $field_name ACF field name
     * @return mixed Field value
     */
    public function get_acf_field($field_name) {
        if (!function_exists('get_field')) {
            return null;
        }

        return get_field($field_name, $this->get_acf_identifier());
    }

    /**
     * Set ACF field value
     *
     * @param string $field_name ACF field name
     * @param mixed $value Field value
     * @return bool Success
     */
    public function set_acf_field($field_name, $value) {
        if (!function_exists('update_field')) {
            return false;
        }

        return update_field($field_name, $value, $this->get_acf_identifier());
    }

    /**
     * Get ACF identifier for this entity
     *
     * ACF uses different formats for different entities:
     * - Posts: just the ID
     * - Terms: 'taxonomy_term_id' format
     * - Users: 'user_id' format
     * - Options: 'option' string
     *
     * @return string|int ACF identifier
     */
    protected function get_acf_identifier() {
        switch ($this->entity_type) {
            case 'post':
                return $this->entity_id;

            case 'term':
                return $this->entity_object->taxonomy . '_' . $this->entity_id;

            case 'user':
                return 'user_' . $this->entity_id;

            case 'comment':
                return 'comment_' . $this->entity_id;
        }

        return $this->entity_id;
    }

    /**
     * Get entity type
     *
     * @return string Entity type
     */
    public function get_type() {
        return $this->entity_type;
    }

    /**
     * Get entity ID
     *
     * @return int Entity ID
     */
    public function get_id() {
        return $this->entity_id;
    }

    /**
     * Get raw entity object
     *
     * @return WP_Post|WP_Term|WP_User|WP_Comment|null Entity object
     */
    public function get_object() {
        return $this->entity_object;
    }

    /**
     * Check if entity exists
     *
     * @return bool Whether entity exists
     */
    public function exists() {
        return $this->entity_object !== null && !is_wp_error($this->entity_object);
    }

    /**
     * Get entity title/name
     *
     * @return string Entity title
     */
    public function get_title() {
        if (!$this->exists()) {
            return '';
        }

        switch ($this->entity_type) {
            case 'post':
                return get_the_title($this->entity_id);

            case 'term':
                return $this->entity_object->name;

            case 'user':
                return $this->entity_object->display_name;

            case 'comment':
                return sprintf(__('Comment #%d', 'bws-meta-manager'), $this->entity_id);
        }

        return '';
    }

    /**
     * Get entity edit URL
     *
     * @return string Edit URL
     */
    public function get_edit_url() {
        switch ($this->entity_type) {
            case 'post':
                return get_edit_post_link($this->entity_id);

            case 'term':
                return get_edit_term_link($this->entity_id, $this->entity_object->taxonomy);

            case 'user':
                return get_edit_user_link($this->entity_id);

            case 'comment':
                return get_edit_comment_link($this->entity_id);
        }

        return '';
    }
}
