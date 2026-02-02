# Plugin Favorites Manager - Documentation

## Overview

Plugin Favorites Manager is a WordPress plugin that enhances the admin plugins page by allowing administrators to mark plugins as favorites for quick access. It creates a custom "Favorites" filter tab that displays only the user's favorited plugins.

## Features

- **Favorite Toggle**: Star icon (☆/⭐) on each plugin row to mark/unmark favorites
- **Favorites Tab**: Custom filter tab showing only favorited plugins with count badge
- **Auto-redirect**: Automatically opens the Favorites view if user has saved favorites
- **AJAX-powered**: Toggle favorites without page refresh
- **Per-user Storage**: Each admin has their own favorites list

## How It Works

### Architecture

```
plugin-favorites.php          # Main plugin class
assets/
  css/plugin-favorites.css    # Styling for star icons and count badges
  js/plugin-favorites.js      # jQuery AJAX handling for toggling favorites
```

### Core Components

#### 1. Favorite Storage
Favorites are stored in WordPress user meta with the key `favorite_plugins`. Each user has their own list of favorited plugin file paths.

```php
private $user_meta_key = 'favorite_plugins';
```

#### 2. Favorite Toggle Link
The `add_favorite_link()` method hooks into `plugin_action_links` to add a star icon at the beginning of each plugin's action links.

- ☆ = Not favorited (click to add)
- ⭐ = Favorited (click to remove)

#### 3. Favorites Tab
The `add_favorites_view()` method hooks into `views_plugins` to add a "Favorites" tab with a count of favorited plugins.

#### 4. Plugin Filtering
The `filter_favorite_plugins()` method hooks into `all_plugins` to filter the plugin list when the Favorites tab is active (`?plugin_status=favorites`).

#### 5. AJAX Handler
The `ajax_toggle_favorite()` method handles toggling favorite status via AJAX:
- Verifies nonce for security
- Checks user has `activate_plugins` capability
- Updates user meta with new favorites list
- Returns updated count

### WordPress Hooks Used

| Hook | Method | Purpose |
|------|--------|---------|
| `plugin_action_links` | `add_favorite_link()` | Add star toggle to plugin rows |
| `views_plugins` | `add_favorites_view()` | Add Favorites tab |
| `all_plugins` | `filter_favorite_plugins()` | Filter plugin list |
| `wp_ajax_toggle_plugin_favorite` | `ajax_toggle_favorite()` | Handle AJAX requests |
| `admin_enqueue_scripts` | `enqueue_admin_assets()` | Load CSS/JS |
| `admin_init` | `set_default_favorites_view()` | Auto-redirect to favorites |

## Bug Fix: Incorrect Tab Counts

### The Problem

When viewing the Favorites tab, the counts for other tabs (All, Active, Inactive, Auto-updates Disabled) showed incorrect values. For example:

**Before fix (on Favorites tab with 4 favorites):**
- All (4) | Active (4) | Drop-in (1) | Auto-updates Disabled (4) | Favorites (4)

**Expected:**
- All (37) | Active (15) | Inactive (22) | Drop-in (1) | Auto-updates Disabled (37) | Favorites (4)

### Root Cause

The `filter_favorite_plugins()` method filters the `all_plugins` array when on the Favorites tab. WordPress uses this filtered array to calculate counts for ALL tabs, not just the current view. So when only 4 favorited plugins are returned, WordPress calculates all counts based on those 4 plugins.

Additionally, WordPress removes tabs with 0 count, so the "Inactive" tab disappeared entirely when all 4 favorites were active plugins.

### The Solution

Three changes were made to fix this:

#### 1. Store Original Plugin List

Added a class property to store the unfiltered plugin list:

```php
private $all_plugins = null;
```

Modified `filter_favorite_plugins()` to save the original list before filtering:

```php
public function filter_favorite_plugins($plugins) {
    // Store the original plugins list for correct count calculations
    if ($this->all_plugins === null) {
        $this->all_plugins = $plugins;
    }
    // ... rest of filtering logic
}
```

#### 2. Recalculate Correct Counts

Added `recalculate_view_counts()` method that:
- Uses the stored original plugin list
- Counts all, active, inactive, and auto-updates disabled plugins correctly
- Updates the count numbers in existing view tabs using regex replacement

```php
private function recalculate_view_counts($views) {
    $all_plugins = $this->all_plugins;
    $active_plugins = get_option('active_plugins', array());

    // Count from original unfiltered list
    $all_count = count($all_plugins);
    $active_count = 0;
    $inactive_count = 0;
    // ... counting logic

    // Update counts in views using regex
    foreach ($views as $key => &$view) {
        switch ($key) {
            case 'all':
                $view = preg_replace('/\(\d+\)/', '(' . $all_count . ')', $view);
                break;
            // ... other cases
        }
    }
    return $views;
}
```

#### 3. Restore Missing Inactive Tab

WordPress removes tabs with 0 count. When filtering to favorites, if all favorites are active, the Inactive tab disappears. The fix re-adds it:

```php
if (!isset($views['inactive']) && $inactive_count > 0) {
    $inactive_url = admin_url('plugins.php?plugin_status=inactive');
    $inactive_view = sprintf(
        '<a href="%s">%s <span class="count">(%d)</span></a>',
        esc_url($inactive_url),
        __('Inactive', 'plugin-favorites'),
        $inactive_count
    );
    // Insert after 'active' tab to maintain order
    // ... ordering logic
}
```

### Result

After the fix, all tabs show correct counts regardless of which tab is active:

- All (37) | Active (15) | Inactive (22) | Drop-in (1) | Auto-updates Disabled (37) | Favorites (4)

## Security

The plugin implements proper security measures:

- **Nonce verification**: All AJAX requests are verified with `check_ajax_referer()`
- **Capability checking**: Requires `activate_plugins` permission
- **Input sanitization**: Uses `sanitize_text_field()` for user input
- **Output escaping**: Uses `esc_attr()`, `esc_url()`, `esc_attr__()` for output

## File Structure

```
plugin-favorites-manager-main/
├── plugin-favorites.php       # Main plugin file (293 lines)
├── assets/
│   ├── css/
│   │   └── plugin-favorites.css   # Styling
│   └── js/
│       └── plugin-favorites.js    # AJAX functionality
├── DOCUMENTATION.md           # This file
└── README.md                  # Basic readme (if exists)
```

## Requirements

- WordPress 5.0+
- PHP 7.0+
- User must have `activate_plugins` capability (typically Administrator role)