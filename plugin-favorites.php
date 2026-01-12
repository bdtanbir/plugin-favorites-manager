<?php
/**
 * Plugin Name: Plugin Favorites Manager
 * Plugin URI: https://github.com/bdtanbir/plugin-favorites-manager
 * Description: Mark plugins as favorites for quick access with a custom filter tab
 * Version: 1.0.0
 * Author: Tanbir Ahmod
 * Author URI: https://tanbirahmod.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: plugin-favorites
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

const PLUGIN_FAVORITES_VERSION = '1.0.0';

class Plugin_Favorites_Manager {
    
    private $user_meta_key = 'favorite_plugins';
    
    public function __construct() {
        // Add favorite toggle to plugin rows
        add_filter('plugin_action_links', array($this, 'add_favorite_link'), 10, 4);
        
        // Add favorites filter tab
        add_filter('views_plugins', array($this, 'add_favorites_view'));
        
        // Filter plugins list when favorites tab is active
        add_filter('all_plugins', array($this, 'filter_favorite_plugins'));
        
        // Handle AJAX toggle favorite
        add_action('wp_ajax_toggle_plugin_favorite', array($this, 'ajax_toggle_favorite'));
      
        // Set favorites as default view if user has favorites
        add_action('admin_init', array($this, 'set_default_favorites_view'));
    }

    /**
     * Set favorites as default view if user has favorites and no specific view is selected
     */
    public function set_default_favorites_view() {
        global $pagenow;
        
        // Only on plugins page
        if ($pagenow !== 'plugins.php') {
            return;
        }
        
        // Check if user has favorites
        $favorites = $this->get_favorites();
        if (empty($favorites)) {
            return;
        }
        
        // If no plugin_status is set, redirect to favorites view
        if (!isset($_GET['plugin_status'])) {
            wp_redirect(add_query_arg('plugin_status', 'favorites', admin_url('plugins.php')));
            exit;
        }
    }
    
    /**
     * Get user's favorite plugins
     */
    private function get_favorites() {
        $favorites = get_user_meta(get_current_user_id(), $this->user_meta_key, true);
        return is_array($favorites) ? $favorites : array();
    }
    
    /**
     * Check if a plugin is favorited
     */
    private function is_favorite($plugin_file) {
        $favorites = $this->get_favorites();
        return in_array($plugin_file, $favorites);
    }
    
    /**
     * Add favorite toggle link to plugin action links
     */
    public function add_favorite_link($actions, $plugin_file, $plugin_data, $context) {
        $is_favorite = $this->is_favorite($plugin_file);
        $star_icon = $is_favorite ? '⭐' : '☆';
        $class = $is_favorite ? 'is-favorite' : '';
        
        $favorite_link = sprintf(
            '<a href="#" class="plugin-favorite-link %s" data-plugin="%s" title="%s">%s</a>',
            esc_attr($class),
            esc_attr($plugin_file),
            $is_favorite
                ? esc_attr__('Remove from favorites', 'plugin-favorites')
                : esc_attr__('Add to favorites', 'plugin-favorites'),
            $star_icon
        );
        
        // Add at the beginning of actions
        $actions = array_merge(array('favorite' => $favorite_link), $actions);
        
        return $actions;
    }
    
    /**
     * Add Favorites view/tab to the plugins page
     */
    public function add_favorites_view($views) {
        $favorites = $this->get_favorites();
        $count = count($favorites);
        
        $current_class = '';
        if (isset($_GET['plugin_status']) && $_GET['plugin_status'] === 'favorites') {
            $current_class = 'current';
        }
        
        $favorites_url = add_query_arg(array(
            'plugin_status' => 'favorites'
        ), admin_url('plugins.php'));
        
        $views['favorites'] = sprintf(
            '<a href="%s" class="view-favorites %s">%s <span class="count">(%d)</span></a>',
            esc_url($favorites_url),
            $current_class,
            __('Favorites', 'plugin-favorites'),
            $count
        );
        
        return $views;
    }
    
    /**
     * Filter plugins list to show only favorites when tab is active
     */
    public function filter_favorite_plugins($plugins) {
        // Only filter when on favorites tab
        if (!isset($_GET['plugin_status']) || $_GET['plugin_status'] !== 'favorites') {
            return $plugins;
        }
        
        $favorites = $this->get_favorites();
        
        if (empty($favorites)) {
            return array();
        }
        
        // Filter to only show favorite plugins
        $filtered_plugins = array();
        foreach ($plugins as $plugin_file => $plugin_data) {
            if (in_array($plugin_file, $favorites)) {
                $filtered_plugins[$plugin_file] = $plugin_data;
            }
        }
        
        return $filtered_plugins;
    }
    
    /**
     * Handle AJAX request to toggle favorite status
     */
    public function ajax_toggle_favorite() {
        // Verify nonce
        check_ajax_referer('plugin_favorites_nonce', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'plugin-favorites')));
        }
        
        // Get and sanitize plugin file
        $plugin_file = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';
        
        if (empty($plugin_file)) {
            wp_send_json_error(array('message' => __('Invalid plugin', 'plugin-favorites')));
        }
        
        // Get current favorites
        $favorites = $this->get_favorites();
        
        // Toggle favorite status
        $is_favorite = in_array($plugin_file, $favorites);
        
        if ($is_favorite) {
            // Remove from favorites
            $favorites = array_diff($favorites, array($plugin_file));
            $new_status = false;
        } else {
            // Add to favorites
            $favorites[] = $plugin_file;
            $new_status = true;
        }
        
        // Update user meta
        update_user_meta(get_current_user_id(), $this->user_meta_key, array_values($favorites));
        
        wp_send_json_success(array(
            'is_favorite' => $new_status,
            'count' => count($favorites)
        ));
    }
}

// Initialize the plugin
add_action('plugins_loaded', function () {
    require_once plugin_dir_path(__FILE__) . 'includes/class-assets.php';

    new Plugin_Favorites_Manager();
    Plugin_Favorites_Assets::init();
});
