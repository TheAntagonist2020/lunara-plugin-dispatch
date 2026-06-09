<?php
/**
 * Plugin Name: Lunara Dispatch Automation
 * Plugin URI:  https://lunarafilm.com
 * Description: Aggregates film news from multiple RSS sources, runs items through Claude with the Lunara Journal editorial voice, sideloads images, and creates one draft journal post per Journal section with the matched image featured.
 * Version:     3.0.13
 * Author:      Lunara Film
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lunara-dispatch
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LUNARA_DISPATCH_VERSION', '3.0.13');
define('LUNARA_DISPATCH_FILE', __FILE__);
define('LUNARA_DISPATCH_DIR', plugin_dir_path(__FILE__));
define('LUNARA_DISPATCH_URL', plugin_dir_url(__FILE__));

require_once LUNARA_DISPATCH_DIR . 'includes/class-prompts.php';
require_once LUNARA_DISPATCH_DIR . 'includes/class-sources.php';
require_once LUNARA_DISPATCH_DIR . 'includes/class-feed-fetcher.php';
require_once LUNARA_DISPATCH_DIR . 'includes/class-ai-client.php';
require_once LUNARA_DISPATCH_DIR . 'includes/class-image-handler.php';
require_once LUNARA_DISPATCH_DIR . 'includes/class-post-builder.php';
require_once LUNARA_DISPATCH_DIR . 'includes/class-blocks.php';
require_once LUNARA_DISPATCH_DIR . 'includes/class-admin.php';
require_once LUNARA_DISPATCH_DIR . 'includes/class-plugin.php';

register_activation_hook(__FILE__, array('Lunara_Dispatch_Plugin', 'on_activate'));
register_deactivation_hook(__FILE__, array('Lunara_Dispatch_Plugin', 'on_deactivate'));

add_action('plugins_loaded', array('Lunara_Dispatch_Plugin', 'instance'));
