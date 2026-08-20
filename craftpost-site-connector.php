<?php
/**
 * Plugin Name: CraftPost Site Connector
 * Plugin URI: https://craftpost.net/
 * Description: Secure receiver and connector plugin for CraftPost content automation service.
 * Version: 1.4.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: CraftPost
 * Author URI: https://craftpost.net
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: craftpost-site-connector
 */

if (!defined('ABSPATH')) {
    exit;
}

// Prevent conflicts when the legacy plugin directory is still active.
if (class_exists('AI_Craft_Post_Plugin', false)) {
    $legacy_class = new ReflectionClass('AI_Craft_Post_Plugin');
    if (realpath($legacy_class->getFileName()) !== realpath(__FILE__)) {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $legacy_plugin = plugin_basename($legacy_class->getFileName());
        $network_wide = is_multisite() && is_plugin_active_for_network($legacy_plugin);
        deactivate_plugins($legacy_plugin, false, $network_wide);
        set_transient('craftpost_site_connector_legacy_deactivated', true, MINUTE_IN_SECONDS);
        return;
    }
}

// Confirm that the legacy plugin was automatically deactivated during migration.
add_action('admin_notices', function () {
    if (!get_transient('craftpost_site_connector_legacy_deactivated')) {
        return;
    }

    delete_transient('craftpost_site_connector_legacy_deactivated');
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('CraftPost Site Connector was activated and the previous AI Craft Post version was automatically deactivated. Existing settings and content were preserved. You can now delete the previous plugin.', 'craftpost-site-connector') . '</p></div>';
});

