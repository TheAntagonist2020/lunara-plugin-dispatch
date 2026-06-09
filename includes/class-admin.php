<?php
/**
 * Lunara_Dispatch_Admin
 *
 * Renders the settings screen and wires AJAX endpoints. Handles:
 *   - Provider + per-provider API key/model fields (Claude/OpenAI/Gemini/Grok)
 *   - Multi-source RSS manager (add/remove/enable feeds)
 *   - Manual Run Now, Reset Seen Sources, Roundup Migration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lunara_Dispatch_Admin {

    /** @var Lunara_Dispatch_Plugin */
    private $plugin;

    public function __construct(Lunara_Dispatch_Plugin $plugin) {
        $this->plugin = $plugin;

        add_action('admin_menu',  array($this, 'add_admin_menu'));
        add_action('admin_init',  array($this, 'register_settings'));
        add_action('admin_init',  array($this, 'handle_sources_post'));

        add_action('wp_ajax_lunara_dispatch_run_now',          array($this, 'ajax_run_dispatch'));
        add_action('wp_ajax_lunara_dispatch_reset_seen',       array($this, 'ajax_reset_seen_sources'));
        add_action('wp_ajax_lunara_dispatch_migrate_roundups', array($this, 'ajax_migrate_roundups'));
    }

    public function add_admin_menu() {
        add_options_page(
            'Lunara Dispatch Settings',
            'Lunara Dispatch',
            'manage_options',
            'lunara-dispatch-settings',
            array($this, 'settings_page')
        );
    }

    public function register_settings() {
        $opts = array(
            'lunara_dispatch_enabled',
            'lunara_dispatch_post_type',
            'lunara_dispatch_post_status',
            'lunara_dispatch_schedule',
            'lunara_dispatch_provider',
            'lunara_dispatch_max_tokens',

            'lunara_dispatch_claude_key',   'lunara_dispatch_claude_model',
            'lunara_dispatch_openai_key',   'lunara_dispatch_openai_model',
            'lunara_dispatch_gemini_key',   'lunara_dispatch_gemini_model',
            'lunara_dispatch_grok_key',     'lunara_dispatch_grok_model',
        );
        foreach ($opts as $o) {
            register_setting('lunara_dispatch_options', $o);
        }

        register_setting('lunara_dispatch_options', 'lunara_dispatch_voice_refinement', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_voice_refinement'),
            'default'           => '',
        ));

        register_setting('lunara_dispatch_options', 'lunara_dispatch_system_prompt_override', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_prompt_override'),
            'default'           => '',
        ));
    }

    public function sanitize_voice_refinement($value) {
        $value = wp_unslash((string) $value);
        $value = wp_strip_all_tags($value);
        $value = preg_replace('/[ \t]+/', ' ', $value);
        $value = preg_replace('/\R{3,}/', "\n\n", $value);

        return trim((string) $value);
    }

    public function sanitize_prompt_override($value) {
        $value = wp_unslash((string) $value);
        $value = wp_strip_all_tags($value);
        $value = preg_replace('/[ \t]+/', ' ', $value);
        $value = preg_replace('/\R{3,}/', "\n\n", $value);

        return trim((string) $value);
    }

    /**
     * Custom POST handler for the sources table (separate form so we can
     * accept array-of-rows without fighting register_setting).
     */
    public function handle_sources_post() {
        if (!isset($_POST['lunara_dispatch_sources_nonce'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!wp_verify_nonce($_POST['lunara_dispatch_sources_nonce'], 'lunara_dispatch_sources_save')) {
            return;
        }

        $rows = isset($_POST['lds_sources']) && is_array($_POST['lds_sources']) ? $_POST['lds_sources'] : array();
        $clean = array();
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['url'])) {
                continue;
            }
            $clean[] = array(
                'id'      => isset($row['id'])    ? sanitize_key($row['id'])           : '',
                'label'   => isset($row['label']) ? sanitize_text_field($row['label']) : '',
                'url'     => esc_url_raw($row['url']),
                'enabled' => !empty($row['enabled']),
                'max'     => isset($row['max']) ? (int) $row['max'] : 10,
            );
        }
        Lunara_Dispatch_Sources::save_all($clean);

        add_action('admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>Lunara Dispatch sources saved.</p></div>';
        });
    }

    /* ───────────────────────────── AJAX ─────────────────────────────── */

    public function ajax_run_dispatch() {
        check_ajax_referer('lunara_dispatch_run_now', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }
        $result = $this->plugin->run(true);
        if (!empty($result['success'])) {
            wp_send_json_success($result);
        }
        wp_send_json_error($result, 500);
    }

    public function ajax_reset_seen_sources() {
        check_ajax_referer('lunara_dispatch_reset_seen', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }
        $count = $this->plugin->feed_fetcher->clear_seen_sources();
        wp_send_json_success(array(
            'message'        => 'Seen-sources tracker cleared. Run Now will treat all RSS items as fresh.',
            'previous_count' => $count,
        ));
    }

    public function ajax_migrate_roundups() {
        check_ajax_referer('lunara_dispatch_migrate_roundups', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }
        $dry_run = !empty($_POST['dry_run']);
        $limit   = isset($_POST['limit']) ? max(1, (int) $_POST['limit']) : 50;
        $result  = $this->plugin->post_builder->migrate_roundups($dry_run, $limit);

        $result['dry_run'] = $dry_run;
        $result['message'] = $dry_run
            ? sprintf('DRY RUN: %d roundup post(s) would be split (originals will be renamed to "Lunara Journal Archive — [date]" and tagged Archive).', count($result['preview']))
            : sprintf('Migration complete. Split %d roundups into %d individual posts.', $result['migrated'], $result['created']);
        wp_send_json_success($result);
    }

    /* ──────────────────────────── RENDER ─────────────────────────────── */

    public function settings_page() {
        $provider       = sanitize_key(get_option('lunara_dispatch_provider', 'claude'));
        $schedule_value = get_option('lunara_dispatch_schedule', 'daily');
        $post_status    = sanitize_key(get_option('lunara_dispatch_post_status', 'draft'));
        $sources        = Lunara_Dispatch_Sources::all();
        if (empty($sources)) {
            $sources = Lunara_Dispatch_Sources::defaults();
        }
        $enabled_sources = array_values(array_filter($sources, function ($src) {
            return !empty($src['enabled']);
        }));
        $last_report  = method_exists($this->plugin, 'get_last_run_report') ? $this->plugin->get_last_run_report() : array();
        $next_run_ts  = wp_next_scheduled(Lunara_Dispatch_Plugin::CRON_HOOK);
        $next_run     = $next_run_ts ? wp_date('F j, Y g:i a', $next_run_ts) : 'Not scheduled';
        $last_run     = !empty($last_report['timestamp_gmt']) ? get_date_from_gmt($last_report['timestamp_gmt'], 'F j, Y g:i a') : 'No completed runs recorded yet';
        $last_state   = isset($last_report['success']) ? (!empty($last_report['success']) ? 'Healthy' : 'Needs attention') : 'Unknown';
        $last_message = !empty($last_report['message']) ? $last_report['message'] : 'No run message recorded yet.';
        $last_topic_skips = isset($last_report['skipped_topic_duplicates']) ? (int) $last_report['skipped_topic_duplicates'] : 0;
        $last_quality_skips = isset($last_report['skipped_quality_gate']) ? (int) $last_report['skipped_quality_gate'] : 0;
        $last_quality_skip_rows = isset($last_report['quality_gate_skips']) && is_array($last_report['quality_gate_skips']) ? $last_report['quality_gate_skips'] : array();
        $last_image_blocked_sources = isset($last_report['image_blocked_sources']) ? (int) $last_report['image_blocked_sources'] : 0;
        $last_source_items_with_image = isset($last_report['source_items_with_image']) ? (int) $last_report['source_items_with_image'] : 0;
        $last_item_images_sideloaded = isset($last_report['item_images_sideloaded']) ? (int) $last_report['item_images_sideloaded'] : 0;
        $last_section_images_matched = isset($last_report['section_images_matched']) ? (int) $last_report['section_images_matched'] : 0;
        $last_created_with_featured_image = isset($last_report['created_with_featured_image']) ? (int) $last_report['created_with_featured_image'] : 0;
        $status_label = method_exists($this->plugin, 'get_status_label') ? $this->plugin->get_status_label($post_status) : $post_status;
        $system_prompt = class_exists('Lunara_Dispatch_Prompts') ? Lunara_Dispatch_Prompts::system_prompt() : '';
        $prompt_override = get_option('lunara_dispatch_system_prompt_override', '');
        $prompt_mode = '' !== trim((string) $prompt_override) ? 'Full override active' : 'Default prompt active';

        $providers = array(
            'claude' => 'Anthropic Claude',
            'openai' => 'OpenAI (ChatGPT)',
            'gemini' => 'Google Gemini',
            'grok'   => 'xAI Grok',
        );
        ?>
        <div style="background-color: #0a1520; color: #ffffff; padding: 20px; font-family: Georgia, serif; line-height: 1.7;">
            <h1 style="color: #c9a961; font-family: 'Trebuchet MS', sans-serif; text-transform: uppercase;">Lunara Dispatch Automation</h1>
            <p style="color:#cccccc;">Multi-source film-news aggregation, written in the Lunara Journal voice. Each Journal story becomes its own <?php echo esc_html($status_label); ?> post with a safe available source image set as featured.</p>

            <div style="margin:20px 0 28px; padding:18px 20px; border:1px solid rgba(201,169,97,.22); background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.01));">
                <h2 style="color:#c9a961; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; margin:0 0 10px;">Automation Health</h2>
                <p style="margin:0 0 8px; color:#ffffff;"><strong>Status:</strong> <?php echo esc_html($last_state); ?></p>
                <p style="margin:0 0 8px; color:#cccccc;"><strong>Next scheduled run:</strong> <?php echo esc_html($next_run); ?></p>
                <p style="margin:0 0 8px; color:#cccccc;"><strong>Last completed run:</strong> <?php echo esc_html($last_run); ?></p>
                <p style="margin:0 0 8px; color:#cccccc;"><strong>Active sources:</strong> <?php echo esc_html((string) count($enabled_sources)); ?><?php if (!empty($enabled_sources)) : ?> — <?php echo esc_html(implode(', ', array_map(function ($src) { return (string) ($src['label'] ?? 'Source'); }, $enabled_sources))); ?><?php endif; ?></p>
                <p style="margin:0 0 8px; color:#cccccc;"><strong>Live post behavior:</strong> New Journal entries are currently created as <code style="color:#c9a961;"><?php echo esc_html($post_status); ?></code>.</p>
                <p style="margin:0 0 8px; color:#cccccc;"><strong>Last topic-overlap skips:</strong> <?php echo esc_html((string) $last_topic_skips); ?></p>
                <p style="margin:0 0 8px; color:#cccccc;"><strong>Last quality-gate skips:</strong> <?php echo esc_html((string) $last_quality_skips); ?></p>
                <?php if (!empty($last_quality_skip_rows)) : ?>
                    <ul style="margin:0 0 10px 18px; color:#cccccc;">
                        <?php foreach (array_slice($last_quality_skip_rows, 0, 4) as $skip_row) : ?>
                            <li><strong><?php echo esc_html((string) ($skip_row['title'] ?? 'Untitled')); ?>:</strong> <?php echo esc_html((string) ($skip_row['reason'] ?? 'quality gate')); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p style="margin:0 0 8px; color:#cccccc;"><strong>Last source-image blocks:</strong> <?php echo esc_html((string) $last_image_blocked_sources); ?></p>
                <p style="margin:0 0 8px; color:#cccccc;"><strong>Last image path:</strong> <?php echo esc_html((string) $last_source_items_with_image); ?> available / <?php echo esc_html((string) $last_item_images_sideloaded); ?> sideloaded / <?php echo esc_html((string) $last_section_images_matched); ?> matched / <?php echo esc_html((string) $last_created_with_featured_image); ?> attached.</p>
                <p style="margin:0; color:#cccccc;"><strong>Last run note:</strong> <?php echo esc_html($last_message); ?></p>
            </div>

            <?php $this->render_visual_assignment_queue(); ?>

            <form method="post" action="options.php">
                <?php settings_fields('lunara_dispatch_options'); ?>

                <h2 style="color:#c9a961; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; border-bottom:1px solid #c9a961; padding-bottom:6px;">General</h2>
                <table style="width:100%;">
                    <?php $this->row('Enable Automation', '<input type="checkbox" name="lunara_dispatch_enabled" value="1" ' . checked(1, get_option('lunara_dispatch_enabled'), false) . ' />'); ?>
                    <?php $this->row('Target Post Type',
                        '<input type="text" name="lunara_dispatch_post_type" value="' . esc_attr(get_option('lunara_dispatch_post_type', 'journal')) . '" style="' . $this->input_style() . '" />'
                        . '<p style="color:#cccccc;font-size:13px;margin:6px 0 0;">CPT slug. Default: <code style="color:#c9a961;">journal</code></p>'
                    ); ?>
                    <?php
                    $status_opts = '';
                    foreach (array('draft' => 'Draft', 'pending' => 'Pending Review', 'publish' => 'Publish Live', 'private' => 'Private') as $val => $label) {
                        $status_opts .= '<option value="' . esc_attr($val) . '" ' . selected($post_status, $val, false) . '>' . esc_html($label) . '</option>';
                    }
                    $this->row('Publishing Mode',
                        '<select name="lunara_dispatch_post_status" style="' . $this->input_style(300) . '">' . $status_opts . '</select>'
                        . '<p style="color:#cccccc;font-size:13px;margin:6px 0 0;">Choose whether automation creates drafts, pending items, private entries, or publishes straight to the site.</p>'
                    );

                    $opts = '';
                    foreach (array('daily' => 'Daily', 'twice_daily' => 'Twice Daily (every 12h)', 'every_4_hours' => 'Every 4 Hours', 'every_2_hours' => 'Every 2 Hours') as $val => $label) {
                        $opts .= '<option value="' . esc_attr($val) . '" ' . selected($schedule_value, $val, false) . '>' . esc_html($label) . '</option>';
                    }
                    $this->row('Run Frequency', '<select name="lunara_dispatch_schedule" style="' . $this->input_style(300) . '">' . $opts . '</select>');
                    ?>
                </table>

                <h2 style="color:#c9a961; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; border-bottom:1px solid #c9a961; padding-bottom:6px; margin-top:30px;">Voice / Prompt Refinement</h2>
                <p style="color:#cccccc;">Use this as Dalton's current editorial direction for the next Dispatch runs. It is added after the permanent Lunara prompt, so it can steer tone, taste, selection, and anti-patterns without editing plugin code.</p>
                <textarea name="lunara_dispatch_voice_refinement" rows="10" style="<?php echo esc_attr($this->input_style()); ?> min-height:220px; font-family:Consolas, Monaco, monospace;" placeholder="Example: The current Journal voice is too flat and summary-like. Push harder toward reader pull, sharper openings, more specific film taste, and less generic trade recap language."><?php echo esc_textarea(get_option('lunara_dispatch_voice_refinement', '')); ?></textarea>
                <p style="color:#cccccc;font-size:13px;margin:8px 0 0;">Tip: write this like a standing note to the writer. Be blunt. Name what sounded wrong and what the next run should do instead.</p>

                <h2 style="color:#c9a961; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; border-bottom:1px solid #c9a961; padding-bottom:6px; margin-top:30px;">Prompt Access</h2>
                <p style="color:#cccccc;">This is the actual assembled system prompt Dispatch will send to the active AI provider on the next run, including the current refinement note.</p>
                <p style="margin:0 0 8px; color:#ffffff;"><strong>Mode:</strong> <?php echo esc_html($prompt_mode); ?></p>
                <textarea readonly rows="18" style="<?php echo esc_attr($this->input_style()); ?> min-height:360px; font-family:Consolas, Monaco, monospace; white-space:pre-wrap;"><?php echo esc_textarea($system_prompt); ?></textarea>
                <p style="color:#cccccc;font-size:13px;margin:8px 0 18px;">Use this box to read or copy the live prompt. It is read-only so a stray selection cannot rewrite the automation.</p>

                <h3 style="color:#c9a961; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; margin:18px 0 8px;">Full Prompt Override</h3>
                <p style="color:#cccccc;">Optional. Leave this blank to use the permanent Lunara prompt above. Add text here only when you want to replace the base prompt itself; the Voice / Prompt Refinement note will still be appended after it.</p>
                <textarea name="lunara_dispatch_system_prompt_override" rows="14" style="<?php echo esc_attr($this->input_style()); ?> min-height:300px; font-family:Consolas, Monaco, monospace;" placeholder="Blank = use the permanent Lunara Dispatch system prompt. Fill this only when you want to replace the base prompt directly."><?php echo esc_textarea($prompt_override); ?></textarea>
                <p style="color:#cccccc;font-size:13px;margin:8px 0 0;">Recommended workflow: copy the assembled prompt above, revise it here, save, run Dispatch in draft mode, then grade the output before trusting it.</p>

                <h2 style="color:#c9a961; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; border-bottom:1px solid #c9a961; padding-bottom:6px; margin-top:30px;">AI Provider</h2>
                <p style="color:#cccccc;">Pick which AI writes the Journal. The same Lunara editorial system prompt is sent to every provider, so the voice stays identical — only the brain changes. Paste any keys you have; only the active provider's key is required.</p>
                <table style="width:100%;">
                    <?php
                    $opts = '';
                    foreach ($providers as $val => $label) {
                        $opts .= '<option value="' . esc_attr($val) . '" ' . selected($provider, $val, false) . '>' . esc_html($label) . '</option>';
                    }
                    $this->row('Active Provider', '<select name="lunara_dispatch_provider" style="' . $this->input_style(300) . '">' . $opts . '</select>');

                    $this->row('Max Output Tokens', '<input type="number" min="1024" max="16000" name="lunara_dispatch_max_tokens" value="' . esc_attr(get_option('lunara_dispatch_max_tokens', 4096)) . '" style="' . $this->input_style(120) . '" />');
                    ?>
                </table>

                <?php
                $this->provider_block('Claude (Anthropic)', 'claude', 'claude-opus-4-5', 'sk-ant-api03-...');
                $this->provider_block('OpenAI (ChatGPT)',    'openai', 'gpt-4o',          'sk-proj-...');
                $this->provider_block('Google Gemini',       'gemini', 'gemini-2.5-pro',  'AIza...');
                $this->provider_block('xAI Grok',            'grok',   'grok-4',          'xai-...');
                ?>

                <?php submit_button('Save Settings', 'primary', 'submit', false, array('style' => 'background-color: #c9a961; color: #0a1520; font-family:\'Trebuchet MS\',sans-serif; text-transform:uppercase; border:none; padding:10px 20px; margin-top:20px;')); ?>
            </form>

            <h2 style="color:#c9a961; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; border-bottom:1px solid #c9a961; padding-bottom:6px; margin-top:40px;">RSS Sources</h2>
            <p style="color:#cccccc;">Each enabled feed contributes up to its own max-items per run. Items deduped across all sources by URL fingerprint.</p>
            <form method="post">
                <?php wp_nonce_field('lunara_dispatch_sources_save', 'lunara_dispatch_sources_nonce'); ?>
                <table style="width:100%; color:#ffffff; border-collapse:collapse;" id="lds-sources-table">
                    <thead>
                        <tr style="text-align:left; color:#c9a961; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; font-size:12px;">
                            <th style="padding:6px 4px;">On</th>
                            <th style="padding:6px 4px;">Label</th>
                            <th style="padding:6px 4px;">RSS URL</th>
                            <th style="padding:6px 4px;">Max</th>
                            <th style="padding:6px 4px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sources as $idx => $src) : $this->source_row($idx, $src); endforeach; ?>
                    </tbody>
                </table>
                <p>
                    <button type="button" id="lds-add-source" style="background-color:transparent; color:#c9a961; border:1px solid #c9a961; padding:6px 14px; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; cursor:pointer;">+ Add Source</button>
                </p>
                <?php submit_button('Save Sources', 'primary', 'lds_save_sources', false, array('style' => 'background-color: #c9a961; color: #0a1520; font-family:\'Trebuchet MS\',sans-serif; text-transform:uppercase; border:none; padding:10px 20px;')); ?>
            </form>

            <h2 style="color:#c9a961; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; border-bottom:1px solid #c9a961; padding-bottom:6px; margin-top:40px;">Manual Run</h2>
            <p style="color:#cccccc;">Bypasses the Enable Automation toggle. Aggregates all enabled sources, calls the active AI provider, sideloads images, and creates one Journal post per story using the current publishing mode.</p>
            <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                <button id="lunara-dispatch-run-now" type="button" style="background-color:#c9a961; color:#0a1520; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; border:none; padding:10px 20px; cursor:pointer;">Run Now</button>
                <button id="lunara-dispatch-reset-seen" type="button" style="background-color:transparent; color:#c9a961; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; border:1px solid #c9a961; padding:10px 20px; cursor:pointer;">Reset Seen Sources</button>
            </div>
            <div id="lunara-dispatch-message" style="margin-top:10px; color:#ffffff;"></div>

            <h2 style="color:#c9a961; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; border-bottom:1px solid #c9a961; padding-bottom:6px; margin-top:40px;">Migrate Roundups → Individual Posts</h2>
            <p style="color:#cccccc;">Splits existing journal posts that have multiple <code style="color:#c9a961;">&lt;h2&gt;</code> sections into individual entries. Originals renamed "Lunara Journal Archive — [date]" and tagged Archive. Always preview first.</p>
            <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                <button id="lunara-dispatch-migrate-preview" type="button" style="background-color:transparent; color:#c9a961; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; border:1px solid #c9a961; padding:10px 20px; cursor:pointer;">Preview Migration</button>
                <button id="lunara-dispatch-migrate-run" type="button" style="background-color:#c9a961; color:#0a1520; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; border:none; padding:10px 20px; cursor:pointer;">Migrate Now</button>
                <label style="color:#cccccc; font-size:13px;">Limit: <input type="number" id="lunara-dispatch-migrate-limit" value="50" min="1" max="500" style="width:80px; background:#1a2938; color:#fff; border:1px solid #c9a961; padding:4px 8px;" /></label>
            </div>
            <div id="lunara-dispatch-migrate-message" style="margin-top:10px; color:#ffffff; font-size:13px;"></div>
            <div id="lunara-dispatch-migrate-preview-list" style="margin-top:14px; max-height:400px; overflow-y:auto;"></div>
        </div>

        <?php $this->print_template_row(); ?>
        <?php $this->print_admin_js(); ?>
        <?php
    }

    private function row($label, $field) {
        echo '<tr><td style="padding:8px 10px; width:240px; vertical-align:top; color:#c9a961; font-family:\'Trebuchet MS\',sans-serif; text-transform:uppercase;">'
            . esc_html($label)
            . '</td><td style="padding:8px 10px;">' . $field . '</td></tr>';
    }

    private function input_style($width = null) {
        $w = $width ? 'width:' . (int) $width . 'px;' : 'width:100%;';
        return $w . ' background-color:#1a2938; color:#fff; border:1px solid #c9a961; padding:6px;';
    }

    private function provider_block($title, $key, $default_model, $placeholder) {
        $stored_key   = (string) get_option('lunara_dispatch_' . $key . '_key', '');
        $stored_model = (string) get_option('lunara_dispatch_' . $key . '_model', $default_model);
        $masked       = $stored_key !== '' ? substr($stored_key, 0, 8) . str_repeat('•', 18) . substr($stored_key, -4) : '';
        ?>
        <h3 style="color:#c9a961; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; margin-top:24px;"><?php echo esc_html($title); ?></h3>
        <table style="width:100%;">
            <?php
            $key_field = '';
            if ($masked !== '') {
                $key_field .= '<p style="color:#cccccc; font-size:13px; margin:0 0 6px;">Stored: <code style="color:#c9a961;">' . esc_html($masked) . '</code></p>';
            }
            $key_field .= '<input type="password" name="lunara_dispatch_' . esc_attr($key) . '_key" value="' . esc_attr($stored_key) . '" autocomplete="new-password" placeholder="' . esc_attr($placeholder) . '" style="' . $this->input_style() . '" />';

            $this->row('API Key', $key_field);
            $this->row('Model',
                '<input type="text" name="lunara_dispatch_' . esc_attr($key) . '_model" value="' . esc_attr($stored_model) . '" style="' . $this->input_style() . '" />'
                . '<p style="color:#cccccc; font-size:13px; margin:6px 0 0;">Default: <code style="color:#c9a961;">' . esc_html($default_model) . '</code></p>'
            );
            ?>
        </table>
        <?php
    }

    private function source_row($idx, $src) {
        ?>
        <tr style="border-bottom:1px solid #1a2938;">
            <td style="padding:6px 4px;"><input type="checkbox" name="lds_sources[<?php echo (int)$idx; ?>][enabled]" value="1" <?php checked(!empty($src['enabled'])); ?> /></td>
            <td style="padding:6px 4px;"><input type="text" name="lds_sources[<?php echo (int)$idx; ?>][label]" value="<?php echo esc_attr($src['label'] ?? ''); ?>" style="<?php echo esc_attr($this->input_style(180)); ?>" /></td>
            <td style="padding:6px 4px;">
                <input type="hidden" name="lds_sources[<?php echo (int)$idx; ?>][id]" value="<?php echo esc_attr($src['id'] ?? ''); ?>" />
                <input type="url" name="lds_sources[<?php echo (int)$idx; ?>][url]" value="<?php echo esc_attr($src['url'] ?? ''); ?>" style="<?php echo esc_attr($this->input_style()); ?>" />
            </td>
            <td style="padding:6px 4px;"><input type="number" min="1" max="50" name="lds_sources[<?php echo (int)$idx; ?>][max]" value="<?php echo esc_attr($src['max'] ?? 10); ?>" style="<?php echo esc_attr($this->input_style(70)); ?>" /></td>
            <td style="padding:6px 4px;"><button type="button" class="lds-remove-source" style="background:transparent; color:#c9a961; border:none; cursor:pointer;">✕</button></td>
        </tr>
        <?php
    }

    private function print_template_row() {
        ?>
        <script type="text/html" id="lds-source-template">
            <tr style="border-bottom:1px solid #1a2938;">
                <td style="padding:6px 4px;"><input type="checkbox" name="lds_sources[__I__][enabled]" value="1" checked /></td>
                <td style="padding:6px 4px;"><input type="text" name="lds_sources[__I__][label]" value="" placeholder="Label" style="<?php echo esc_attr($this->input_style(180)); ?>" /></td>
                <td style="padding:6px 4px;">
                    <input type="hidden" name="lds_sources[__I__][id]" value="" />
                    <input type="url" name="lds_sources[__I__][url]" value="" placeholder="https://example.com/feed/" style="<?php echo esc_attr($this->input_style()); ?>" />
                </td>
                <td style="padding:6px 4px;"><input type="number" min="1" max="50" name="lds_sources[__I__][max]" value="10" style="<?php echo esc_attr($this->input_style(70)); ?>" /></td>
                <td style="padding:6px 4px;"><button type="button" class="lds-remove-source" style="background:transparent; color:#c9a961; border:none; cursor:pointer;">✕</button></td>
            </tr>
        </script>
        <?php
    }

    private function print_admin_js() {
        $run_nonce      = wp_create_nonce('lunara_dispatch_run_now');
        $reset_nonce    = wp_create_nonce('lunara_dispatch_reset_seen');
        $migrate_nonce  = wp_create_nonce('lunara_dispatch_migrate_roundups');
        ?>
        <script>
        jQuery(function($){
            // Add / remove RSS source rows
            $('#lds-add-source').on('click', function(){
                var idx = $('#lds-sources-table tbody tr').length;
                var html = $('#lds-source-template').html().replace(/__I__/g, idx);
                $('#lds-sources-table tbody').append(html);
            });
            $(document).on('click', '.lds-remove-source', function(){
                $(this).closest('tr').remove();
            });

            // Run Now
            $('#lunara-dispatch-run-now').on('click', function(){
                var $btn = $(this).prop('disabled', true).text('Running...');
                $('#lunara-dispatch-message').text('Aggregating feeds and calling the AI — this can take up to 90 seconds…').css('color','#c9a961');
                $.ajax({
                    url: ajaxurl, type:'POST', dataType:'json', timeout: 180000,
                    data: { action:'lunara_dispatch_run_now', nonce: '<?php echo esc_js($run_nonce); ?>' },
                    success: function(r){
                        if (r.success && r.data) {
                            var d = r.data, msg = d.message;
                            if (Array.isArray(d.post_ids) && d.post_ids.length) {
                                var statusLabel = d.post_status_label || d.post_status || 'posts';
                                msg += ' [' + statusLabel + ': ' + d.post_ids.join(', ') + ']';
                            }
                            if (typeof d.imported !== 'undefined') {
                                msg += ' (Imported ' + d.imported + ', URL skipped ' + (d.skipped_duplicates || 0) + ', Topic skipped ' + (d.skipped_topic_duplicates || 0) + ', Quality skipped ' + (d.skipped_quality_gate || 0) + ', Source images blocked ' + (d.image_blocked_sources || 0) + ')';
                            }
                            if (typeof d.item_images_sideloaded !== 'undefined') {
                                msg += ' Images: ' + (d.source_items_with_image || 0) + ' available / ' + (d.item_images_sideloaded || 0) + ' sideloaded / ' + (d.section_images_matched || 0) + ' matched / ' + (d.created_with_featured_image || 0) + ' attached.';
                            }
                            if (d.feed_errors && Object.keys(d.feed_errors).length) {
                                msg += '  Feed errors: ' + Object.keys(d.feed_errors).join(', ');
                            }
                            $('#lunara-dispatch-message').text(msg).css('color','#c9a961');
                        } else {
                            $('#lunara-dispatch-message').text('Run completed with unexpected response.').css('color','#ff9900');
                        }
                    },
                    error: function(xhr){
                        var t = 'Error: Could not run dispatch.';
                        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            t = 'Error: ' + xhr.responseJSON.data.message;
                        }
                        $('#lunara-dispatch-message').text(t).css('color','#ff0000');
                    },
                    complete: function(){ $btn.prop('disabled', false).text('Run Now'); }
                });
            });

            // Reset seen
            $('#lunara-dispatch-reset-seen').on('click', function(){
                if (!confirm('Clear the seen-sources tracker? Next run will reprocess every RSS item.')) return;
                var $btn = $(this).prop('disabled', true).text('Resetting...');
                $.ajax({
                    url: ajaxurl, type:'POST', dataType:'json',
                    data: { action:'lunara_dispatch_reset_seen', nonce:'<?php echo esc_js($reset_nonce); ?>' },
                    success: function(r){
                        if (r.success && r.data) {
                            var msg = r.data.message + ' (' + (r.data.previous_count || 0) + ' fingerprints cleared)';
                            $('#lunara-dispatch-message').text(msg).css('color','#c9a961');
                        }
                    },
                    error: function(){ $('#lunara-dispatch-message').text('Error: could not reset tracker.').css('color','#ff0000'); },
                    complete: function(){ $btn.prop('disabled', false).text('Reset Seen Sources'); }
                });
            });

            // Migrate
            function migrate(dry){
                var $p = $('#lunara-dispatch-migrate-preview'), $r = $('#lunara-dispatch-migrate-run');
                var $msg = $('#lunara-dispatch-migrate-message'), $list = $('#lunara-dispatch-migrate-preview-list');
                var limit = parseInt($('#lunara-dispatch-migrate-limit').val(), 10) || 50;
                $p.prop('disabled', true); $r.prop('disabled', true);
                $msg.text(dry ? 'Scanning…' : 'Splitting…').css('color','#c9a961');
                $list.empty();
                $.ajax({
                    url: ajaxurl, type:'POST', dataType:'json', timeout: 120000,
                    data: { action:'lunara_dispatch_migrate_roundups', nonce:'<?php echo esc_js($migrate_nonce); ?>', dry_run: dry?1:0, limit: limit },
                    success: function(r){
                        if (r.success && r.data) {
                            var d = r.data;
                            $msg.text(d.message + ' (Scanned ' + d.scanned + ', Skipped ' + d.skipped + ')').css('color','#c9a961');
                            if (Array.isArray(d.preview) && d.preview.length) {
                                var html = '<h3 style="color:#c9a961; font-family:\'Trebuchet MS\',sans-serif; margin:14px 0 8px;">' + (dry ? 'WOULD SPLIT' : 'SPLIT') + ':</h3><ul style="list-style:disc; padding-left:24px; color:#ccc;">';
                                d.preview.forEach(function(it){
                                    html += '<li style="margin-bottom:8px;"><strong style="color:#fff;">[#' + it.id + '] ' + (it.title || '(untitled)') + '</strong><br><span style="font-size:12px; color:#999;">→ ' + it.sections.length + ' section(s): ' + it.sections.join(' | ') + '</span></li>';
                                });
                                html += '</ul>';
                                $list.html(html);
                            }
                        }
                    },
                    error: function(xhr){
                        var t = 'Error: Migration failed.';
                        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            t = 'Error: ' + xhr.responseJSON.data.message;
                        }
                        $msg.text(t).css('color','#ff0000');
                    },
                    complete: function(){ $p.prop('disabled', false); $r.prop('disabled', false); }
                });
            }
            $('#lunara-dispatch-migrate-preview').on('click', function(){ migrate(true); });
            $('#lunara-dispatch-migrate-run').on('click', function(){
                var lim = $('#lunara-dispatch-migrate-limit').val();
                if (!confirm('Split up to ' + lim + ' roundup post(s) into individual entries? Originals will be archived.')) return;
                migrate(false);
            });
        });
        </script>
        <?php
    }

    private function render_visual_assignment_queue() {
        $post_type = method_exists($this->plugin, 'post_builder') ? 'journal' : sanitize_key((string) get_option('lunara_dispatch_post_type', 'journal'));
        if (empty($post_type) || !post_type_exists($post_type)) {
            $post_type = 'post';
        }

        $query = new WP_Query(array(
            'post_type'      => $post_type,
            'post_status'    => array('draft', 'pending'),
            'posts_per_page' => 20,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ));

        $items = array();
        foreach ($query->posts as $post) {
            if ((int) get_post_thumbnail_id($post->ID) > 0) {
                continue;
            }

            $query_text = trim((string) get_post_meta($post->ID, '_lunara_dispatch_visual_search_query', true));
            if ('' === $query_text) {
                $query_text = $this->build_visual_queue_search_query($post);
            }

            $brief = trim((string) get_post_meta($post->ID, '_lunara_dispatch_visual_brief', true));
            if ('' === $brief) {
                $brief = 'Find a safe, exact visual tied to the real subject of this draft. Prefer official stills, trailer frames, distributor/press assets, or correctly credited outlet images.';
            }

            $items[] = array(
                'id'     => (int) $post->ID,
                'title'  => get_the_title($post),
                'edit'   => get_edit_post_link($post->ID, ''),
                'query'  => $query_text,
                'brief'  => $brief,
                'google' => 'https://www.google.com/search?tbm=isch&q=' . rawurlencode($query_text),
                'tmdb'   => 'https://www.themoviedb.org/search?query=' . rawurlencode($query_text),
            );

            if (count($items) >= 6) {
                break;
            }
        }

        wp_reset_postdata();
        ?>
        <div style="margin:20px 0 28px; padding:18px 20px; border:1px solid rgba(201,169,97,.22); background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.01));">
            <h2 style="color:#c9a961; font-family:'Trebuchet MS',sans-serif; text-transform:uppercase; margin:0 0 10px;">Visual Assignment Assistant</h2>
            <p style="margin:0 0 12px; color:#cccccc;">Drafts without featured images get a search brief instead of a random fallback image. Use this queue to find exact, safe art before publishing.</p>
            <?php if (empty($items)) : ?>
                <p style="margin:0; color:#cccccc;">No draft Journal entries are currently missing featured images.</p>
            <?php else : ?>
                <div style="display:grid; gap:12px;">
                    <?php foreach ($items as $item) : ?>
                        <div style="padding:14px; border:1px solid rgba(201,169,97,.16); background:rgba(6,14,23,.72);">
                            <h3 style="margin:0 0 8px; color:#ffffff; font-size:16px;"><?php echo esc_html('#' . $item['id'] . ' ' . $item['title']); ?></h3>
                            <p style="margin:0 0 8px; color:#cccccc;"><?php echo esc_html($item['brief']); ?></p>
                            <p style="margin:0 0 10px; color:#c9a961;"><strong>Search:</strong> <?php echo esc_html($item['query']); ?></p>
                            <p style="margin:0;">
                                <a href="<?php echo esc_url($item['edit']); ?>" style="color:#c9a961; margin-right:14px;">Edit draft</a>
                                <a href="<?php echo esc_url($item['google']); ?>" target="_blank" rel="noopener noreferrer" style="color:#c9a961; margin-right:14px;">Image search</a>
                                <a href="<?php echo esc_url($item['tmdb']); ?>" target="_blank" rel="noopener noreferrer" style="color:#c9a961;">TMDB search</a>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function build_visual_queue_search_query($post) {
        $title = $post instanceof WP_Post ? get_the_title($post) : '';
        $title = html_entity_decode(wp_strip_all_tags((string) $title), ENT_QUOTES, get_bloginfo('charset'));
        $title = trim(preg_replace('/[^\p{L}\p{N}\s\'".:&-]+/u', ' ', $title));
        $title = trim(preg_replace('/\s+/u', ' ', (string) $title));

        if ('' === $title && $post instanceof WP_Post) {
            $title = wp_trim_words(wp_strip_all_tags((string) $post->post_content), 10, '');
        }

        return trim($title . ' film official still press image trailer');
    }
}
