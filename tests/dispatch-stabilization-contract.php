<?php
/**
 * Source-level release contract for the standalone Dispatch package.
 * Run: php tests/dispatch-stabilization-contract.php
 */

$root = dirname(__DIR__);
$failures = array();

function dispatch_contract_file($root, $path) {
    $content = file_get_contents($root . DIRECTORY_SEPARATOR . $path);
    if (false === $content) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $content;
}

function dispatch_contract_contains($content, $needle, $label, &$failures) {
    if (false === strpos($content, $needle)) {
        $failures[] = $label;
    }
}

function dispatch_contract_excludes($content, $needle, $label, &$failures) {
    if (false !== strpos($content, $needle)) {
        $failures[] = $label;
    }
}

$bootstrap = dispatch_contract_file($root, 'lunara-dispatch.php');
$plugin = dispatch_contract_file($root, 'includes/class-plugin.php');
$admin = dispatch_contract_file($root, 'includes/class-admin.php');
$feed = dispatch_contract_file($root, 'includes/class-feed-fetcher.php');
$ai = dispatch_contract_file($root, 'includes/class-ai-client.php');
$images = dispatch_contract_file($root, 'includes/class-image-handler.php');
$posts = dispatch_contract_file($root, 'includes/class-post-builder.php');
$blocks = dispatch_contract_file($root, 'includes/class-blocks.php');
$prompts = dispatch_contract_file($root, 'includes/class-prompts.php');
$control = dispatch_contract_file($root, 'includes/class-control-plane-client.php');
$bridge = dispatch_contract_file($root, 'includes/class-journal-ingest-bridge.php');