define('AI_CRAFT_POST_VERSION', '1.4.1');
define('AI_CRAFT_POST_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AI_CRAFT_POST_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('AI_CRAFT_POST_SITE_KEY_OPTION', 'ai_craft_post_site_key');
define('AI_CRAFT_POST_SEO_WRITE_TITLE_OPTION', 'ai_craft_post_seo_write_title');
define('AI_CRAFT_POST_SEO_WRITE_DESCRIPTION_OPTION', 'ai_craft_post_seo_write_description');
define('AI_CRAFT_POST_SEO_WRITE_KEYWORD_OPTION', 'ai_craft_post_seo_write_keyword');
define('AI_CRAFT_POST_FAQ_SCHEMA_ENABLED_OPTION', 'ai_craft_post_faq_schema_enabled');
define('AI_CRAFT_POST_FAQ_METABOX_ENABLED_OPTION', 'ai_craft_post_faq_metabox_enabled');
define('AI_CRAFT_POST_FAQ_DETAILS_ENABLED_OPTION', 'ai_craft_post_faq_details_enabled');
define('AI_CRAFT_POST_FAQ_CSS_OPTION', 'ai_craft_post_faq_css');
define('AI_CRAFT_POST_FAQ_SCHEMA_META_KEY', 'ai_craft_post_faq_schema_items');
define('AI_CRAFT_POST_FAQ_SCHEMA_TITLE_META_KEY', 'ai_craft_post_faq_schema_title');
define('AI_CRAFT_POST_REST_NAMESPACE', 'ai-craft-post/v1');
define('AI_CRAFT_POST_DASHBOARD_URL', 'https://craftpost.net/my-craft-panel/');
define('AI_CRAFT_POST_DEFAULT_FAQ_CSS', <<<'CSS'
.ai-craft-post-faq h2{margin:0 0 18px;line-height:1.25;color:inherit}
.ai-craft-post-faq-item{margin:0;padding:18px 0;border-top:1px solid rgba(0,0,0,.1)}
.ai-craft-post-faq-item:first-of-type{border-top:0;padding-top:0}
.ai-craft-post-faq-item:last-child{padding-bottom:0}
.ai-craft-post-faq-question{margin:0;line-height:1.45;font-weight:700;color:inherit}
.ai-craft-post-faq-answer{margin-top:10px;line-height:1.7;color:inherit;opacity:.88}
.ai-craft-post-faq-answer>*:first-child{margin-top:0}
.ai-craft-post-faq-answer>*:last-child{margin-bottom:0}
.ai-craft-post-faq summary.ai-craft-post-faq-question{cursor:pointer;list-style:none;position:relative;padding-right:32px}
.ai-craft-post-faq summary.ai-craft-post-faq-question::-webkit-details-marker{display:none}
.ai-craft-post-faq summary.ai-craft-post-faq-question:after{content:"+";position:absolute;right:0;top:50%;width:24px;height:24px;margin-top:-12px;border:1px solid rgba(0,0,0,.18);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:400;line-height:1;opacity:.72}
.ai-craft-post-faq details[open]>summary.ai-craft-post-faq-question:after{content:"-";line-height:2}
@media (max-width:600px){.ai-craft-post-faq{padding:18px 16px;border-radius:12px}.ai-craft-post-faq-item{padding:15px 0}.ai-craft-post-faq summary.ai-craft-post-faq-question{padding-right:30px}}
CSS
);

/**
 * Main CraftPost Site Connector plugin class.
 */
class AI_Craft_Post_Plugin
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->load_dependencies();

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'register_privacy_policy_content'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_action_links'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('add_meta_boxes', array($this, 'add_faq_schema_meta_box'));
        add_action('save_post', array($this, 'save_faq_schema_meta_box'));
        add_action('wp_head', array($this, 'render_faq_schema_json_ld'));
        add_filter('the_content', array($this, 'append_faq_schema_content'));
    }

    /**
     * Load required plugin classes.
     */
    public function load_dependencies()
    {
        require_once AI_CRAFT_POST_PLUGIN_PATH . 'includes/class-image-handler.php';
        require_once AI_CRAFT_POST_PLUGIN_PATH . 'includes/class-webhook-handler.php';
        require_once AI_CRAFT_POST_PLUGIN_PATH . 'includes/class-site-info-handler.php';
    }

    /**
     * Enqueue admin assets.
     */
    public function enqueue_admin_scripts($hook)
    {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }

        if (!(bool) get_option(AI_CRAFT_POST_FAQ_METABOX_ENABLED_OPTION, false)) {
            return;
        }

        wp_enqueue_script(
            'craftpost-admin-faq-metabox',
            AI_CRAFT_POST_PLUGIN_URL . 'assets/js/admin-faq-metabox.js',
            array(),
            AI_CRAFT_POST_VERSION,
            true
        );

        wp_localize_script('craftpost-admin-faq-metabox', 'craftpostFaqI18n', array(
            'question'    => __('Question', 'craftpost-site-connector'),
            'answer'      => __('Answer', 'craftpost-site-connector'),
            'remove'      => __('Remove', 'craftpost-site-connector'),
            'notFound'    => __('FAQ block was not found in content.', 'craftpost-site-connector'),
            'movedSuffix' => __('question(s) moved. Save or update the post to keep changes.', 'craftpost-site-connector'),
        ));
    }

    /**
     * Enqueue frontend scripts and styles.
     */
    public function enqueue_frontend_scripts()
    {
        wp_register_style(
            'craftpost-faq-style',
            AI_CRAFT_POST_PLUGIN_URL . 'assets/css/faq.css',
            array(),
            AI_CRAFT_POST_VERSION
        );

        $faq_css = (string) get_option(AI_CRAFT_POST_FAQ_CSS_OPTION, AI_CRAFT_POST_DEFAULT_FAQ_CSS);
        if ($faq_css !== '') {
            wp_add_inline_style('craftpost-faq-style', wp_strip_all_tags($faq_css));
        }

        if (is_singular()) {
            wp_enqueue_style('craftpost-faq-style');
        }
    }

    /**
     * Add the plugin settings page.
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'tools.php',
            __('CraftPost Site Connector', 'craftpost-site-connector'),
            __('CraftPost Site Connector', 'craftpost-site-connector'),
            'manage_options',
            'craftpost-site-connector',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Add direct settings and key links on the Plugins screen.
     */
    public function add_plugin_action_links($links)
    {
        $settings_link = '<a href="' . esc_url(admin_url('tools.php?page=ai-craft-post')) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Settings', 'craftpost-site-connector') . '</a>';
        $key_link = '<a href="' . esc_url(AI_CRAFT_POST_DASHBOARD_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Get key', 'craftpost-site-connector') . '</a>';

        array_unshift($links, $settings_link, $key_link);

        return $links;
    }

    /**
     * Register plugin settings.
     */
    public function register_settings()
    {
        register_setting('ai_craft_post_settings', AI_CRAFT_POST_SITE_KEY_OPTION, array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_site_key'),
            'default' => '',
        ));
        register_setting('ai_craft_post_settings', AI_CRAFT_POST_SEO_WRITE_TITLE_OPTION, array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => true,
        ));
        register_setting('ai_craft_post_settings', AI_CRAFT_POST_SEO_WRITE_DESCRIPTION_OPTION, array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => true,
        ));
        register_setting('ai_craft_post_settings', AI_CRAFT_POST_SEO_WRITE_KEYWORD_OPTION, array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => true,
        ));
        register_setting('ai_craft_post_settings', AI_CRAFT_POST_FAQ_SCHEMA_ENABLED_OPTION, array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => false,
        ));
        register_setting('ai_craft_post_settings', AI_CRAFT_POST_FAQ_METABOX_ENABLED_OPTION, array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => false,
        ));
        register_setting('ai_craft_post_settings', AI_CRAFT_POST_FAQ_DETAILS_ENABLED_OPTION, array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => false,
        ));
        register_setting('ai_craft_post_settings', AI_CRAFT_POST_FAQ_CSS_OPTION, array(
            'type' => 'string',
            'sanitize_callback' => 'wp_strip_all_tags',
            'default' => AI_CRAFT_POST_DEFAULT_FAQ_CSS,
        ));
    }

    /**
     * Add suggested CraftPost disclosure text to the WordPress privacy guide.
     */
    public function register_privacy_policy_content()
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $policy_text = '<p class="privacy-policy-tutorial">' . wp_kses_post(__('CraftPost Site Connector connects this website to the external CraftPost service after an administrator configures a site key. CraftPost may retrieve site configuration, WordPress user account identifiers and roles, post metadata, post content, taxonomy data, media settings, and supported plugin information to create, update, translate, or refresh website content. CraftPost may send generated content and remote image URLs back to this website. Downloading a remote image also sends a request from the website server to the host of that image. The site key is stored in the WordPress options table. Review the <a href="https://craftpost.net/privacy.html" target="_blank" rel="noopener noreferrer">CraftPost Privacy Policy</a> and <a href="https://craftpost.net/terms.html" target="_blank" rel="noopener noreferrer">Terms of Service</a> for details about processing and retention.', 'craftpost-site-connector')) . '</p>';

        wp_add_privacy_policy_content(
            __('CraftPost Site Connector', 'craftpost-site-connector'),
            wp_kses_post(wpautop($policy_text, false))
        );
    }

    /**
     * Sanitize the site key without clearing it on empty form submits.
     */
    public function sanitize_site_key($value)
    {
        $value = sanitize_text_field($value);
        if ($value === '') {
            return (string) get_option(AI_CRAFT_POST_SITE_KEY_OPTION, '');
        }

        return $value;
    }

    /**
     * Store checkbox options as booleans.
     */
    public function sanitize_checkbox($value)
    {
        return !empty($value);
    }

    /**
     * Add the FAQ schema metabox to public post types.
     */
    public function add_faq_schema_meta_box()
    {
        if (!(bool) get_option(AI_CRAFT_POST_FAQ_METABOX_ENABLED_OPTION, false)) {
            return;
        }

        $post_types = get_post_types(array('public' => true), 'names');
        foreach ($post_types as $post_type) {
            if (in_array($post_type, array('attachment'), true)) {
                continue;
            }

            add_meta_box(
                'ai-craft-post-faq-schema',
                __('FAQ schema.org', 'craftpost-site-connector'),
                array($this, 'render_faq_schema_meta_box'),
                $post_type,
                'normal',
                'default'
            );
        }
    }

    /**
     * Render editable FAQ schema questions for the post.
     */
    public function render_faq_schema_meta_box($post)
    {
        $items = get_post_meta($post->ID, AI_CRAFT_POST_FAQ_SCHEMA_META_KEY, true);
        if (!is_array($items)) {
            $items = array();
        }
        $title = (string) get_post_meta($post->ID, AI_CRAFT_POST_FAQ_SCHEMA_TITLE_META_KEY, true);
        if ($title === '') {
            $title = __('FAQ', 'craftpost-site-connector');
        }

        wp_nonce_field('ai_craft_post_save_faq_schema', 'ai_craft_post_faq_schema_nonce');
        ?>
        <div id="ai-craft-post-faq-schema-box">
            <p class="description">
                <?php echo esc_html__('Questions shown after the post content.', 'craftpost-site-connector'); ?>
                <?php echo esc_html__('The FAQPage schema enabled in', 'craftpost-site-connector'); ?>
                <a href="<?php echo esc_url(admin_url('tools.php?page=ai-craft-post')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('CraftPost Site Connector settings', 'craftpost-site-connector'); ?></a>.
            </p>
            <p>
                <label>
                    <strong><?php echo esc_html__('FAQ heading', 'craftpost-site-connector'); ?></strong>
                    <input type="text" id="ai-craft-post-faq-title" name="ai_craft_post_faq_schema_title" value="<?php echo esc_attr($title); ?>" class="widefat" />
                </label>
            </p>
            <div id="ai-craft-post-faq-schema-items">
                <?php foreach ($items as $index => $item) : ?>
                    <?php
                    $question = is_array($item) ? (string) ($item['question'] ?? '') : '';
                    $answer = is_array($item) ? (string) ($item['answer'] ?? '') : '';
                    ?>
                    <div class="ai-craft-post-faq-schema-item" style="margin: 0 0 14px; padding: 12px; border: 1px solid #dcdcde; background: #fff;">
                        <p style="margin-top: 0;">
                            <label>
                                <strong><?php echo esc_html__('Question', 'craftpost-site-connector'); ?></strong>
                                <input type="text" name="ai_craft_post_faq_schema[<?php echo esc_attr($index); ?>][question]" value="<?php echo esc_attr($question); ?>" class="widefat" />
                            </label>
                        </p>
                        <p>
                            <label>
                                <strong><?php echo esc_html__('Answer', 'craftpost-site-connector'); ?></strong>
                                <textarea name="ai_craft_post_faq_schema[<?php echo esc_attr($index); ?>][answer]" rows="4" class="widefat"><?php echo esc_textarea($answer); ?></textarea>
                            </label>
                        </p>
                        <button type="button" class="button ai-craft-post-remove-faq-item"><?php echo esc_html__('Remove', 'craftpost-site-connector'); ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="ai-craft-post-add-faq-item"><?php echo esc_html__('Add question', 'craftpost-site-connector'); ?></button>
            <button type="button" class="button" id="ai-craft-post-move-faq-from-content"><?php echo esc_html__('Move FAQ from content', 'craftpost-site-connector'); ?></button>
            <span id="ai-craft-post-faq-status" style="margin-left: 8px;"></span>
        </div>
        <?php
    }

    /**
     * Save FAQ schema questions from the post editor.
     */
    public function save_faq_schema_meta_box($post_id)
    {
        if (!isset($_POST['ai_craft_post_faq_schema_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ai_craft_post_faq_schema_nonce'])), 'ai_craft_post_save_faq_schema')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $title = isset($_POST['ai_craft_post_faq_schema_title']) ? sanitize_text_field(wp_unslash($_POST['ai_craft_post_faq_schema_title'])) : '';
        $items = array();
        $raw_items = isset($_POST['ai_craft_post_faq_schema']) && is_array($_POST['ai_craft_post_faq_schema'])
            ? map_deep(wp_unslash($_POST['ai_craft_post_faq_schema']), 'wp_kses_post')
            : array();
        if (is_array($raw_items)) {
            foreach ($raw_items as $raw_item) {
                if (!is_array($raw_item)) {
                    continue;
                }

                $question = sanitize_text_field((string) ($raw_item['question'] ?? ''));
                $answer = wp_kses_post((string) ($raw_item['answer'] ?? ''));
                if ($question === '' || trim(wp_strip_all_tags($answer)) === '') {
                    continue;
                }

                $items[] = array(
                    'question' => $question,
                    'answer' => $answer,
                );
            }
        }

        if (empty($items)) {
            delete_post_meta($post_id, AI_CRAFT_POST_FAQ_SCHEMA_META_KEY);
            delete_post_meta($post_id, AI_CRAFT_POST_FAQ_SCHEMA_TITLE_META_KEY);
            return;
        }

        if ($title === '') {
            $title = __('FAQ', 'craftpost-site-connector');
        }

        update_post_meta($post_id, AI_CRAFT_POST_FAQ_SCHEMA_META_KEY, $items);
        update_post_meta($post_id, AI_CRAFT_POST_FAQ_SCHEMA_TITLE_META_KEY, $title);
    }

    /**
     * Output FAQPage JSON-LD for posts with saved FAQ schema items.
     */
    public function render_faq_schema_json_ld()
    {
        if (!(bool) get_option(AI_CRAFT_POST_FAQ_SCHEMA_ENABLED_OPTION, false)) {
            return;
        }

        if (!is_singular()) {
            return;
        }

        $post_id = get_queried_object_id();
        if (!$post_id) {
            return;
        }

        $items = get_post_meta($post_id, AI_CRAFT_POST_FAQ_SCHEMA_META_KEY, true);
        if (empty($items) || !is_array($items)) {
            return;
        }

        $entities = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = trim(wp_strip_all_tags((string) ($item['question'] ?? '')));
            $answer = trim(wp_strip_all_tags((string) ($item['answer'] ?? '')));
            if ($question === '' || $answer === '') {
                continue;
            }

            $entities[] = array(
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text' => $answer,
                ),
            );
        }

        if (empty($entities)) {
            return;
        }

        echo "\n" . '<script type="application/ld+json">' . wp_json_encode(array(
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    /**
     * Append editable FAQ schema questions after the post content.
     */
    public function append_faq_schema_content($content)
    {
        if (!(bool) get_option(AI_CRAFT_POST_FAQ_METABOX_ENABLED_OPTION, false)) {
            return $content;
        }

        if (is_admin() || is_feed() || !is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $content;
        }

        $items = get_post_meta($post_id, AI_CRAFT_POST_FAQ_SCHEMA_META_KEY, true);
        if (empty($items) || !is_array($items)) {
            return $content;
        }

        $output = '';
        $item_index = 0;
        $use_details = (bool) get_option(AI_CRAFT_POST_FAQ_DETAILS_ENABLED_OPTION, false);
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = trim(wp_strip_all_tags((string) ($item['question'] ?? '')));
            $answer = trim((string) ($item['answer'] ?? ''));
            if ($question === '' || trim(wp_strip_all_tags($answer)) === '') {
                continue;
            }

            if ($use_details) {
                $open_attr = $item_index === 0 ? ' open' : '';
                $output .= '<details class="ai-craft-post-faq-item"' . $open_attr . '>';
                $output .= '<summary class="ai-craft-post-faq-question">' . esc_html($question) . '</summary>';
                $output .= '<div class="ai-craft-post-faq-answer">' . wpautop(wp_kses_post($answer)) . '</div>';
                $output .= '</details>';
            } else {
                $output .= '<div class="ai-craft-post-faq-item">';
                $output .= '<h3 class="ai-craft-post-faq-question">' . esc_html($question) . '</h3>';
                $output .= '<div class="ai-craft-post-faq-answer">' . wpautop(wp_kses_post($answer)) . '</div>';
                $output .= '</div>';
            }

            $item_index++;
        }

        if ($output === '') {
            return $content;
        }

        $title = (string) get_post_meta($post_id, AI_CRAFT_POST_FAQ_SCHEMA_TITLE_META_KEY, true);
        if ($title === '') {
            $title = __('FAQ', 'craftpost-site-connector');
        }

        $faq_css = (string) get_option(AI_CRAFT_POST_FAQ_CSS_OPTION, AI_CRAFT_POST_DEFAULT_FAQ_CSS);
        if ($faq_css !== '' && !wp_style_is('craftpost-faq-style', 'enqueued')) {
            wp_enqueue_style('craftpost-faq-style');
        }

        return $content . '<section class="ai-craft-post-faq" aria-label="' . esc_attr($title) . '"><h2>' . esc_html($title) . '</h2>' . $output . '</section>';
    }

    /**
     * Register CraftPost Site Connector REST routes.
     */
    public function register_rest_routes()
    {
        register_rest_route(AI_CRAFT_POST_REST_NAMESPACE, '/webhook/site-info', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_site_info_webhook'),
            'permission_callback' => array($this, 'verify_signed_request'),
        ));

        register_rest_route(AI_CRAFT_POST_REST_NAMESPACE, '/webhook/article', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_article_webhook'),
            'permission_callback' => array($this, 'verify_signed_request'),
        ));

        register_rest_route(AI_CRAFT_POST_REST_NAMESPACE, '/webhook/posts-range', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_posts_range_webhook'),
            'permission_callback' => array($this, 'verify_signed_request'),
        ));

        register_rest_route(AI_CRAFT_POST_REST_NAMESPACE, '/webhook/post', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_post_webhook'),
            'permission_callback' => array($this, 'verify_signed_request'),
        ));

        register_rest_route(AI_CRAFT_POST_REST_NAMESPACE, '/webhook/append-images', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_append_images_webhook'),
            'permission_callback' => array($this, 'verify_signed_request'),
        ));

        register_rest_route(AI_CRAFT_POST_REST_NAMESPACE, '/webhook/article-refresh', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_article_refresh_webhook'),
            'permission_callback' => array($this, 'verify_signed_request'),
        ));
    }

    /**
     * Verify webhook requests from CraftPost Site Connector.
     */
    public function verify_signed_request($request)
    {
        $site_key = (string) get_option(AI_CRAFT_POST_SITE_KEY_OPTION, '');
        if ($site_key === '') {
            return new WP_Error('site_key_not_set', 'Site key is not configured', array('status' => 401));
        }

        $authorization = (string) $request->get_header('Authorization');
        if ($authorization === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authorization = sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        }
        if ($authorization === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authorization = sanitize_text_field(wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        }
        $bearer_key = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            $bearer_key = trim((string) $matches[1]);
        }

        if ($bearer_key === '') {
            return new WP_Error('missing_bearer_key', 'Bearer key is required', array('status' => 401));
        }

        if (!hash_equals($site_key, $bearer_key)) {
            return new WP_Error('invalid_bearer_key', 'Invalid Bearer key', array('status' => 401));
        }

        $timestamp = (string) $request->get_header('X-AI-Craft-Timestamp');
        if ($timestamp === '') {
            return new WP_Error('missing_timestamp', 'Timestamp is required', array('status' => 401));
        }

        $request_time = strtotime($timestamp);
        if (!$request_time || abs(time() - $request_time) > 300) {
            return new WP_Error('stale_timestamp', 'Timestamp is too old', array('status' => 401));
        }

        $signature = (string) $request->get_header('X-AI-Craft-Signature');
        if ($signature === '') {
            return new WP_Error('missing_signature', 'Signature is required', array('status' => 401));
        }

        $raw_body = (string) $request->get_body();
        $expected = hash_hmac('sha256', $timestamp . '.' . $raw_body, $site_key);
        if (!hash_equals($expected, $signature)) {
            return new WP_Error('invalid_signature', 'Invalid request signature', array('status' => 401));
        }

        return true;
    }

    /**
     * Handle the article webhook.
     */
    public function handle_article_webhook($request)
    {
        return AI_Craft_Post_Webhook_Handler::get_instance()->process_article($request);
    }

    /**
     * Handle the site info webhook.
     */
    public function handle_site_info_webhook($request)
    {
        return AI_Craft_Post_Site_Info_Handler::get_instance()->get_site_info($request);
    }

    /**
     * Handle the posts range webhook.
     */
    public function handle_posts_range_webhook($request)
    {
        return AI_Craft_Post_Webhook_Handler::get_instance()->get_posts_range($request);
    }

    /**
     * Handle the single post webhook.
     */
    public function handle_post_webhook($request)
    {
        return AI_Craft_Post_Webhook_Handler::get_instance()->get_post_payload($request);
    }

    /**
     * Handle the append images webhook.
     */
    public function handle_append_images_webhook($request)
    {
        return AI_Craft_Post_Webhook_Handler::get_instance()->append_images_to_existing_post($request);
    }

    /**
     * Handle the approved article refresh webhook.
     */
    public function handle_article_refresh_webhook($request)
    {
        return AI_Craft_Post_Webhook_Handler::get_instance()->apply_article_refresh($request);
    }

    /**
     * Render the plugin settings page.
     */
    public function render_admin_page()
    {
        $site_key = (string) get_option(AI_CRAFT_POST_SITE_KEY_OPTION, '');
        $masked_key = $site_key === '' ? '' : str_repeat('*', max(0, strlen($site_key) - 4)) . substr($site_key, -4);
        $base_url = rest_url(AI_CRAFT_POST_REST_NAMESPACE);
        $seo_provider = ai_craft_post_detect_seo_provider();
        $seo_provider_labels = array(
            'yoast' => 'Yoast SEO',
            'rank_math' => 'Rank Math',
            'aioseo' => 'All in One SEO',
            'none' => __('No supported SEO plugin detected', 'craftpost-site-connector'),
        );
        $seo_provider_label = $seo_provider_labels[$seo_provider] ?? $seo_provider;
        $write_title = (bool) get_option(AI_CRAFT_POST_SEO_WRITE_TITLE_OPTION, true);
        $write_description = (bool) get_option(AI_CRAFT_POST_SEO_WRITE_DESCRIPTION_OPTION, true);
        $write_keyword = (bool) get_option(AI_CRAFT_POST_SEO_WRITE_KEYWORD_OPTION, true);
        $faq_schema_enabled = (bool) get_option(AI_CRAFT_POST_FAQ_SCHEMA_ENABLED_OPTION, false);
        $faq_metabox_enabled = (bool) get_option(AI_CRAFT_POST_FAQ_METABOX_ENABLED_OPTION, false);
        $faq_details_enabled = (bool) get_option(AI_CRAFT_POST_FAQ_DETAILS_ENABLED_OPTION, false);
        $faq_css = (string) get_option(AI_CRAFT_POST_FAQ_CSS_OPTION, AI_CRAFT_POST_DEFAULT_FAQ_CSS);
        $code_editor_settings = wp_enqueue_code_editor(array('type' => 'text/css'));
        if (false !== $code_editor_settings) {
            wp_add_inline_script(
                'code-editor',
                'jQuery(function(){wp.codeEditor.initialize(' . wp_json_encode(AI_CRAFT_POST_FAQ_CSS_OPTION) . ',' . wp_json_encode($code_editor_settings) . ');});'
            );
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('CraftPost Site Connector Settings', 'craftpost-site-connector'); ?> v<?php echo esc_html(AI_CRAFT_POST_VERSION); ?></h1>

            <p>
                <?php echo esc_html__('Add this WordPress site to your CraftPost account, copy the generated aic_live key, and paste it below', 'craftpost-site-connector'); ?>
                <a href="<?php echo esc_url(AI_CRAFT_POST_DASHBOARD_URL); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open CraftPost and get a key', 'craftpost-site-connector'); ?></a>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields('ai_craft_post_settings'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(AI_CRAFT_POST_SITE_KEY_OPTION); ?>"><?php echo esc_html__('Site Key', 'craftpost-site-connector'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                id="<?php echo esc_attr(AI_CRAFT_POST_SITE_KEY_OPTION); ?>"
                                name="<?php echo esc_attr(AI_CRAFT_POST_SITE_KEY_OPTION); ?>"
                                value="<?php echo esc_attr($site_key); ?>"
                                class="regular-text"
                                autocomplete="new-password" />
                            <p class="description"><?php echo esc_html__('Paste a new key to replace the stored key.', 'craftpost-site-connector'); ?></p>
                            <p>
                                <strong><?php echo esc_html__('Status:', 'craftpost-site-connector'); ?></strong>
                                <?php echo $site_key === '' ? esc_html__('missing', 'craftpost-site-connector') : '<span style="color: green;">' . esc_html__('Active', 'craftpost-site-connector') . '</span>'; ?>
                            </p>
                            <?php if ($masked_key !== '') : ?>
                                <p>
                                    <strong><?php echo esc_html__('Stored key:', 'craftpost-site-connector'); ?></strong>
                                    <code><?php echo esc_html($masked_key); ?></code>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('SEO settings', 'craftpost-site-connector'); ?></h2>
                <div style="display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start; max-width: 980px;">
                    <table class="form-table" role="presentation" style="max-width: 620px;">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Detected SEO plugin', 'craftpost-site-connector'); ?></th>
                            <td>
                                <p style="margin-top: 0;">
                                    <strong><?php echo esc_html($seo_provider_label); ?></strong>
                                    <code><?php echo esc_html($seo_provider); ?></code>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__('CraftPost Site Connector detects Yoast SEO, Rank Math, or All in One SEO.', 'craftpost-site-connector'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Write SEO fields', 'craftpost-site-connector'); ?></th>
                            <td>
                                <fieldset>
                                    <input type="hidden" name="<?php echo esc_attr(AI_CRAFT_POST_SEO_WRITE_TITLE_OPTION); ?>" value="0" />
                                    <label>
                                        <input type="checkbox"
                                            name="<?php echo esc_attr(AI_CRAFT_POST_SEO_WRITE_TITLE_OPTION); ?>"
                                            value="1"
                                            <?php checked($write_title); ?> />
                                        <?php echo esc_html__('Unique SEO Title + Site Name', 'craftpost-site-connector'); ?>
                                    </label>
                                    <br />
                                    <input type="hidden" name="<?php echo esc_attr(AI_CRAFT_POST_SEO_WRITE_DESCRIPTION_OPTION); ?>" value="0" />
                                    <label>
                                        <input type="checkbox"
                                            name="<?php echo esc_attr(AI_CRAFT_POST_SEO_WRITE_DESCRIPTION_OPTION); ?>"
                                            value="1"
                                            <?php checked($write_description); ?> />
                                        <?php echo esc_html__('Meta Description', 'craftpost-site-connector'); ?>
                                    </label>
                                    <br />
                                    <input type="hidden" name="<?php echo esc_attr(AI_CRAFT_POST_SEO_WRITE_KEYWORD_OPTION); ?>" value="0" />
                                    <label>
                                        <input type="checkbox"
                                            name="<?php echo esc_attr(AI_CRAFT_POST_SEO_WRITE_KEYWORD_OPTION); ?>"
                                            value="1"
                                            <?php checked($write_keyword); ?> />
                                        <?php echo esc_html__('Focus Keyword', 'craftpost-site-connector'); ?>
                                    </label>
                                </fieldset>
                                <p class="description">
                                    <?php echo esc_html__('Checked fields use generated values. Unchecked fields use the default SEO plugin templates (Title, Page, Separator, Site Name).', 'craftpost-site-connector'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <div aria-hidden="true" style="min-width: 260px; max-width: 320px; padding: 18px; border: 1px solid #ccd0d4; border-radius: 8px; background: #fff;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px;">
                            <span style="width: 42px; height: 42px; border-radius: 50%; background: #e7f0ff; display: inline-flex; align-items: center; justify-content: center; color: #135e96; font-weight: 700;">AI</span>
                            <span style="height: 2px; flex: 1; background: #ccd0d4;"></span>
                            <span style="width: 42px; height: 42px; border-radius: 50%; background: #f0f6fc; display: inline-flex; align-items: center; justify-content: center; color: #1d2327; font-weight: 700;">SEO</span>
                        </div>
                        <div style="border: 1px solid #dcdcde; border-radius: 6px; padding: 12px;">
                            <div style="height: 10px; width: 82%; background: #dbeafe; border-radius: 12px; margin-bottom: 10px;"></div>
                            <div style="height: 10px; width: 64%; background: #dcfce7; border-radius: 12px; margin-bottom: 10px;"></div>
                            <div style="height: 10px; width: 46%; background: #fef3c7; border-radius: 12px;"></div>
                        </div>
                    </div>
                </div>

                <h2><?php echo esc_html__('FAQ settings', 'craftpost-site-connector'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('FAQ schema', 'craftpost-site-connector'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(AI_CRAFT_POST_FAQ_METABOX_ENABLED_OPTION); ?>" value="0" />
                            <label>
                                <input type="checkbox"
                                    name="<?php echo esc_attr(AI_CRAFT_POST_FAQ_METABOX_ENABLED_OPTION); ?>"
                                    value="1"
                                    <?php checked($faq_metabox_enabled); ?> />
                                <?php echo esc_html__('Use FAQ metabox', 'craftpost-site-connector'); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__('Stores generated questions in the FAQ metabox. When disabled, questions are added directly to the post content.', 'craftpost-site-connector'); ?>
                            </p>
                            <br />
                            <input type="hidden" name="<?php echo esc_attr(AI_CRAFT_POST_FAQ_SCHEMA_ENABLED_OPTION); ?>" value="0" />
                            <label>
                                <input type="checkbox"
                                    name="<?php echo esc_attr(AI_CRAFT_POST_FAQ_SCHEMA_ENABLED_OPTION); ?>"
                                    value="1"
                                    <?php checked($faq_schema_enabled); ?> />
                                <?php echo esc_html__('Enable FAQPage schema.org', 'craftpost-site-connector'); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__('Adds FAQPage structured data for search engines without changing the visible post content.', 'craftpost-site-connector'); ?>
                            </p>
                            <br />
                            <input type="hidden" name="<?php echo esc_attr(AI_CRAFT_POST_FAQ_DETAILS_ENABLED_OPTION); ?>" value="0" />
                            <label>
                                <input type="checkbox"
                                    name="<?php echo esc_attr(AI_CRAFT_POST_FAQ_DETAILS_ENABLED_OPTION); ?>"
                                    value="1"
                                    <?php checked($faq_details_enabled); ?> />
                                <?php echo esc_html__('Display FAQ as collapsible details', 'craftpost-site-connector'); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__('Enable this to use details blocks where the first question is open and the rest are collapsed', 'craftpost-site-connector'); ?>
                            </p>
                            <div aria-label="<?php echo esc_attr__('FAQ metabox preview', 'craftpost-site-connector'); ?>" style="max-width: 450px; margin-top: 14px;">
                                <svg viewBox="0 0 520 150" role="img" style="width: 100%; height: auto; display: block; border: 1px solid #ccd0d4; background: #f6f7f7;">
                                    <rect x="0" y="0" width="520" height="150" fill="#f6f7f7" />
                                    <rect x="14" y="16" width="492" height="118" fill="#fff" stroke="#c3c4c7" />
                                    <line x1="14" y1="52" x2="506" y2="52" stroke="#dcdcde" />
                                    <text x="28" y="39" fill="#1d2327" font-family="Arial, sans-serif" font-size="15" font-weight="700">FAQ schema.org</text>
                                    <text x="28" y="78" fill="#50575e" font-family="Arial, sans-serif" font-size="13">Questions shown after the post content.</text>
                                    <rect x="28" y="94" width="112" height="30" fill="#fff" stroke="#2271b1" />
                                    <text x="48" y="114" fill="#135e96" font-family="Arial, sans-serif" font-size="13">Add question</text>
                                    <rect x="148" y="94" width="162" height="30" fill="#fff" stroke="#2271b1" />
                                    <text x="166" y="114" fill="#135e96" font-family="Arial, sans-serif" font-size="13">Move FAQ from content</text>
                                </svg>
                                <p class="description" style="margin-top: 8px;">
                                    <?php echo esc_html__('The FAQ metabox is shown in the post editor only when "Use FAQ metabox" is enabled.', 'craftpost-site-connector'); ?>
                                </p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(AI_CRAFT_POST_FAQ_CSS_OPTION); ?>"><?php echo esc_html__('FAQ CSS', 'craftpost-site-connector'); ?></label>
                        </th>
                        <td>
                            <textarea
                                id="<?php echo esc_attr(AI_CRAFT_POST_FAQ_CSS_OPTION); ?>"
                                name="<?php echo esc_attr(AI_CRAFT_POST_FAQ_CSS_OPTION); ?>"
                                rows="18"
                                class="large-text code"
                                spellcheck="false"><?php echo esc_textarea($faq_css); ?></textarea>
                            <p class="description"><?php echo esc_html__('CSS applied to the FAQ block displayed after post content.', 'craftpost-site-connector'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

/**
 * Extract FAQ questions from the post content and return cleaned content.
 */
function craftpost_site_connector_extract_faq_items_from_content($content)
{
    $content = (string) $content;
    $heading_pattern = '/((?:<!--\s*wp:heading(?:\s+\{.*?\})?\s*-->\s*)?<h2\b[^>]*>(.*?)<\/h2>\s*(?:<!--\s*\/wp:heading\s*-->\s*)?)/uis';
    if (!preg_match_all($heading_pattern, $content, $heading_matches, PREG_OFFSET_CAPTURE)) {
        return array(
            'content' => $content,
            'items' => array(),
            'title' => '',
        );
    }

    $faq_start = -1;
    $faq_end = strlen($content);
    $faq_title = '';
    foreach ($heading_matches[0] as $index => $match) {
        $heading_html = $heading_matches[2][$index][0] ?? '';
        $heading_title = trim(wp_strip_all_tags(html_entity_decode((string) $heading_html, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $heading_text = function_exists('mb_strtolower') ? mb_strtolower($heading_title) : strtolower($heading_title);

        if (!preg_match('/\bfaq\b|поширен|част[іи]|питан|вопрос|часто задаваем/u', $heading_text)) {
            continue;
        }

        $faq_start = intval($match[1]);
        $faq_title = $heading_title;
        if (isset($heading_matches[0][$index + 1])) {
            $faq_end = intval($heading_matches[0][$index + 1][1]);
        }
        break;
    }

    if ($faq_start < 0) {
        return array(
            'content' => $content,
            'items' => array(),
            'title' => '',
        );
    }

    $section = substr($content, $faq_start, $faq_end - $faq_start);
    $question_pattern = '/((?:<!--\s*wp:heading(?:\s+\{.*?\})?\s*-->\s*)?<h([34])\b[^>]*>(.*?)<\/h\2>\s*(?:<!--\s*\/wp:heading\s*-->\s*)?)/uis';
    if (!preg_match_all($question_pattern, $section, $question_matches, PREG_OFFSET_CAPTURE)) {
        return array(
            'content' => $content,
            'items' => array(),
            'title' => '',
        );
    }

    $items = array();
    $remove_ranges = array();
    if (!empty($question_matches[0][0])) {
        $remove_ranges[] = array(
            'start' => 0,
            'end' => intval($question_matches[0][0][1]),
        );
    }

    foreach ($question_matches[0] as $index => $match) {
        $question_html = $question_matches[3][$index][0] ?? '';
        $question = trim(wp_strip_all_tags(html_entity_decode((string) $question_html, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $answer_start = intval($match[1]) + strlen($match[0]);
        $answer_end = isset($question_matches[0][$index + 1]) ? intval($question_matches[0][$index + 1][1]) : strlen($section);
        $answer_source = substr($section, $answer_start, $answer_end - $answer_start);
        $answer = trim($answer_source);
        $remove_end = $answer_end;
        if (preg_match('/(?:<!--\s*wp:(?:paragraph|list)(?:\s+\{.*?\})?\s*-->\s*)?(?:<p\b[^>]*>.*?<\/p>|<ul\b[^>]*>.*?<\/ul>|<ol\b[^>]*>.*?<\/ol>)(?:\s*<!--\s*\/wp:(?:paragraph|list)\s*-->)?/uis', $answer_source, $answer_block, PREG_OFFSET_CAPTURE)) {
            $answer = trim($answer_block[0][0]);
            $remove_end = $answer_start + intval($answer_block[0][1]) + strlen($answer_block[0][0]);
        }
        $answer_text = trim(wp_strip_all_tags($answer));

        if ($question === '' || $answer_text === '') {
            continue;
        }

        $items[] = array(
            'question' => sanitize_text_field($question),
            'answer' => wp_kses_post($answer),
        );
        $remove_ranges[] = array(
            'start' => intval($match[1]),
            'end' => $remove_end,
        );
    }

    if (empty($items)) {
        return array(
            'content' => $content,
            'items' => array(),
            'title' => '',
        );
    }

    usort($remove_ranges, function ($a, $b) {
        return intval($a['start']) <=> intval($b['start']);
    });

    $clean_section = '';
    $cursor = 0;
    foreach ($remove_ranges as $range) {
        $range_start = max($cursor, intval($range['start']));
        $range_end = max($range_start, intval($range['end']));
        $clean_section .= substr($section, $cursor, $range_start - $cursor);
        $cursor = $range_end;
    }
    $clean_section .= substr($section, $cursor);
    $clean_content = trim(substr($content, 0, $faq_start) . $clean_section . substr($content, $faq_end));

    return array(
        'content' => $clean_content,
        'items' => $items,
        'title' => sanitize_text_field($faq_title),
    );
}

/**
 * Start CraftPost Site Connector.
 */
function craftpost_site_connector_init()
{
    AI_Craft_Post_Plugin::get_instance();
}
add_action('plugins_loaded', 'craftpost_site_connector_init');

/**
 * Allow CraftPost Site Connector routes in Clearfy REST API whitelist.
 */
add_filter('clearfy_rest_api_white_list', function ($white_list) {
    if (!is_array($white_list)) {
        $white_list = array();
    }

    $white_list[] = AI_CRAFT_POST_REST_NAMESPACE;

    return array_values(array_unique($white_list));
});

/**
 * Allow AI Craft 2 routes in Clearfy REST API whitelist.
 */
add_filter('clearfy_rest_api_white_list', function ($white_list) {
    if (!is_array($white_list)) {
        $white_list = array();
    }

    $white_list[] = 'ai-craft-post';
    $white_list[] = 'ai-craft-post/v1';
    $white_list[] = 'craftpost-site-connector';

    return array_values(array_unique($white_list));
});

/**
 * Activate CraftPost Site Connector.
 */
function craftpost_site_connector_activate()
{
}
register_activation_hook(__FILE__, 'craftpost_site_connector_activate');

/**
 * Deactivate CraftPost Site Connector.
 */
function craftpost_site_connector_deactivate()
{
}
register_deactivation_hook(__FILE__, 'craftpost_site_connector_deactivate');
