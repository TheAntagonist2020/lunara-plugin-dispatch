<?php
/**
 * Gutenberg blocks for the Lunara Journal surfaces.
 *
 * @package Lunara_Dispatch
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lunara_Dispatch_Blocks {

    public static function init() {
        add_filter('block_categories_all', array(__CLASS__, 'register_category'));
        add_action('init', array(__CLASS__, 'register_blocks'));
    }

    public static function register_category($categories) {
        foreach ($categories as $category) {
            if (isset($category['slug']) && $category['slug'] === 'lunara') {
                return $categories;
            }
        }

        array_unshift($categories, array(
            'slug'  => 'lunara',
            'title' => __('Lunara', 'lunara-dispatch'),
            'icon'  => 'format-aside',
        ));

        return $categories;
    }

    private static function register_style() {
        wp_register_style(
            'lunara-dispatch-blocks',
            LUNARA_DISPATCH_URL . 'assets/css/lunara-dispatch-blocks.css',
            array(),
            LUNARA_DISPATCH_VERSION
        );
    }

    public static function register_blocks() {
        self::register_style();
        wp_register_script(
            'lunara-dispatch-blocks',
            LUNARA_DISPATCH_URL . 'assets/js/lunara-dispatch-blocks.js',
            array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render'),
            LUNARA_DISPATCH_VERSION,
            true
        );

        $common = array(
            'category'      => 'lunara',
            'editor_script' => 'lunara-dispatch-blocks',
            'editor_style'  => 'lunara-dispatch-blocks',
            'supports'      => array(
                'align'    => array('wide', 'full'),
                'anchor'   => true,
                'html'     => false,
                'inserter' => true,
            ),
        );

        register_block_type('lunara-dispatch/journal-feed', array_merge($common, array(
            'title'           => __('Lunara Journal Feed', 'lunara-dispatch'),
            'description'     => __('Display Journal entries as cards, list items, or a compact rail.', 'lunara-dispatch'),
            'attributes'      => self::feed_attributes(),
            'render_callback' => array(__CLASS__, 'render_journal_feed'),
        )));

        register_block_type('lunara-dispatch/journal-spotlight', array_merge($common, array(
            'title'           => __('Lunara Journal Spotlight', 'lunara-dispatch'),
            'description'     => __('Feature the newest Journal story with supporting entries.', 'lunara-dispatch'),
            'attributes'      => self::feed_attributes(array('count' => 4, 'layout' => 'spotlight')),
            'render_callback' => array(__CLASS__, 'render_journal_spotlight'),
        )));

        register_block_type('lunara-dispatch/journal-lanes', array_merge($common, array(
            'title'           => __('Lunara Journal Lanes', 'lunara-dispatch'),
            'description'     => __('Display Journal type links for the many forms the Journal can take.', 'lunara-dispatch'),
            'attributes'      => array(
                'showCounts' => array('type' => 'boolean', 'default' => true),
                'hideEmpty'   => array('type' => 'boolean', 'default' => false),
            ),
            'render_callback' => array(__CLASS__, 'render_journal_lanes'),
        )));
    }

    private static function feed_attributes($overrides = array()) {
        return array_merge(array(
            'count'          => array('type' => 'number', 'default' => 6),
            'layout'         => array('type' => 'string', 'default' => 'grid'),
            'journalType'    => array('type' => 'string', 'default' => ''),
            'showImage'      => array('type' => 'boolean', 'default' => true),
            'showExcerpt'    => array('type' => 'boolean', 'default' => true),
            'showDate'       => array('type' => 'boolean', 'default' => true),
            'showType'       => array('type' => 'boolean', 'default' => true),
            'excludeArchive' => array('type' => 'boolean', 'default' => true),
        ), $overrides);
    }

    private static function target_post_type() {
        return post_type_exists('journal') ? 'journal' : 'post';
    }

    private static function query_posts($attributes) {
        $post_type = self::target_post_type();
        $count = isset($attributes['count']) ? max(1, min(24, (int) $attributes['count'])) : 6;

        $args = array(
            'post_type'              => $post_type,
            'post_status'            => 'publish',
            'posts_per_page'         => $count,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => taxonomy_exists('journal_type'),
        );

        if (taxonomy_exists('journal_type')) {
            $tax_query = array();
            $journal_type = isset($attributes['journalType']) ? sanitize_title($attributes['journalType']) : '';
            if ($journal_type !== '') {
                $tax_query[] = array(
                    'taxonomy' => 'journal_type',
                    'field'    => 'slug',
                    'terms'    => $journal_type,
                );
            }

            if (!empty($attributes['excludeArchive'])) {
                $archive = get_term_by('slug', 'archive', 'journal_type');
                if ($archive && !is_wp_error($archive)) {
                    $tax_query[] = array(
                        'taxonomy' => 'journal_type',
                        'field'    => 'term_id',
                        'terms'    => array((int) $archive->term_id),
                        'operator' => 'NOT IN',
                    );
                }
            }

            if (!empty($tax_query)) {
                $args['tax_query'] = $tax_query;
            }
        }

        return new WP_Query($args);
    }

    public static function render_journal_feed($attributes) {
        self::enqueue_public_style();
        $layout = isset($attributes['layout']) ? sanitize_key($attributes['layout']) : 'grid';
        if (!in_array($layout, array('grid', 'list', 'rail'), true)) {
            $layout = 'grid';
        }

        $query = self::query_posts($attributes);
        if (!$query->have_posts()) {
            return '<p class="lunara-journal-empty">' . esc_html__('No Journal entries found yet.', 'lunara-dispatch') . '</p>';
        }

        ob_start();
        echo '<div class="lunara-journal-block lunara-journal-block--' . esc_attr($layout) . '">';
        while ($query->have_posts()) {
            $query->the_post();
            self::render_card($attributes, $layout);
        }
        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }

    public static function render_journal_spotlight($attributes) {
        self::enqueue_public_style();
        $query = self::query_posts($attributes);
        if (!$query->have_posts()) {
            return '<p class="lunara-journal-empty">' . esc_html__('No Journal entries found yet.', 'lunara-dispatch') . '</p>';
        }

        ob_start();
        echo '<div class="lunara-journal-spotlight">';
        $index = 0;
        while ($query->have_posts()) {
            $query->the_post();
            self::render_card($attributes, $index === 0 ? 'hero' : 'list');
            $index++;
        }
        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }

    private static function render_card($attributes, $layout) {
        $show_image = !empty($attributes['showImage']);
        $show_excerpt = !empty($attributes['showExcerpt']);
        $show_date = !empty($attributes['showDate']);
        $show_type = !empty($attributes['showType']) && taxonomy_exists('journal_type');
        $terms = $show_type ? get_the_terms(get_the_ID(), 'journal_type') : array();
        ?>
        <article class="lunara-journal-card lunara-journal-card--<?php echo esc_attr($layout); ?>">
            <?php if ($show_image && has_post_thumbnail()) : ?>
                <a class="lunara-journal-card__image" href="<?php the_permalink(); ?>">
                    <?php
                    $image_attributes = 'hero' === $layout
                        ? array('loading' => 'eager', 'fetchpriority' => 'high')
                        : array('loading' => 'lazy', 'fetchpriority' => 'auto');
                    the_post_thumbnail($layout === 'hero' ? 'large' : 'medium_large', $image_attributes);
                    ?>
                </a>
            <?php endif; ?>
            <div class="lunara-journal-card__body">
                <div class="lunara-journal-card__meta">
                    <?php if ($show_type && !empty($terms) && !is_wp_error($terms)) : ?>
                        <span><?php echo esc_html($terms[0]->name); ?></span>
                    <?php endif; ?>
                    <?php if ($show_date) : ?>
                        <time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('M j, Y')); ?></time>
                    <?php endif; ?>
                </div>
                <h3 class="lunara-journal-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                <?php if ($show_excerpt) : ?>
                    <div class="lunara-journal-card__excerpt"><?php echo wp_kses_post(wpautop(wp_trim_words(get_the_excerpt(), $layout === 'hero' ? 34 : 22))); ?></div>
                <?php endif; ?>
            </div>
        </article>
        <?php
    }

    public static function render_journal_lanes($attributes) {
        self::enqueue_public_style();
        if (!taxonomy_exists('journal_type')) {
            return '';
        }

        $terms = get_terms(array(
            'taxonomy'   => 'journal_type',
            'hide_empty' => !empty($attributes['hideEmpty']),
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));

        if (empty($terms) || is_wp_error($terms)) {
            return '';
        }

        ob_start();
        echo '<nav class="lunara-journal-lanes" aria-label="' . esc_attr__('Lunara Journal lanes', 'lunara-dispatch') . '">';
        foreach ($terms as $term) {
            if ($term->slug === 'archive') {
                continue;
            }
            $url = get_term_link($term);
            if (is_wp_error($url)) {
                continue;
            }
            echo '<a class="lunara-journal-lane" href="' . esc_url($url) . '">';
            echo '<span>' . esc_html($term->name) . '</span>';
            if (!empty($attributes['showCounts'])) {
                echo '<em>' . esc_html((string) (int) $term->count) . '</em>';
            }
            echo '</a>';
        }
        echo '</nav>';
        return ob_get_clean();
    }

    private static function enqueue_public_style() {
        if (!wp_style_is('lunara-dispatch-blocks', 'registered')) {
            self::register_style();
        }
        wp_enqueue_style('lunara-dispatch-blocks');
    }
}

Lunara_Dispatch_Blocks::init();
