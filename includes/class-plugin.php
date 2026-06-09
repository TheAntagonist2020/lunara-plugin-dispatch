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
    const LOCK_KEY  = 'lunara_dispatch_running';
    const REPORT_OPTION = 'lunara_dispatch_last_run_report';
    const SKIP_MARKER = 'LUNARA_SKIP';

    /** @var Lunara_Dispatch_Plugin */
    private static $instance = null;

    /** @var Lunara_Dispatch_Feed_Fetcher  */ public $feed_fetcher;
    /** @var Lunara_Dispatch_AI_Client     */ public $ai_client;
    /** @var Lunara_Dispatch_Image_Handler */ public $image_handler;
    /** @var Lunara_Dispatch_Post_Builder  */ public $post_builder;
    /** @var Lunara_Dispatch_Admin         */ public $admin;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->feed_fetcher  = new Lunara_Dispatch_Feed_Fetcher();
        $this->ai_client     = new Lunara_Dispatch_AI_Client();
        $this->image_handler = new Lunara_Dispatch_Image_Handler();
        $this->post_builder  = new Lunara_Dispatch_Post_Builder();
        $this->admin         = new Lunara_Dispatch_Admin($this);

        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action(self::CRON_HOOK, array($this, 'run_scheduled'));
        add_action('update_option_lunara_dispatch_schedule', array($this, 'reschedule_on_frequency_change'), 10, 2);
    }

    public static function on_activate() {
        Lunara_Dispatch_Sources::install_defaults_if_empty();
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $recurrence = self::recurrence_from_setting(get_option('lunara_dispatch_schedule', 'daily'));
            wp_schedule_event(strtotime('+1 hour'), $recurrence, self::CRON_HOOK);
        }
    }

    public static function on_deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
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
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_event(strtotime('+1 hour'), self::recurrence_from_setting($new_value), self::CRON_HOOK);
    }

    public function run_scheduled() {
        $this->run(false);
    }

    public function get_target_post_status() {
        $post_status = sanitize_key((string) get_option('lunara_dispatch_post_status', 'draft'));
        $allowed = array('draft', 'pending', 'publish', 'private');
        if (!in_array($post_status, $allowed, true)) {
            return 'draft';
        }
        return $post_status;
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

    /**
     * Aggregate, generate, split, and save. Returns a structured result.
     *
     * @param bool $force Bypass the enabled toggle for manual runs.
     * @return array
     */
    public function run($force = false) {
        $enabled = (int) get_option('lunara_dispatch_enabled', 0);
        if (!$force && !$enabled) {
            return $this->result(false, 'Automation is disabled. Enable it in settings or use Run Now.');
        }

        if (get_transient(self::LOCK_KEY)) {
            return $this->result(false, 'Another dispatch run is already in progress. Try again in a minute.');
        }
        set_transient(self::LOCK_KEY, 1, 5 * MINUTE_IN_SECONDS);

        try {
            $fetched = $this->feed_fetcher->fetch_all();
            $items   = $fetched['items'];
            $skipped = $fetched['skipped_duplicates'];
            $errors  = $fetched['errors'];

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
                    : ( ! empty( $i['image_url'] ) ? 'reusable image available' : 'no reusable image found' );
                $lines[] = "SOURCE: " . $i['source_label']
                    . "\nTITLE: " . $i['title']
                    . "\nLINK: "  . $i['url']
                    . "\nIMAGE_STATUS: " . $image_status
                    . $source_policy
                    . $image_policy
                    . "\n\n"      . $i['description']
                    . "\n\n---\n";
            }
            $news_data = implode("\n", $lines);

            $generated = $this->ai_client->generate($news_data);
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

            $item_image_ids = $this->image_handler->sideload_for_items($items);
            $source_items_with_image = $source_image_status['source_items_with_image'];
            $item_images_sideloaded = count(array_filter($item_image_ids, static function ($attachment_id) {
                return (int) $attachment_id > 0;
            }));
            $section_image_map = $this->image_handler->match_sections_to_items(
                $generated, $items, $item_image_ids
            );
            $section_images_matched = count(array_filter($section_image_map, static function ($attachment_id) {
                return (int) $attachment_id > 0;
            }));

			$created_post_ids = $this->post_builder->split_into_individual_posts(
				$generated, $section_image_map, $post_type, $post_status
			);
			$created_with_featured_image = $this->count_posts_with_featured_images($created_post_ids);
			$created_without_featured_image = max(0, count($created_post_ids) - $created_with_featured_image);
			$topic_duplicate_skips = method_exists($this->post_builder, 'get_last_topic_duplicate_skips')
				? $this->post_builder->get_last_topic_duplicate_skips()
				: array();
			$topic_duplicate_count = count($topic_duplicate_skips);
			$quality_gate_skips = method_exists($this->post_builder, 'get_last_quality_gate_skips')
				? $this->post_builder->get_last_quality_gate_skips()
				: array();
			$quality_gate_count = count($quality_gate_skips);

			if (empty($created_post_ids)) {
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

            $this->feed_fetcher->mark_seen($items);

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
				'feed_errors'        => $errors,
				'post_status'        => $post_status,
				'post_status_label'  => $this->get_status_label($post_status),
			));
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    private function generation_requested_skip($generated) {
        return false !== stripos((string) $generated, self::SKIP_MARKER);
    }

    private function summarize_source_image_status(array $items) {
        return array(
            'source_items_with_image' => count(array_filter($items, static function ($item) {
                return empty($item['image_blocked']) && !empty($item['image_url']);
            })),
            'image_blocked_sources' => count(array_filter($items, static function ($item) {
                return !empty($item['image_blocked']);
            })),
            'source_items_without_image' => count(array_filter($items, static function ($item) {
                return empty($item['image_blocked']) && empty($item['image_url']);
            })),
        );
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
        ), $extra);

        update_option(self::REPORT_OPTION, array(
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
		), false);

        return $payload;
    }
}
