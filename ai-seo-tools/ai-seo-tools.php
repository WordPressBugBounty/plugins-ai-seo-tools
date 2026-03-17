<?php

/**
 * Plugin Name:       AI SEO Tools
 * Description:       Leverages AI to enhance SEO aspects like image alt text, content refresh and auto tagging.
 * Version:           2.0.3
 * Author:            KingAddons.com
 * Author URI:        https://kingaddons.com
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ai-seo-tools
 */

// If this file is called directly, abort.
if (! defined('WPINC')) {
    die;
}

/**
 * Define constants
 */
define('AI_SEO_TOOLS_VERSION', '2.0.3');
define('AI_SEO_TOOLS_PATH', plugin_dir_path(__FILE__));
define('AI_SEO_TOOLS_URL', plugin_dir_url(__FILE__));

/**
 * Load admin functionality (always needed for settings).
 */
require_once AI_SEO_TOOLS_PATH . 'admin/class-ai-seo-tools-settings.php';
new AI_SEO_Tools_Settings(); // Instantiate settings class

/**
 * Define available modules.
 * Key: module slug (used in options), Value: path to the module's main class file.
 */
function ai_seo_tools_get_modules(): array
{
    return [
        'enable_alt_text_module' => AI_SEO_TOOLS_PATH . 'modules/alt-text/class-ai-seo-tools-alt-text.php',
        'enable_content_refresh_module' => AI_SEO_TOOLS_PATH . 'modules/content-refresh/class-ai-seo-tools-content-refresh.php',
        'enable_auto_tagging_module' => AI_SEO_TOOLS_PATH . 'modules/auto-tagging/class-ai-seo-tools-auto-tagging.php',
        // Add other modules here later:
        // 'enable_file_rename_module' => AI_SEO_TOOLS_PATH . 'modules/file-rename/class-ai-seo-tools-file-rename.php',
        // 'enable_summarizer_module' => AI_SEO_TOOLS_PATH . 'modules/summarizer/class-ai-seo-tools-summarizer.php',
    ];
}

/**
 * Load active modules based on settings.
 */
function ai_seo_tools_load_active_modules(): void
{
    $options = get_option('ai_seo_tools_options', []); // Get saved options or default to empty array
    $modules = ai_seo_tools_get_modules();

    foreach ($modules as $option_key => $module_file) {
        // Load if the option is explicitly '1' OR if it's not set at all (default to enabled).
        // Equivalent to checking if the option is NOT explicitly set to '0'.
        if (($options[$option_key] ?? '1') !== '0') {
            if (file_exists($module_file)) {
                require_once $module_file;
                // Assuming the class name follows a pattern or we define it elsewhere.
                // For now, let's derive class name from file name:
                // e.g., class-ai-seo-tools-alt-text.php -> AI_SEO_Tools_Alt_Text
                $class_name = str_replace('-', '_', ucwords(basename($module_file, '.php'), '-'));
                $class_name = str_replace('Class_', '', $class_name); // Remove potential Class_ prefix

                if (class_exists($class_name)) {
                    new $class_name();
                }
            }
        }
    }
}

// Load modules after plugins are loaded to ensure options are available.
add_action('plugins_loaded', 'ai_seo_tools_load_active_modules');

/**
 * Load includes functionality.
 */
// require_once AI_SEO_TOOLS_PATH . 'includes/class-ai-seo-tools-alt-text.php'; // MOVED TO MODULES

/**
 * Initialize classes.
 */
// if ( class_exists( 'AI_SEO_Tools_Settings' ) ) { // Instantiated above
// 	new AI_SEO_Tools_Settings();
// }

// if ( class_exists( 'AI_SEO_Tools_Alt_Text' ) ) { // MOVED TO MODULES
// 	new AI_SEO_Tools_Alt_Text();
// } 