dispatch_contract_contains($bootstrap, "Version:     3.2.3", 'plugin header is not 3.2.3', $failures);
dispatch_contract_contains($bootstrap, "LUNARA_DISPATCH_VERSION', '3.2.3'", 'runtime version constant is not 3.2.3', $failures);
dispatch_contract_contains($plugin, "lunara_journal_control_plane_activated", 'Control Plane activation is not consumed', $failures);
dispatch_contract_contains($plugin, 'add_option(self::LOCK_KEY', 'worker lock is not atomically inserted', $failures);
dispatch_contract_contains($plugin, 'AND option_value = %s', 'worker lock lacks compare-and-swap ownership', $failures);
dispatch_contract_excludes($plugin, 'set_transient(self::LOCK_KEY', 'legacy transient lock remains active', $failures);
dispatch_contract_contains($plugin, 'foundation_is_available()', 'worker does not gate runs on the required Foundation dependency', $failures);
dispatch_contract_contains($plugin, "new WP_Error('lunara_dispatch_foundation_required'", 'manual queue does not reject an absent Foundation dependency', $failures);
dispatch_contract_contains($plugin, '&& Lunara_Dispatch_Control_Plane_Client::available()', 'forced runs can bypass Foundation protocol compatibility', $failures);
dispatch_contract_contains($plugin, "0 === (int) \$updated", 'same-second heartbeat no-op is not handled', $failures);
dispatch_contract_contains($plugin, "(int) \$current['expires'] >= time()", 'same-second heartbeat does not require an unexpired owner lock', $failures);
if (preg_match_all('/if\s*\(\s*!\s*\$this->heartbeat_lock\s*\(/', $plugin) < 4) {
    $failures[] = 'worker phases do not stop after heartbeat ownership loss';
}
dispatch_contract_contains($admin, '$this->plugin->queue_manual_run()', 'Settings run bypasses the async queue', $failures);
dispatch_contract_excludes($admin, "'publish' => 'Publish Live'", 'Publish Live remains in the Dispatch UI', $failures);
dispatch_contract_excludes($admin, 'wp_ajax_lunara_dispatch_migrate_roundups', 'retired migration AJAX route remains registered', $failures);
dispatch_contract_excludes($admin, 'lunara-dispatch-migrate-run', 'retired migration browser controls remain packaged', $failures);
dispatch_contract_contains($feed, 'wp_safe_remote_get($url', 'article scraping is not using the safe HTTP API', $failures);
dispatch_contract_contains($feed, 'MAX_ITEMS_PER_RUN', 'feed input lacks a global item budget', $failures);
dispatch_contract_contains($feed, 'MAX_FEED_BYTES', 'feed downloads lack a byte budget', $failures);
dispatch_contract_contains($feed, "'limit_response_size'", 'feed byte budget is not applied to HTTP requests', $failures);
dispatch_contract_contains($feed, 'image_source_verified', 'feed images are not tied to their exact source story', $failures);
dispatch_contract_contains($feed, '$source_allows_images = !$image_blocked;', 'legacy source flags still disable all source-story images', $failures);
dispatch_contract_contains($feed, 'resolve_source_story_image', 'existing Dispatch drafts cannot resolve their source-story image', $failures);
dispatch_contract_contains($feed, 'article_open_graph', 'article Open Graph images are not identified as source-story leads', $failures);
dispatch_contract_contains($ai, "'x-goog-api-key' => \$key", 'Gemini key is not sent by header', $failures);
dispatch_contract_excludes($ai, ':generateContent?key=', 'Gemini key remains in a URL', $failures);
dispatch_contract_contains($images, 'assign_images_to_posts', 'images are not assigned after draft acceptance', $failures);
dispatch_contract_contains($images, 'MAX_IMAGE_BYTES', 'image downloads lack a byte budget', $failures);
dispatch_contract_contains($images, 'MAX_IMAGE_PIXELS', 'image downloads lack a decoded-pixel budget', $failures);
dispatch_contract_contains($images, 'valid_extensions', 'content-negotiated image MIME types are not reconciled with their Media Library filename', $failures);
dispatch_contract_contains($images, 'item_has_source_story_image', 'image imports are not gated by exact source-story provenance', $failures);
dispatch_contract_contains($images, 'find_exact_source_item_index', 'new drafts do not prefer exact canonical source URL image matching', $failures);
dispatch_contract_excludes($images, "'keyword_fallback'", 'new drafts can still receive an image through title or keyword guessing', $failures);
dispatch_contract_contains($images, '_lunara_dispatch_image_source_verified', 'Media Library attachments do not retain source-story verification', $failures);
dispatch_contract_contains($plugin, 'backfill_source_story_images', 'existing Dispatch drafts lack a source-image repair path', $failures);
dispatch_contract_contains($plugin, "lunara-dispatch source-images", 'source-image repair is not exposed as a dry-run-first CLI command', $failures);
dispatch_contract_contains($plugin, "'post_status' => 'draft'", 'source-image repair is not limited to Journal drafts', $failures);
dispatch_contract_contains($plugin, "'compare' => 'NOT EXISTS'", 'source-image repair does not preserve existing featured images', $failures);
dispatch_contract_contains($plugin, "'value' => '0'", 'source-image repair misses drafts with an explicitly empty thumbnail field', $failures);
dispatch_contract_contains($posts, 'Lunara_Dispatch_Journal_Ingest_Bridge::build_payload', 'post builder bypasses the Journal ingest bridge', $failures);
dispatch_contract_excludes($posts, 'wp_insert_post(', 'post builder retains an independent direct-insert path', $failures);
dispatch_contract_contains($posts, "'disabled' => true", 'legacy roundup migration is not retired', $failures);
dispatch_contract_excludes($posts, '_lunara_dispatch_roundup_migrated', 'unsafe legacy roundup migration remains executable', $failures);
dispatch_contract_contains($blocks, "'inserter' => true", 'Journal blocks are not editable from the inserter', $failures);
dispatch_contract_contains($blocks, "'loading' => 'eager', 'fetchpriority' => 'high'", 'spotlight lead is not eager and high priority', $failures);
dispatch_contract_contains($blocks, "'loading' => 'lazy', 'fetchpriority' => 'auto'", 'non-lead cards are not lazy loaded', $failures);
dispatch_contract_contains($prompts, 'SECURITY BOUNDARY:', 'prompt injection boundary is missing', $failures);
dispatch_contract_contains($control, 'supports_protocol', 'Foundation protocol compatibility is missing', $failures);
dispatch_contract_contains($control, "apply_filters( 'lunara_journal_foundation_ingest', null, \$payload )", 'Foundation ingest filter is not used', $failures);
dispatch_contract_contains($control, "array( 'post_id', 'created', 'post_status', 'idempotency_key' )", 'Foundation response schema is not enforced', $failures);
dispatch_contract_contains($control, "'protocol_error'", 'incompatible Foundation runtime is not disabled', $failures);
dispatch_contract_contains($bridge, "'idempotency_key'", 'Journal bridge does not build an idempotency key', $failures);
dispatch_contract_contains($bridge, "class_exists( 'Lunara_Journal_Control_Plane' )", 'active Foundation is not authoritative', $failures);
dispatch_contract_contains($bridge, 'lunara_dispatch_foundation_required', 'absent Foundation does not fail closed', $failures);
dispatch_contract_excludes($bridge, 'wp_insert_post(', 'Journal bridge retains a standalone insert fallback', $failures);
dispatch_contract_contains($bootstrap, "class-journal-ingest-bridge.php", 'Journal ingest bridge is not loaded', $failures);
dispatch_contract_contains($admin, 'Journal Foundation dependency:', 'admin health does not surface the required Foundation dependency', $failures);

if (!defined('ABSPATH')) {
    define('ABSPATH', $root . DIRECTORY_SEPARATOR);
}
require_once $root . DIRECTORY_SEPARATOR . 'includes/class-control-plane-client.php';
if (!Lunara_Dispatch_Control_Plane_Client::supports_protocol('1.2.1')) {
    $failures[] = 'major-1 Journal protocol should be supported';
}
foreach (array('2.0.0', '1.2', '', null, array()) as $unsupported_protocol) {
    if (Lunara_Dispatch_Control_Plane_Client::supports_protocol($unsupported_protocol)) {
        $failures[] = 'unsupported or malformed Journal protocol was accepted';
        break;
    }
}

foreach (array('assets/js/lunara-dispatch-blocks.js', 'assets/css/lunara-dispatch-blocks.css') as $asset) {
    if (!is_file($root . DIRECTORY_SEPARATOR . $asset)) {
        $failures[] = 'missing block asset: ' . $asset;
    }
}

if ($failures) {
    fwrite(STDERR, "Dispatch stabilization contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Dispatch stabilization contract passed.\n";
