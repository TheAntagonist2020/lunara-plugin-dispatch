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
        $sources        = Lunara_Dispatch_Sources::all();
        if (empty($sources)) {
            $sources = Lunara_Dispatch_Sources::defaults();
        }

        $providers = array(
            'claude' => 'Anthropic Claude',
            'openai' => 'OpenAI (ChatGPT)',
            'gemini' => 'Google Gemini',
            'grok'   => 'xAI Grok',
        );
        ?>
        <div style="background-color: #0a1520; color: #ffffff; padding: 20px; font-family: Georgia, serif; line-height: 1.7;">
            <h1 style="color: #c9a961; font-family: 'Trebuchet MS', sans-serif; text-transform: uppercase;">Lunara Dispatch Automation</h1>
            <p style="color:#cccccc;">Multi-source film-news aggregation, written in the Lunara Journal voice. Each Journal story becomes its own draft post with the matched RSS image set as featured.</p>

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
                    $opts = '';
                    foreach (array('daily' => 'Daily', 'twice_daily' => 'Twice Daily (every 12h)', 'every_4_hours' => 'Every 4 Hours', 'every_2_hours' => 'Every 2 Hours') as $val => $label) {
                        $opts .= '<option value="' . esc_attr($val) . '" ' . selected($schedule_value, $val, false) . '>' . esc_html($label) . '</option>';
                    }
                    $this->row('Run Frequency', '<select name="lunara_dispatch_schedule" style="' . $this->input_style(300) . '">' . $opts . '</select>');
                    ?>
                </table>

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
            <p style="color:#cccccc;">Bypasses the Enable Automation toggle. Aggregates all enabled sources, calls the active AI provider, sideloads images, and creates one draft per Journal story.</p>
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
                                msg += ' [Drafts: ' + d.post_ids.join(', ') + ']';
                            }
                            if (typeof d.imported !== 'undefined') {
                                msg += ' (Imported ' + d.imported + ', Skipped ' + (d.skipped_duplicates || 0) + ')';
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
}
