<?php
/**
 * Lunara_Dispatch_Plugin
 *
 * Main orchestrator. Wires the pieces together, owns the cron schedule,
 * and exposes the run-now entry point used by AJAX + the cron handler.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lunara_Dispatch_Plugin {

    const CRON_HOOK = 'lunara_dispatch_scheduled';
    const MANUAL_CRON_HOOK = 'lunara_dispatch_manual_requested';
    const LOCK_KEY  = 'lunara_dispatch_running';
    const REPORT_OPTION = 'lunara_dispatch_last_run_report';
    const HISTORY_OPTION = 'lunara_dispatch_run_history';
    const HISTORY_LIMIT = 20;
    const LOCK_TTL = 20 * MINUTE_IN_SECONDS;
    const SKIP_MARKER = 'LUNARA_SKIP';

    /** @var Lunara_Dispatch_Plugin */
    private static $instance = null;

    /** @var Lunara_Dispatch_Feed_Fetcher  */ public $feed_fetcher;
    /** @var Lunara_Dispatch_AI_Client     */ public $ai_client;
    /** @var Lunara_Dispatch_Image_Handler */ public $image_handler;
    /** @var Lunara_Dispatch_Post_Builder  */ public $post_builder;
    /** @var Lunara_Dispatch_Admin         */ public $admin;

    /** @var string */ private $current_run_id = '';

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (is_admin() && class_exists('Lunara_Dispatch_Admin')) {
            $this->admin = new Lunara_Dispatch_Admin($this);
        }

        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action(self::CRON_HOOK, array($this, 'run_scheduled'));
        add_action(self::MANUAL_CRON_HOOK, array($this, 'run_manual_scheduled'));
        add_action('update_option_lunara_dispatch_schedule', array($this, 'reschedule_on_frequency_change'), 10, 2);
        add_action('lunara_journal_control_plane_activated', array($this, 'reschedule_from_control_plane'), 10, 2);
    }

    public function ensure_services() {
        if (!$this->feed_fetcher) {
            require_once LUNARA_DISPATCH_DIR . 'includes/class-feed-fetcher.php';
            require_once LUNARA_DISPATCH_DIR . 'includes/class-ai-client.php';
            require_once LUNARA_DISPATCH_DIR . 'includes/class-image-handler.php';
            require_once LUNARA_DISPATCH_DIR . 'includes/class-post-builder.php';
            $this->feed_fetcher  = new Lunara_Dispatch_Feed_Fetcher();
            $this->ai_client     = new Lunara_Dispatch_AI_Client();
            $this->image_handler = new Lunara_Dispatch_Image_Handler();
            $this->post_builder  = new Lunara_Dispatch_Post_Builder();
        }
    }

    public static function on_activate() {
        Lunara_Dispatch_Sources::install_defaults_if_empty();
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $recurrence = self::recurrence_from_setting(class_exists('Lunara_Dispatch_Control_Plane_Client') ? Lunara_Dispatch_Control_Plane_Client::schedule() : get_option('lunara_dispatch_schedule', 'daily'));
            wp_schedule_event(strtotime('+1 hour'), $recurrence, self::CRON_HOOK);
        }
    }

    public static function on_deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::MANUAL_CRON_HOOK);
    }

    public function add_cron_schedules($schedules) {
        $schedules['lunara_twice_daily']   = array('interval' => 12 * HOUR_IN_SECONDS, 'display' => __('Twice Daily (Lunara)'));
        $schedules['lunara_every_4_hours'] = array('interval' =>  4 * HOUR_IN_SECONDS, 'display' => __('Every 4 Hours (Lunara)'));
        $schedules['lunara_every_2_hours'] = array('interval' =>  2 * HOUR_IN_SECONDS, 'display' => __('Every 2 Hours (Lunara)'));
        return $schedules;
    }

    public static function recurrence_from_setting($setting) {
        switch ($setting) {
            case 'twice_daily':
                return 'lunara_twice_daily';
            case 'every_4_hours':
                return 'lunara_every_4_hours';
            case 'every_2_hours':
                return 'lunara_every_2_hours';
            case 'daily':
            default:
                return 'daily';
        }
    }

    public function reschedule_on_frequency_change($old_value, $new_value) {
        if ($old_value === $new_value) {
            return;
        }
        $this->reschedule_cron($new_value);
    }

    /**
     * Keep WP-Cron aligned with the authoritative versioned Journal config.
     *
     * @param int|string $config_id Activated config identifier.
     * @param array      $config    Activated config payload.
     * @return void
     */
    public function reschedule_from_control_plane($config_id = 0, $config = array()) {
        unset($config_id);
        $schedule = is_array($config) && !empty($config['dispatch']['schedule'])
            ? sanitize_key((string) $config['dispatch']['schedule'])
            : (is_array($config) && !empty($config['schedule'])
                ? sanitize_key((string) $config['schedule'])
                : Lunara_Dispatch_Control_Plane_Client::schedule());
        $this->reschedule_cron($schedule);
    }

    private function reschedule_cron($schedule) {
        $recurrence = self::recurrence_from_setting($schedule);
        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event(self::CRON_HOOK) : false;
        if ($event && isset($event->schedule) && $recurrence === $event->schedule) {
            return;
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_event(time() + HOUR_IN_SECONDS, $recurrence, self::CRON_HOOK);
    }

    public function run_scheduled() {
        $this->run(false);
    }

    public function run_manual_scheduled() {
        delete_option('lunara_dispatch_manual_run_queued_at');
        $this->run(true);
    }

    /**
     * Queue a manual Dispatch run and return immediately.
     *
     * @return array|WP_Error
     */
    public function queue_manual_run() {
        if (!$this->foundation_is_available()) {
            return new WP_Error('lunara_dispatch_foundation_required', $this->foundation_error_message());
        }
        if ($this->lock_is_active()) {
            return array(
                'success' => true,
                'queued'  => false,
                'running' => true,
                'message' => 'A Dispatch run is already in progress.',
            );
        }

        $existing = wp_next_scheduled(self::MANUAL_CRON_HOOK);
        if ($existing) {
            return array(
                'success'       => true,
                'queued'        => true,
                'running'       => false,
                'scheduled_gmt' => gmdate('c', $existing),
                'message'       => 'A manual Dispatch run is already queued.',
            );
        }

        $scheduled_for = time() + 1;
        $scheduled = wp_schedule_single_event($scheduled_for, self::MANUAL_CRON_HOOK);
        if (is_wp_error($scheduled)) {
            return $scheduled;
        }
        if (false === $scheduled) {
            return new WP_Error('lunara_dispatch_queue_failed', 'WordPress could not queue the manual Dispatch run.');
        }

        update_option('lunara_dispatch_manual_run_queued_at', current_time('mysql', true), false);
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
        }

        return array(
            'success'       => true,
            'queued'        => true,
            'running'       => false,
            'scheduled_gmt' => gmdate('c', $scheduled_for),
            'message'       => 'Manual Dispatch run queued in WordPress.',
        );
    }

    /**
     * Claim the worker with an atomic option insert or expired-value CAS.
     *
     * @return string|WP_Error Owner token on success.
     */
    private function acquire_lock() {
        global $wpdb;

        $owner = wp_generate_uuid4();
        $payload = $this->lock_payload($owner);
        if (add_option(self::LOCK_KEY, $payload, '', false)) {
            return $owner;
        }

        $raw = $this->read_raw_lock();
        $state = $this->decode_lock($raw);
        if (!empty($state['expires']) && (int) $state['expires'] >= time()) {
            return new WP_Error('lunara_dispatch_locked', 'Another Dispatch run owns the worker lock.');
        }

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $payload,
            self::LOCK_KEY,
            $raw
        ));
        if (1 === (int) $updated) {
            wp_cache_delete(self::LOCK_KEY, 'options');
            return $owner;
        }

        return new WP_Error('lunara_dispatch_locked', 'Another Dispatch run acquired the worker lock.');
    }

    private function heartbeat_lock($owner) {
        global $wpdb;

        $raw = $this->read_raw_lock();
        $state = $this->decode_lock($raw);
        if (empty($state['owner']) || !hash_equals((string) $state['owner'], (string) $owner)) {
            return false;
        }

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $this->lock_payload($owner),
            self::LOCK_KEY,
            $raw
        ));
        if (1 === (int) $updated) {
            wp_cache_delete(self::LOCK_KEY, 'options');
            return true;
        }

        // MySQL reports zero affected rows when a heartbeat lands within the
        // same second and therefore writes the exact payload already stored.
        // That is not a lost lock. Re-read the authoritative row and accept
        // the no-op only while this worker still owns an unexpired lock.
        if (0 === (int) $updated) {
            $current = $this->decode_lock($this->read_raw_lock());
            return !empty($current['owner'])
                && hash_equals((string) $current['owner'], (string) $owner)
                && !empty($current['expires'])
                && (int) $current['expires'] >= time();
        }
        return false;
    }

    private function release_lock($owner) {
        global $wpdb;

        $raw = $this->read_raw_lock();
        $state = $this->decode_lock($raw);
        if (empty($state['owner']) || !hash_equals((string) $state['owner'], (string) $owner)) {
            return false;
        }

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::LOCK_KEY,
            $raw
        ));
        if (1 === (int) $deleted) {
            wp_cache_delete(self::LOCK_KEY, 'options');
            return true;
        }
        return false;
    }

    private function lock_is_active() {
        $state = $this->decode_lock($this->read_raw_lock());
        return !empty($state['owner']) && !empty($state['expires']) && (int) $state['expires'] >= time();
    }

    private function lock_payload($owner) {
        return wp_json_encode(array(
            'owner'     => (string) $owner,
            'heartbeat' => time(),
            'expires'   => time() + self::LOCK_TTL,
        ));
    }

    private function read_raw_lock() {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            self::LOCK_KEY
        ));
        return is_string($raw) ? $raw : '';
    }

    private function decode_lock($raw) {
        $state = json_decode((string) $raw, true);
        return is_array($state) ? $state : array();
    }

    public function get_target_post_status() {
        // Control Plane guarantee: Dispatch may only create drafts. Legacy values
        // such as publish, pending, or private are intentionally ignored.
        return class_exists('Lunara_Dispatch_Control_Plane_Client') ? Lunara_Dispatch_Control_Plane_Client::post_status() : 'draft';
    }

    public function get_status_label($post_status) {
        switch ($post_status) {
            case 'publish':
                return 'published';
            case 'pending':
                return 'pending';
            case 'private':
                return 'private';
            case 'draft':
            default:
                return 'draft';
        }
    }

    public function get_last_run_report() {
        $report = get_option(self::REPORT_OPTION, array());
        return is_array($report) ? $report : array();
    }

    public function get_run_history() {
        $history = get_option(self::HISTORY_OPTION, array());
        return is_array($history) ? $history : array();
    }

    /**
     * Aggregate, generate, split, and save. Returns a structured result.
     *
     * @param bool $force Bypass the enabled toggle for manual runs.
     * @return array
     */
    public function run($force = false) {
        $this->ensure_services();
        $this->current_run_id = wp_generate_uuid4();
        if (!$this->foundation_is_available()) {
            return $this->result(false, $this->foundation_error_message(), array(
                'retry_required' => true,
                'protocol_error' => true,
            ));
        }
        $enabled = class_exists('Lunara_Dispatch_Control_Plane_Client') ? Lunara_Dispatch_Control_Plane_Client::enabled() : (int) get_option('lunara_dispatch_enabled', 0);
        if (!$force && !$enabled) {
            return $this->result(false, 'Automation is disabled. Enable it in settings or use Run Now.');
        }

        $lock_owner = $this->acquire_lock();
        if (is_wp_error($lock_owner)) {
            return $this->result(false, 'Another dispatch run is already in progress. Try again in a minute.');
        }

        try {
            $fetched = $this->feed_fetcher->fetch_all();
            $items   = $fetched['items'];
            $skipped = $fetched['skipped_duplicates'];
            $errors  = $fetched['errors'];
            if (!$this->heartbeat_lock($lock_owner)) {
                return $this->result(false, 'Dispatch lost worker-lock ownership after feed collection and stopped before generation.', array(
                    'retry_required' => true,
                    'feed_errors' => $errors,
                    'skipped_duplicates' => $skipped,
                ));
            }

            if (empty($items)) {
                return $this->result(true, 'No new items to import across all enabled sources.', array(
                    'created'            => 0,
                    'imported'           => 0,
                    'skipped_duplicates' => $skipped,
                    'feed_errors'        => $errors,
                    'post_ids'           => array(),
                ));
            }

            $source_image_status = $this->summarize_source_image_status($items);

            $lines = array();
            foreach ($items as $i) {
                $source_policy = ! empty( $i['source_policy'] ) ? "\nSOURCE_POLICY: " . $i['source_policy'] : '';
                $image_policy  = ! empty( $i['image_blocked'] ) ? "\nIMAGE_POLICY: Do not reuse or sideload this source image; leave featured-image selection to a separate safe asset." : '';
                $image_status  = ! empty( $i['image_blocked'] )
                    ? 'blocked source image'
                    : ( ! empty( $i['image_url'] )
                        ? ( ! empty( $i['image_reuse_allowed'] ) ? 'approved reusable image available' : 'image requires rights review' )
                        : 'no reusable image found' );
                $lines[] = "[BEGIN_UNTRUSTED_SOURCE_ITEM]\nSOURCE: " . $i['source_label']
                    . "\nTITLE: " . $i['title']
                    . "\nLINK: "  . $i['url']
                    . "\nIMAGE_STATUS: " . $image_status
                    . $source_policy
                    . $image_policy
                    . "\nDESCRIPTION:\n" . $i['description']
                    . "\n[END_UNTRUSTED_SOURCE_ITEM]\n";
            }
            $news_data = implode("\n", $lines);

            $generated = $this->ai_client->generate($news_data);
            if (!$this->heartbeat_lock($lock_owner)) {
                return $this->result(false, 'Dispatch lost worker-lock ownership after generation and stopped before creating drafts.', array(
                    'retry_required' => true,
                    'feed_errors' => $errors,
                    'skipped_duplicates' => $skipped,
                ));
            }
            if (is_wp_error($generated)) {
                $msg = $generated->get_error_message();
                error_log('Lunara Dispatch: ' . $msg);
                return $this->result(false, $msg, array(
                    'feed_errors'        => $errors,
                    'skipped_duplicates' => $skipped,
                ));
            }

            if ($this->generation_requested_skip($generated)) {
                $this->feed_fetcher->mark_seen($items);

                return $this->result(true, sprintf(
                    'Skipped %d source item(s): no reader-worthy Journal entries passed the editorial gate.',
                    count($items)
                ), array(
                    'post_ids'           => array(),
                    'created'            => 0,
                    'imported'           => count($items),
                    'skipped_duplicates' => $skipped,
                    'feed_errors'        => $errors,
                    'post_status'        => $this->get_target_post_status(),
                    'post_status_label'  => $this->get_status_label($this->get_target_post_status()),
                    'image_blocked_sources' => count(array_filter($items, static function ($item) { return !empty($item['image_blocked']); })),
                    'source_items_with_image' => $source_image_status['source_items_with_image'],
                    'item_images_sideloaded' => 0,
                    'section_images_matched' => 0,
                    'created_with_featured_image' => 0,
                    'created_without_featured_image' => 0,
                ));
            }

            $post_type   = $this->post_builder->get_target_post_type();
            $post_status = $this->get_target_post_status();

            $source_items_with_image = $source_image_status['source_items_with_image'];
            $item_images_sideloaded = 0;
            $section_images_matched = 0;

			$created_post_ids = $this->post_builder->split_into_individual_posts(
				$generated,
				array(),
				$post_type,
				$post_status,
				array(
					'provider' => class_exists('Lunara_Dispatch_Control_Plane_Client') ? Lunara_Dispatch_Control_Plane_Client::provider() : sanitize_key(get_option('lunara_dispatch_provider', 'openai')),
					'model'    => class_exists('Lunara_Dispatch_Control_Plane_Client') ? Lunara_Dispatch_Control_Plane_Client::model_for_provider(class_exists('Lunara_Dispatch_Control_Plane_Client') ? Lunara_Dispatch_Control_Plane_Client::provider() : 'openai', '' ) : '',
					'config_version' => class_exists('Lunara_Dispatch_Control_Plane_Client') ? sanitize_text_field((string) (Lunara_Dispatch_Control_Plane_Client::runtime_config()['config_version'] ?? '')) : '',
					'prompt_version' => class_exists('Lunara_Dispatch_Control_Plane_Client') ? 'journal-' . sanitize_text_field((string) (Lunara_Dispatch_Control_Plane_Client::runtime_config()['config_version'] ?? '')) : '',
					'items'    => $items,
					'run_id'   => $this->current_run_id,
				)
			);
			$created_with_featured_image = 0;
			$created_without_featured_image = count($created_post_ids);
			$topic_duplicate_skips = method_exists($this->post_builder, 'get_last_topic_duplicate_skips')
				? $this->post_builder->get_last_topic_duplicate_skips()
				: array();
			$topic_duplicate_count = count($topic_duplicate_skips);
			$quality_gate_skips = method_exists($this->post_builder, 'get_last_quality_gate_skips')
				? $this->post_builder->get_last_quality_gate_skips()
				: array();
			$quality_gate_count = count($quality_gate_skips);
			$insertion_failures = method_exists($this->post_builder, 'get_last_insertion_failures')
				? $this->post_builder->get_last_insertion_failures()
				: array();

			if (empty($created_post_ids)) {
				if (!empty($insertion_failures)) {
					return $this->result(false, 'One or more Journal drafts could not be created; source items remain eligible for retry.', array(
						'feed_errors' => $errors,
						'skipped_duplicates' => $skipped,
						'insertion_failures' => $insertion_failures,
						'retry_required' => true,
						'created' => 0,
						'imported' => count($items),
						'post_status' => $post_status,
					));
				}
				if ($topic_duplicate_count > 0) {
					$this->feed_fetcher->mark_seen($items);

					return $this->result(true, sprintf(
						'Skipped %d generated Journal entr%s because %s overlapped recent Journal topics.',
						$topic_duplicate_count,
						1 === $topic_duplicate_count ? 'y' : 'ies',
						1 === $topic_duplicate_count ? 'it' : 'they'
					), array(
						'feed_errors'              => $errors,
						'skipped_duplicates'       => $skipped,
						'skipped_topic_duplicates' => $topic_duplicate_count,
						'topic_duplicate_skips'    => $topic_duplicate_skips,
						'skipped_quality_gate'     => $quality_gate_count,
						'quality_gate_skips'       => $quality_gate_skips,
						'created'                  => 0,
						'imported'                 => count($items),
						'image_blocked_sources'    => count(array_filter($items, static function ($item) { return !empty($item['image_blocked']); })),
						'source_items_with_image'  => $source_items_with_image,
						'item_images_sideloaded'   => $item_images_sideloaded,
						'section_images_matched'   => $section_images_matched,
						'created_with_featured_image' => 0,
						'created_without_featured_image' => 0,
						'post_status'              => $post_status,
						'post_status_label'        => $this->get_status_label($post_status),
					));
				}

				if ($quality_gate_count > 0) {
					$this->feed_fetcher->mark_seen($items);

					return $this->result(true, sprintf(
						'Skipped %d generated Journal entr%s because %s failed the editorial quality gate.',
						$quality_gate_count,
						1 === $quality_gate_count ? 'y' : 'ies',
						1 === $quality_gate_count ? 'it' : 'they'
					), array(
						'feed_errors'              => $errors,
						'skipped_duplicates'       => $skipped,
						'skipped_topic_duplicates' => $topic_duplicate_count,
						'topic_duplicate_skips'    => $topic_duplicate_skips,
						'skipped_quality_gate'     => $quality_gate_count,
						'quality_gate_skips'       => $quality_gate_skips,
						'created'                  => 0,
						'imported'                 => count($items),
						'image_blocked_sources'    => count(array_filter($items, static function ($item) { return !empty($item['image_blocked']); })),
						'source_items_with_image'  => $source_items_with_image,
						'item_images_sideloaded'   => $item_images_sideloaded,
						'section_images_matched'   => $section_images_matched,
						'created_with_featured_image' => 0,
						'created_without_featured_image' => 0,
						'post_status'              => $post_status,
						'post_status_label'        => $this->get_status_label($post_status),
					));
				}

				return $this->result(false, 'AI returned content but no publishable Journal entries passed the editorial gate.', array(
					'feed_errors'              => $errors,
					'skipped_duplicates'       => $skipped,
					'skipped_topic_duplicates' => $topic_duplicate_count,
					'topic_duplicate_skips'    => $topic_duplicate_skips,
					'skipped_quality_gate'     => $quality_gate_count,
					'quality_gate_skips'       => $quality_gate_skips,
					'created'                  => 0,
					'imported'                 => count($items),
					'image_blocked_sources'    => count(array_filter($items, static function ($item) { return !empty($item['image_blocked']); })),
					'source_items_with_image'  => $source_items_with_image,
					'item_images_sideloaded'   => $item_images_sideloaded,
					'section_images_matched'   => $section_images_matched,
					'created_with_featured_image' => 0,
					'created_without_featured_image' => 0,
					'post_status'              => $post_status,
					'post_status_label'        => $this->get_status_label($post_status),
				));
			}

            if (!$this->heartbeat_lock($lock_owner)) {
                return $this->result(false, 'Dispatch lost worker-lock ownership after draft ingest and stopped before image work.', array(
                    'retry_required' => true,
                    'post_ids' => $created_post_ids,
                    'created' => count($created_post_ids),
                    'imported' => count($items),
                    'feed_errors' => $errors,
                ));
            }

            $image_result = $this->image_handler->assign_images_to_posts($created_post_ids, $items);
            if (!$this->heartbeat_lock($lock_owner)) {
                return $this->result(false, 'Dispatch lost worker-lock ownership during image work and stopped before marking sources seen.', array(
                    'retry_required' => true,
                    'post_ids' => $created_post_ids,
                    'created' => count($created_post_ids),
                    'imported' => count($items),
                    'feed_errors' => $errors,
                ));
            }
            $item_images_sideloaded = isset($image_result['sideloaded']) ? (int) $image_result['sideloaded'] : 0;
            $section_images_matched = isset($image_result['matched']) ? (int) $image_result['matched'] : 0;
			$created_with_featured_image = $this->count_posts_with_featured_images($created_post_ids);
			$created_without_featured_image = max(0, count($created_post_ids) - $created_with_featured_image);

            if (empty($insertion_failures)) {
                $this->feed_fetcher->mark_seen($items);
            }

            return $this->result(true, sprintf(
                'Created %d %s post(s) from %d source items across %d feed(s). Featured images attached to %d/%d draft(s).',
                count($created_post_ids),
                $this->get_status_label($post_status),
                count($items),
                count(array_unique(array_column($items, 'source_label'))),
                $created_with_featured_image,
                count($created_post_ids)
            ), array(
                'post_ids'           => $created_post_ids,
				'created'            => count($created_post_ids),
				'imported'           => count($items),
				'image_blocked_sources' => count(array_filter($items, static function ($item) { return !empty($item['image_blocked']); })),
				'source_items_with_image' => $source_items_with_image,
				'item_images_sideloaded' => $item_images_sideloaded,
				'section_images_matched' => $section_images_matched,
				'created_with_featured_image' => $created_with_featured_image,
				'created_without_featured_image' => $created_without_featured_image,
				'skipped_duplicates' => $skipped,
				'skipped_topic_duplicates' => $topic_duplicate_count,
				'topic_duplicate_skips' => $topic_duplicate_skips,
				'skipped_quality_gate' => $quality_gate_count,
				'quality_gate_skips' => $quality_gate_skips,
				'insertion_failures' => $insertion_failures,
				'retry_required'     => !empty($insertion_failures),
				'feed_errors'        => $errors,
				'post_status'        => $post_status,
				'post_status_label'  => $this->get_status_label($post_status),
			));
        } finally {
            $this->release_lock($lock_owner);
        }
    }

    private function generation_requested_skip($generated) {
        return false !== stripos((string) $generated, self::SKIP_MARKER);
    }

    private function summarize_source_image_status(array $items) {
        return array(
            'source_items_with_image' => count(array_filter($items, static function ($item) {
                return empty($item['image_blocked']) && !empty($item['image_url']) && !empty($item['image_rights_verified']);
            })),
            'image_blocked_sources' => count(array_filter($items, static function ($item) {
                return !empty($item['image_blocked']);
            })),
            'source_items_without_image' => count(array_filter($items, static function ($item) {
                return empty($item['image_blocked']) && (empty($item['image_url']) || empty($item['image_rights_verified']));
            })),
        );
    }

    private function foundation_is_available() {
        return class_exists('Lunara_Journal_Control_Plane')
            && class_exists('Lunara_Dispatch_Control_Plane_Client')
            && Lunara_Dispatch_Control_Plane_Client::available();
    }

    private function foundation_error_message() {
        $runtime = class_exists('Lunara_Dispatch_Control_Plane_Client')
            ? Lunara_Dispatch_Control_Plane_Client::runtime_config()
            : array();
        return (string) ($runtime['protocol_error'] ?? 'Journal Foundation is required and its Dispatch protocol must be available.');
    }

    private function count_posts_with_featured_images(array $post_ids) {
        $count = 0;
        foreach ($post_ids as $post_id) {
            if ((int) get_post_thumbnail_id((int) $post_id) > 0) {
                $count++;
            }
        }
        return $count;
    }

    private function result($success, $message, array $extra = array()) {
        $payload = array_merge(array(
            'success' => (bool) $success,
            'message' => (string) $message,
            'run_id'  => $this->current_run_id,
        ), $extra);

        $report = array(
            'run_id'             => sanitize_text_field((string) $payload['run_id']),
            'timestamp_gmt'      => current_time('mysql', true),
            'success'            => (bool) $payload['success'],
            'message'            => (string) $payload['message'],
			'created'            => isset($payload['created']) ? (int) $payload['created'] : 0,
			'imported'           => isset($payload['imported']) ? (int) $payload['imported'] : 0,
			'post_status'        => isset($payload['post_status']) ? sanitize_key((string) $payload['post_status']) : $this->get_target_post_status(),
			'feed_errors'        => isset($payload['feed_errors']) && is_array($payload['feed_errors']) ? $payload['feed_errors'] : array(),
			'skipped_duplicates' => isset($payload['skipped_duplicates']) ? (int) $payload['skipped_duplicates'] : 0,
			'image_blocked_sources' => isset($payload['image_blocked_sources']) ? (int) $payload['image_blocked_sources'] : 0,
			'source_items_with_image' => isset($payload['source_items_with_image']) ? (int) $payload['source_items_with_image'] : 0,
			'item_images_sideloaded' => isset($payload['item_images_sideloaded']) ? (int) $payload['item_images_sideloaded'] : 0,
			'section_images_matched' => isset($payload['section_images_matched']) ? (int) $payload['section_images_matched'] : 0,
			'created_with_featured_image' => isset($payload['created_with_featured_image']) ? (int) $payload['created_with_featured_image'] : 0,
			'created_without_featured_image' => isset($payload['created_without_featured_image']) ? (int) $payload['created_without_featured_image'] : 0,
			'skipped_topic_duplicates' => isset($payload['skipped_topic_duplicates']) ? (int) $payload['skipped_topic_duplicates'] : 0,
			'topic_duplicate_skips' => isset($payload['topic_duplicate_skips']) && is_array($payload['topic_duplicate_skips']) ? $payload['topic_duplicate_skips'] : array(),
			'skipped_quality_gate' => isset($payload['skipped_quality_gate']) ? (int) $payload['skipped_quality_gate'] : 0,
			'quality_gate_skips' => isset($payload['quality_gate_skips']) && is_array($payload['quality_gate_skips']) ? $payload['quality_gate_skips'] : array(),
			'insertion_failures' => isset($payload['insertion_failures']) && is_array($payload['insertion_failures']) ? $payload['insertion_failures'] : array(),
			'retry_required' => !empty($payload['retry_required']),
		);

        update_option(self::REPORT_OPTION, $report, false);
        $history = get_option(self::HISTORY_OPTION, array());
        if (!is_array($history)) {
            $history = array();
        }
        array_unshift($history, $report);
        update_option(self::HISTORY_OPTION, array_slice($history, 0, self::HISTORY_LIMIT), false);

        return $payload;
    }
}
