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
    private $all_plugins = null;
    
    public function __construct() {
        // Add favorite toggle to plugin rows
        add_filter('plugin_action_links', array($this, 'add_favorite_link'), 10, 4);
        
        // Add favorites filter tab
        add_filter('views_plugins', array($this, 'add_favorites_view'));
        
        // Filter plugins list when favorites tab is active
        add_filter('all_plugins', array($this, 'filter_favorite_plugins'));
        
        // Handle AJAX toggle favorite
        add_action('wp_ajax_toggle_plugin_favorite', array($this, 'ajax_toggle_favorite'));
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Set favorites as default view if user has favorites
        add_action('admin_init', array($this, 'set_default_favorites_view'));
    }

    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'plugin-favorites',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
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
     * Enqueue admin JavaScript and CSS
     */
    public function enqueue_admin_assets($hook) {
        // Only load on plugins page
        if ($hook !== 'plugins.php') {
            return;
        }
        
        wp_enqueue_style(
            'plugin-favorites',
            plugin_dir_url(__FILE__) . 'assets/css/plugin-favorites.css',
            array(),
            PLUGIN_FAVORITES_VERSION
        );


        wp_enqueue_script(
            'plugin-favorites',
            plugin_dir_url(__FILE__) . 'assets/js/plugin-favorites.js',
            array('jquery'),
            PLUGIN_FAVORITES_VERSION,
            true
        );
        
        wp_localize_script('plugin-favorites', 'pluginFavorites', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('plugin_favorites_nonce')
        ));
        
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
            $is_favorite ? esc_attr__('Remove from favorites', 'plugin-favorites') : esc_attr__('Add to favorites', 'plugin-favorites'),
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

        // Fix counts for other views when on favorites tab
        if (isset($_GET['plugin_status']) && $_GET['plugin_status'] === 'favorites' && $this->all_plugins !== null) {
            $views = $this->recalculate_view_counts($views);
        }

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
     * Recalculate view counts based on all plugins (not filtered)
     */
    private function recalculate_view_counts($views) {
        $all_plugins = $this->all_plugins;
        $active_plugins = get_option('active_plugins', array());

        // Count totals
        $all_count = count($all_plugins);
        $active_count = 0;
        $inactive_count = 0;
        $auto_updates_disabled_count = 0;

        // Get auto-update settings
        $auto_updates = get_site_option('auto_update_plugins', array());

        foreach ($all_plugins as $plugin_file => $plugin_data) {
            if (in_array($plugin_file, $active_plugins)) {
                $active_count++;
            } else {
                $inactive_count++;
            }
            // Count plugins with auto-updates disabled (not in the auto-update list)
            if (!in_array($plugin_file, $auto_updates)) {
                $auto_updates_disabled_count++;
            }
        }

        // Update the counts in existing views using regex to replace the count numbers
        foreach ($views as $key => &$view) {
            switch ($key) {
                case 'all':
                    $view = preg_replace('/\(\d+\)/', '(' . $all_count . ')', $view);
                    break;
                case 'active':
                    $view = preg_replace('/\(\d+\)/', '(' . $active_count . ')', $view);
                    break;
                case 'inactive':
                    $view = preg_replace('/\(\d+\)/', '(' . $inactive_count . ')', $view);
                    break;
                case 'auto-update-disabled':
                    $view = preg_replace('/\(\d+\)/', '(' . $auto_updates_disabled_count . ')', $view);
                    break;
            }
        }

        // Add inactive view if it was removed (WordPress removes views with 0 count)
        if (!isset($views['inactive']) && $inactive_count > 0) {
            $inactive_url = admin_url('plugins.php?plugin_status=inactive');
            $inactive_view = sprintf(
                '<a href="%s">%s <span class="count">(%d)</span></a>',
                esc_url($inactive_url),
                __('Inactive', 'plugin-favorites'),
                $inactive_count
            );
            // Insert inactive after active to maintain proper order
            $ordered_views = array();
            foreach ($views as $key => $view) {
                $ordered_views[$key] = $view;
                if ($key === 'active') {
                    $ordered_views['inactive'] = $inactive_view;
                }
            }
            $views = $ordered_views;
        }

        return $views;
    }
    
    /**
     * Filter plugins list to show only favorites when tab is active
     */
    public function filter_favorite_plugins($plugins) {
        // Store the original plugins list for correct count calculations
        if ($this->all_plugins === null) {
            $this->all_plugins = $plugins;
        }

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
new Plugin_Favorites_Manager();
