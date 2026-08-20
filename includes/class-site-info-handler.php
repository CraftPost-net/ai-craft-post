<?php
/**
 * AI Craft Post site info handler.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns safe site information for AI Craft Post.
 */
class AI_Craft_Post_Site_Info_Handler
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
    }

    /**
     * Get safe site information.
     */
    public function get_site_info($request)
    {
        $site_url = get_site_url();
        $home_url = get_home_url();

        return rest_ensure_response(array(
            'site_url' => $site_url,
            'home_url' => $home_url,
            'site' => array(
                'name' => get_bloginfo('name'),
                'url' => $site_url,
                'home_url' => $home_url,
                'language' => get_bloginfo('language'),
                'timezone' => wp_timezone_string(),
                'version' => get_bloginfo('version'),
            ),
            'authors' => $this->get_authors(),
            'post_types' => $this->get_post_types(),
            'categories' => $this->get_categories(),
            'templates' => $this->get_templates(),
            'image_sizes' => $this->get_image_sizes(),
            'polylang' => $this->get_polylang_info(),
            'seo_provider' => ai_craft_post_detect_seo_provider(),
            'seo_settings' => array(
                'write_title' => (bool) get_option(AI_CRAFT_POST_SEO_WRITE_TITLE_OPTION, true),
                'write_description' => (bool) get_option(AI_CRAFT_POST_SEO_WRITE_DESCRIPTION_OPTION, true),
                'write_keyword' => (bool) get_option(AI_CRAFT_POST_SEO_WRITE_KEYWORD_OPTION, true),
            ),
            'plugin' => array(
                'name' => 'CraftPost Site Connector',
                'version' => defined('AI_CRAFT_POST_VERSION') ? AI_CRAFT_POST_VERSION : '',
                'capabilities' => array(
                    'article_payload_language' => true,
                    'section_image_packs' => true,
                    'preserve_inline_images' => true,
                    'refresh_change_post_url' => true,
                    'append_images' => true,
                    'seo_meta' => true,
                    'structured_faq_payload' => true,
                    'polylang_translations' => function_exists('pll_languages_list'),
                ),
            ),
        ));
    }

    /**
     * Get Polylang languages when Polylang is active.
     */
    private function get_polylang_info()
    {
        if (!function_exists('pll_languages_list')) {
            return array(
                'active' => false,
                'languages' => array(),
            );
        }

        $languages = array();
        $default_slug = function_exists('pll_default_language') ? (string) pll_default_language('slug') : '';
        $items = pll_languages_list(array('fields' => array()));
        if (is_array($items)) {
            foreach ($items as $language) {
                if (!is_object($language)) {
                    continue;
                }

                $slug = sanitize_key((string) ($language->slug ?? ''));
                if ($slug === '') {
                    continue;
                }

                $languages[] = array(
                    'slug' => $slug,
                    'name' => sanitize_text_field((string) ($language->name ?? $slug)),
                    'locale' => sanitize_text_field((string) ($language->locale ?? '')),
                    'is_default' => $default_slug !== '' && $slug === $default_slug,
                );
            }
        }

        return array(
            'active' => true,
            'default_language' => $default_slug,
            'languages' => $languages,
        );
    }

    /**
     * Get users that can author posts.
     */
    private function get_authors()
    {
        $users = get_users(array(
            'role__in' => array('administrator', 'editor', 'author'),
        ));

        $authors = array();
        foreach ($users as $user) {
            $authors[] = array(
                'id' => intval($user->ID),
                'username' => $user->user_login,
                'display_name' => $user->display_name,
                'roles' => array_values((array) $user->roles),
            );
        }

        return $authors;
    }

    /**
     * Get public post types.
     */
    private function get_post_types()
    {
        $objects = get_post_types(array('public' => true), 'objects');
        $post_types = array();

        foreach ($objects as $post_type) {
            if (in_array($post_type->name, array('attachment', 'revision', 'nav_menu_item'), true)) {
                continue;
            }

            $post_types[] = array(
                'name' => $post_type->name,
                'label' => $post_type->label,
                'hierarchical' => (bool) $post_type->hierarchical,
                'has_archive' => (bool) $post_type->has_archive,
                'supports' => array_keys(get_all_post_type_supports($post_type->name)),
            );
        }

        return $post_types;
    }

    /**
     * Get available categories.
     */
    private function get_categories()
    {
        $terms = get_categories(array(
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ));

        $categories = array();
        foreach ($terms as $term) {
            $categories[] = array(
                'id' => intval($term->term_id),
                'name' => $term->name,
                'slug' => $term->slug,
                'parent' => intval($term->parent),
                'count' => intval($term->count),
            );
        }

        return $categories;
    }

    /**
     * Get available templates.
     */
    private function get_templates()
    {
        $templates = array(array(
            'name' => 'Default Template',
            'file' => 'default',
            'type' => 'default',
        ));

        foreach (wp_get_theme()->get_page_templates() as $file => $name) {
            $templates[] = array(
                'name' => $name,
                'file' => $file,
                'type' => 'page',
            );
        }

        return $templates;
    }

    /**
     * Get registered image sizes.
     */
    private function get_image_sizes()
    {
        global $_wp_additional_image_sizes;

        $sizes = array();
        foreach (get_intermediate_image_sizes() as $size) {
            if (in_array($size, array('thumbnail', 'medium', 'medium_large', 'large'), true)) {
                $sizes[$size] = array(
                    'width' => intval(get_option($size . '_size_w')),
                    'height' => intval(get_option($size . '_size_h')),
                    'crop' => (bool) get_option($size . '_crop'),
                );
                continue;
            }

            if (isset($_wp_additional_image_sizes[$size])) {
                $sizes[$size] = array(
                    'width' => intval($_wp_additional_image_sizes[$size]['width']),
                    'height' => intval($_wp_additional_image_sizes[$size]['height']),
                    'crop' => (bool) $_wp_additional_image_sizes[$size]['crop'],
                );
            }
        }

        return $sizes;
    }
}

/**
 * Detect the active supported SEO plugin.
 */
function ai_craft_post_detect_seo_provider()
{
    $active_plugins = (array) get_option('active_plugins', array());
    $active_sitewide_plugins = is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', array())) : array();
    $plugins = array_merge($active_plugins, $active_sitewide_plugins);

    $yoast_active = defined('WPSEO_VERSION') || class_exists('WPSEO_Options') || in_array('wordpress-seo/wp-seo.php', $plugins, true);
    $rank_math_active = defined('RANK_MATH_VERSION') || class_exists('RankMath') || in_array('seo-by-rank-math/rank-math.php', $plugins, true);
    $aioseo_active = defined('AIOSEO_VERSION') || function_exists('aioseo') || in_array('all-in-one-seo-pack/all_in_one_seo_pack.php', $plugins, true);

    if ($yoast_active) {
        return 'yoast';
    }

    if ($rank_math_active) {
        return 'rank_math';
    }

    if ($aioseo_active) {
        return 'aioseo';
    }

    return 'none';
}
