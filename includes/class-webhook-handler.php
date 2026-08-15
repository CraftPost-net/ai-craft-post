<?php
/**
 * AI Craft Post webhook handler.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles receiver webhooks from AI Craft Post.
 */
class AI_Craft_Post_Webhook_Handler
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
     * Create or update a post from a webhook payload.
     */
    public function process_article($request)
    {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            return new WP_Error('invalid_payload', 'JSON object payload is required', array('status' => 400));
        }

        $validation = $this->validate_article_payload($params);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $post_type = sanitize_key($params['post_type'] ?? 'post');
        $post_status = sanitize_key($params['post_status'] ?? 'draft');
        $post_content = $this->sanitize_post_content((string) $params['post_content']);
        if (is_wp_error($post_content)) {
            return $post_content;
        }
        $faq_schema_items = array();
        $faq_schema_title = '';
        $faq_metabox_enabled = (bool) get_option(AI_CRAFT_POST_FAQ_METABOX_ENABLED_OPTION, false);
        if (!empty($params['faq']) && is_array($params['faq'])) {
            $faq_schema_items = $this->sanitize_faq_schema_items($params['faq']);
            $faq_schema_title = isset($params['faq_title']) && is_string($params['faq_title'])
                ? sanitize_text_field($params['faq_title'])
                : '';
            if (!$faq_metabox_enabled && !empty($faq_schema_items)) {
                $post_content = rtrim($post_content) . "\n\n" . $this->render_faq_content($faq_schema_items, $faq_schema_title);
                $faq_schema_items = array();
            }
        } elseif ($faq_metabox_enabled && function_exists('ai_craft_post_extract_faq_items_from_content')) {
            $faq_schema = ai_craft_post_extract_faq_items_from_content($post_content);
            $post_content = $faq_schema['content'];
            $faq_schema_items = $faq_schema['items'];
            $faq_schema_title = $faq_schema['title'] ?? '';
        }

        $post_data = array(
            'post_title' => sanitize_text_field($params['post_title']),
            'post_content' => $post_content,
            'post_status' => $post_status,
            'post_author' => intval($params['author_id'] ?? 1),
            'post_type' => $post_type,
        );

        if (!empty($params['post_excerpt']) && is_string($params['post_excerpt'])) {
            $post_data['post_excerpt'] = sanitize_textarea_field($params['post_excerpt']);
        }

        if (!empty($params['post_id'])) {
            $post = get_post(intval($params['post_id']));
            if (!$post instanceof WP_Post || in_array($post->post_status, array('trash', 'auto-draft', 'inherit'), true)) {
                return new WP_Error('post_not_found', 'Post not found', array('status' => 404));
            }
            $post_data['ID'] = intval($post->ID);
            $post_id = wp_update_post($post_data, true);
            $created = false;
        } else {
            $post_id = wp_insert_post($post_data, true);
            $created = true;
        }

        if (is_wp_error($post_id)) {
            return new WP_Error('post_save_failed', 'Failed to save post: ' . $post_id->get_error_message(), array('status' => 500));
        }

        if (!empty($params['post_template']) && $post_type === 'page') {
            update_post_meta($post_id, '_wp_page_template', sanitize_text_field($params['post_template']));
        }

        if (array_key_exists('comments_status', $params)) {
            wp_update_post(array(
                'ID' => $post_id,
                'comment_status' => sanitize_key($params['comments_status']) === 'open' ? 'open' : 'closed',
            ));
        }

        if (!empty($params['queue_item_id'])) {
            update_post_meta($post_id, 'ai_craft_post_queue_item_id', sanitize_text_field($params['queue_item_id']));
        }

        if (!empty($params['article_job_id'])) {
            update_post_meta($post_id, 'ai_craft_post_article_job_id', intval($params['article_job_id']));
        }

        $translation_result = $this->apply_polylang_translation_meta($post_id, $params);

        if (!empty($faq_schema_items)) {
            update_post_meta($post_id, AI_CRAFT_POST_FAQ_SCHEMA_META_KEY, $faq_schema_items);
            if ($faq_schema_title !== '') {
                update_post_meta($post_id, AI_CRAFT_POST_FAQ_SCHEMA_TITLE_META_KEY, sanitize_text_field($faq_schema_title));
            }
        }

        $this->assign_categories($post_id, $params['categories'] ?? array());
        $this->assign_tags($post_id, $params['tags'] ?? array());

        $featured_image_id = 0;
        if (!empty($params['image_url'])) {
            $featured_image_id = $this->upload_featured_image($post_id, $params['image_url'], $params['post_title'], $params['featured_image_alt'] ?? '');
        }

        $this->write_custom_meta($post_id, $params['meta_input'] ?? array());
        $this->write_custom_meta($post_id, $params['post_meta'] ?? array());

        $section_images = $this->process_section_image_packs($post_id, $params['section_image_packs'] ?? array(), $params['post_title'], $params['language'] ?? '');
        if (!empty($section_images['downloaded_images'])) {
            $updated_content = $this->inject_section_images_into_content($post_content, $section_images['packs']);
            if ($updated_content !== $post_content) {
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_content' => $updated_content,
                ));
            }
        }

        $seo_result = $this->write_seo_meta($post_id, $params);
        $translation_posts = $this->create_translation_posts($params);
        $post = get_post($post_id);

        return rest_ensure_response(array(
            'success' => true,
            'created' => $created,
            'post_id' => intval($post_id),
            'post_slug' => $post instanceof WP_Post ? $post->post_name : '',
            'post_url' => $this->get_post_response_url($post_id),
            'featured_image_id' => intval($featured_image_id),
            'section_images' => $section_images,
            'seo' => $seo_result,
            'translation' => $translation_result,
            'translations' => $translation_posts,
            'message' => $created ? 'Article successfully created' : 'Article successfully updated',
        ));
    }

    /**
     * Return a paginated list of existing posts.
     */
    public function get_posts_range($request)
    {
        $page = max(1, intval($request->get_param('page')));
        $per_page = max(1, min(100, intval($request->get_param('per_page')) ?: 100));
        $post_type = sanitize_key($request->get_param('post_type') ?: 'post');
        if (!$this->is_allowed_post_type($post_type)) {
            return new WP_Error('invalid_post_type', 'Post type is not allowed', array('status' => 400));
        }

        $query = new WP_Query(array(
            'post_type' => $post_type,
            'post_status' => array('publish', 'draft', 'pending', 'future', 'private'),
            'orderby' => 'ID',
            'order' => 'DESC',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'fields' => 'ids',
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
        ));

        $posts = array();
        $polylang_languages = array();
        if (function_exists('pll_languages_list')) {
            foreach ((array) pll_languages_list(array('fields' => array())) as $language) {
                if (!is_object($language) || empty($language->slug)) continue;
                $polylang_languages[] = array(
                    'slug' => sanitize_key((string) $language->slug),
                    'name' => sanitize_text_field((string) ($language->name ?? $language->slug)),
                    'locale' => sanitize_text_field((string) ($language->locale ?? '')),
                );
            }
        }
        foreach ((array) $query->posts as $post_id) {
            $post = get_post($post_id);
            if (!$post instanceof WP_Post) {
                continue;
            }

            $categories = array();
            foreach (get_the_category($post->ID) as $category) {
                $categories[] = array(
                    'id' => intval($category->term_id),
                    'name' => $category->name,
                );
            }

            $posts[] = array(
                'post_id' => intval($post->ID),
                'title' => get_the_title($post),
                'post_url' => get_permalink($post->ID),
                'status' => $post->post_status,
                'post_type' => $post->post_type,
                'author_id' => intval($post->post_author),
                'modified_at' => $post->post_modified_gmt,
                'excerpt' => (string) $post->post_excerpt,
                'categories' => $categories,
                'has_featured_image' => has_post_thumbnail($post->ID),
                'language' => function_exists('pll_get_post_language') ? sanitize_key((string) pll_get_post_language($post->ID, 'slug')) : '',
                'translations' => function_exists('pll_get_post_translations') ? array_map('intval', (array) pll_get_post_translations($post->ID)) : array(),
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'page' => $page,
            'per_page' => $per_page,
            'total' => intval($query->found_posts),
            'total_pages' => intval($query->max_num_pages),
            'count' => count($posts),
            'posts' => $posts,
            'polylang' => array(
                'active' => function_exists('pll_languages_list'),
                'default_language' => function_exists('pll_default_language') ? sanitize_key((string) pll_default_language('slug')) : '',
                'languages' => $polylang_languages,
            ),
        ));
    }

    /**
     * Return one existing post payload.
     */
    public function get_post_payload($request)
    {
        $post_id = intval($request->get_param('post_id'));
        $post = get_post($post_id);

        if (!$post instanceof WP_Post || in_array($post->post_status, array('trash', 'auto-draft', 'inherit'), true)) {
            return new WP_Error('post_not_found', 'Post not found', array('status' => 404));
        }

        $faq_items = $this->sanitize_faq_schema_items(get_post_meta($post->ID, AI_CRAFT_POST_FAQ_SCHEMA_META_KEY, true));
        $faq_title = sanitize_text_field((string) get_post_meta($post->ID, AI_CRAFT_POST_FAQ_SCHEMA_TITLE_META_KEY, true));

        return rest_ensure_response(array(
            'success' => true,
            'post_id' => intval($post->ID),
            'post_title' => get_the_title($post),
            'post_content' => (string) $post->post_content,
            'post_url' => $this->get_post_response_url($post->ID),
            'post_status' => $post->post_status,
            'post_type' => $post->post_type,
            'post_excerpt' => (string) $post->post_excerpt,
            'language' => function_exists('pll_get_post_language') ? sanitize_key((string) pll_get_post_language($post->ID, 'slug')) : '',
            'translations' => function_exists('pll_get_post_translations') ? array_map('intval', (array) pll_get_post_translations($post->ID)) : array(),
            'faq' => array(
                'title' => $faq_title,
                'items' => $faq_items,
                'count' => count($faq_items),
            ),
        ));
    }

    /**
     * Append generated images to an existing post.
     */
    public function append_images_to_existing_post($request)
    {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            return new WP_Error('invalid_payload', 'JSON object payload is required', array('status' => 400));
        }

        $post_id = intval($params['post_id'] ?? 0);
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || in_array($post->post_status, array('trash', 'auto-draft', 'inherit'), true)) {
            return new WP_Error('post_not_found', 'Post not found', array('status' => 404));
        }

        $section_image_packs = $params['section_image_packs'] ?? array();
        if (empty($section_image_packs) || !is_array($section_image_packs)) {
            return new WP_Error('missing_section_images', 'Section image packs are required', array('status' => 400));
        }

        $section_images = $this->process_section_image_packs($post_id, $section_image_packs, get_the_title($post), $params['language'] ?? '');
        $updated_content = (string) $post->post_content;

        if (!empty($section_images['downloaded_images'])) {
            $updated_content = $this->inject_section_images_into_content($post->post_content, $section_images['packs']);
            if ($updated_content !== $post->post_content) {
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_content' => $updated_content,
                ));
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'post_id' => $post_id,
            'post_url' => $this->get_post_response_url($post_id),
            'message' => 'Images appended to existing post',
            'section_images' => $section_images,
            'content_updated' => $updated_content !== $post->post_content,
        ));
    }

    /**
     * Apply approved refresh operations to an existing post.
     */
    public function apply_article_refresh($request)
    {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            return new WP_Error('invalid_payload', 'JSON object payload is required', array('status' => 400));
        }

        $post_id = intval($params['post_id'] ?? 0);
        $refresh_job_id = intval($params['refresh_job_id'] ?? 0);
        $operations = $params['operations'] ?? null;
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || in_array($post->post_status, array('trash', 'auto-draft', 'inherit'), true)) {
            return new WP_Error('post_not_found', 'Post not found', array('status' => 404));
        }
        if ($refresh_job_id < 1 || !is_array($operations) || empty($operations)) {
            return new WP_Error('invalid_refresh_payload', 'refresh_job_id and operations are required', array('status' => 400));
        }

        $applied_job_id = intval(get_post_meta($post_id, '_ai_craft_post_refresh_job_id', true));
        if ($applied_job_id === $refresh_job_id) {
            return rest_ensure_response(array(
                'success' => true,
                'already_applied' => true,
                'post_id' => $post_id,
                'post_url' => $this->get_post_response_url($post_id),
                'message' => 'Article refresh was already applied',
            ));
        }

        $allowed_operations = array('rewrite_full_article', 'update_title', 'rewrite_intro', 'add_sections', 'add_table', 'add_faq', 'add_faq_schema', 'update_seo_title', 'update_seo_description', 'add_image_if_missing');
        $clean_operations = array();
        foreach ($operations as $operation => $item) {
            $operation = sanitize_key($operation);
            if (!in_array($operation, $allowed_operations, true) || !is_array($item)) {
                return new WP_Error('invalid_refresh_operation', 'Unsupported refresh operation: ' . $operation, array('status' => 400));
            }

            if ($operation === 'add_faq_schema') {
                $faq_items = $this->sanitize_faq_schema_items($item['items'] ?? array());
                $faq_title = sanitize_text_field((string) ($item['title'] ?? 'FAQ'));
                if (empty($faq_items) && !empty($item['value']) && function_exists('ai_craft_post_extract_faq_items_from_content')) {
                    $faq_html = $this->sanitize_post_content((string) $item['value']);
                    if (is_wp_error($faq_html)) {
                        return $faq_html;
                    }
                    $extracted_faq = ai_craft_post_extract_faq_items_from_content($faq_html);
                    $faq_items = $this->sanitize_faq_schema_items($extracted_faq['items'] ?? array());
                    if (!empty($extracted_faq['title'])) {
                        $faq_title = sanitize_text_field((string) $extracted_faq['title']);
                    }
                }
                if (empty($faq_items)) {
                    return new WP_Error('empty_refresh_faq_schema', 'FAQ schema questions are required', array('status' => 400));
                }
                $clean_operations[$operation] = array(
                    'title' => $faq_title !== '' ? $faq_title : 'FAQ',
                    'items' => $faq_items,
                );
                continue;
            }

            if (in_array($operation, array('rewrite_full_article', 'rewrite_intro', 'add_sections', 'add_table', 'add_faq'), true)) {
                $raw_value = (string) ($item['value'] ?? '');
                if (preg_match('/(?:&lt;|&#0*60;|&#x0*3c;)\s*\/?\s*a\b/iu', $raw_value)) {
                    return new WP_Error('escaped_refresh_link_markup', 'Refresh content contains escaped link markup', array('status' => 400));
                }
                if (preg_match('/<img\b/i', $raw_value) || ($operation !== 'rewrite_full_article' && preg_match('/<a\b/i', $raw_value))) {
                    return new WP_Error('protected_refresh_markup', 'Refresh content cannot contain links or images', array('status' => 400));
                }
                $value = $this->sanitize_post_content($raw_value);
                if (is_wp_error($value) || trim((string) $value) === '') {
                    return is_wp_error($value) ? $value : new WP_Error('empty_refresh_content', 'Refresh content cannot be empty', array('status' => 400));
                }
                if ($operation === 'add_faq' && (bool) get_option(AI_CRAFT_POST_FAQ_METABOX_ENABLED_OPTION, false)) {
                    if (!function_exists('ai_craft_post_extract_faq_items_from_content')) {
                        return new WP_Error('refresh_faq_parser_missing', 'FAQ metabox parser is unavailable', array('status' => 500));
                    }
                    $faq = ai_craft_post_extract_faq_items_from_content($value);
                    $faq_items = $this->sanitize_faq_schema_items($faq['items'] ?? array());
                    if (empty($faq_items)) {
                        return new WP_Error('empty_refresh_faq_schema', 'FAQ questions could not be extracted for the enabled FAQ metabox', array('status' => 400));
                    }
                    $faq_title = sanitize_text_field((string) ($faq['title'] ?? 'FAQ'));
                    $clean_operations['add_faq_schema'] = array(
                        'title' => $faq_title !== '' ? $faq_title : 'FAQ',
                        'items' => $faq_items,
                    );
                    continue;
                }
                $clean_operations[$operation] = $value;
                continue;
            }

            if (in_array($operation, array('update_title', 'update_seo_title', 'update_seo_description'), true)) {
                $value = trim(sanitize_text_field((string) ($item['value'] ?? '')));
                if ($value === '') {
                    return new WP_Error('empty_refresh_seo_value', 'SEO refresh value cannot be empty', array('status' => 400));
                }
                $clean_operations[$operation] = $value;
                continue;
            }

            $image_url = esc_url_raw((string) ($item['image_url'] ?? ''));
            if ($image_url === '') {
                return new WP_Error('missing_refresh_image_url', 'Generated featured image URL is required', array('status' => 400));
            }
            $clean_operations[$operation] = $image_url;
        }

        $applied = array();
        $skipped = array();
        if (!empty($params['auto_mode']) && $this->post_has_faq($post_id, (string) $post->post_content)) {
            foreach (array('add_faq', 'add_faq_schema') as $faq_operation) {
                if (isset($clean_operations[$faq_operation])) {
                    unset($clean_operations[$faq_operation]);
                    $skipped[] = $faq_operation;
                }
            }
            if (isset($clean_operations['add_sections']) && function_exists('ai_craft_post_extract_faq_items_from_content')) {
                $clean_sections = ai_craft_post_extract_faq_items_from_content($clean_operations['add_sections']);
                if (!empty($clean_sections['items'])) {
                    $clean_operations['add_sections'] = trim((string) ($clean_sections['content'] ?? ''));
                    if ($clean_operations['add_sections'] === '') {
                        unset($clean_operations['add_sections']);
                        $skipped[] = 'add_sections';
                    }
                }
            }
        }
        $featured_image_id = 0;
        if (isset($clean_operations['add_image_if_missing'])) {
            $featured_image_id = $this->upload_featured_image($post_id, $clean_operations['add_image_if_missing'], get_the_title($post), get_the_title($post));
            if ($featured_image_id < 1) {
                return new WP_Error('refresh_image_upload_failed', 'Failed to upload the generated featured image', array('status' => 502));
            }
            $applied[] = 'add_image_if_missing';
        }

        $updated_content = (string) $post->post_content;
        if (isset($clean_operations['rewrite_full_article'])) {
            $updated_content = $clean_operations['rewrite_full_article'];
            $incoming_links = array();
            if (preg_match_all('/<a\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*>.*?<\/a>/isu', $updated_content, $incoming_link_matches, PREG_SET_ORDER)) {
                foreach ($incoming_link_matches as $incoming_link_match) {
                    $incoming_url = esc_url_raw(html_entity_decode((string) $incoming_link_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $incoming_text = trim(html_entity_decode(wp_strip_all_tags((string) $incoming_link_match[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($incoming_url === '') {
                        return new WP_Error('invalid_refresh_link', 'Refresh content contains an invalid link', array('status' => 400));
                    }
                    $incoming_links[] = $incoming_url . "\0" . $incoming_text;
                }
            }

            $inventory_urls = array();
            $legacy_link_payload = empty($params['link_inventory']) && empty($params['link_validation']['status']);
            if (!$legacy_link_payload && empty($params['link_inventory']) && preg_match('/<a\b[^>]*\bhref=["\'][^"\']+["\']/iu', (string) $post->post_content)) {
                return new WP_Error('refresh_link_inventory_missing', 'Refresh job does not contain the required link inventory; create a new refresh job', array('status' => 400));
            }
            foreach ((array) ($params['link_inventory'] ?? array()) as $inventory_item) {
                if (!is_array($inventory_item) || ($inventory_item['kind'] ?? '') === 'plain_url') continue;
                $inventory_url = esc_url_raw((string) ($inventory_item['href'] ?? ''));
                if ($inventory_url === '') {
                    return new WP_Error('invalid_refresh_link_inventory', 'Refresh link inventory contains an invalid URL', array('status' => 400));
                }
                $inventory_urls[] = $inventory_url;
            }
            $incoming_urls = array_map(static function ($link_pair) {
                return explode("\0", $link_pair, 2)[0];
            }, $incoming_links);
            if ($legacy_link_payload) $inventory_urls = $incoming_urls;
            sort($inventory_urls);
            sort($incoming_urls);
            if ($inventory_urls !== $incoming_urls) {
                return new WP_Error('refresh_link_inventory_mismatch', 'Refresh links do not match the approved inventory', array('status' => 400));
            }

            if (!empty($params['preserve_inline_images'])) {
                $preserved_images = array();
                if (preg_match_all('/<figure\b[^>]*>.*?<img\b[^>]*>.*?<\/figure>/isu', (string) $post->post_content, $image_matches)) {
                    $preserved_images = array_values(array_filter(array_map('trim', $image_matches[0])));
                }
                if (!empty($preserved_images)) {
                    $preserved_index = 0;
                    $updated_content = preg_replace_callback('/<h[23]\b[^>]*>.*?<\/h[23]>/isu', function ($heading) use (&$preserved_index, $preserved_images) {
                        if (!isset($preserved_images[$preserved_index])) return $heading[0];
                        return $heading[0] . "\n" . $preserved_images[$preserved_index++];
                    }, $updated_content);
                    if ($preserved_index < count($preserved_images)) {
                        $updated_content = rtrim($updated_content) . "\n\n" . implode("\n", array_slice($preserved_images, $preserved_index));
                    }
                }
            }
            $applied[] = 'rewrite_full_article';
        }
        if (isset($clean_operations['rewrite_intro'])) {
            $replacements = 0;
            $opening_paragraph = '';
            preg_match('/<p\b[^>]*>.*?<\/p>/is', $updated_content, $opening_match);
            $opening_paragraph = (string) ($opening_match[0] ?? '');
            if ($opening_paragraph !== '' && preg_match('/<(a|img)\b/i', $opening_paragraph)) {
                $skipped[] = 'rewrite_intro';
            } elseif ($opening_paragraph !== '') {
                $updated_content = preg_replace('/<p\b[^>]*>.*?<\/p>/is', $clean_operations['rewrite_intro'], $updated_content, 1, $replacements);
                $applied[] = 'rewrite_intro';
            } else {
                $updated_content = $clean_operations['rewrite_intro'] . "\n\n" . $updated_content;
                $applied[] = 'rewrite_intro';
            }
        }
        foreach (array('add_sections', 'add_table', 'add_faq') as $append_operation) {
            if (isset($clean_operations[$append_operation])) {
                $updated_content = rtrim($updated_content) . "\n\n" . $clean_operations[$append_operation];
                $applied[] = $append_operation;
            }
        }
        if (isset($clean_operations['add_faq_schema'])) {
            if ((bool) get_option(AI_CRAFT_POST_FAQ_METABOX_ENABLED_OPTION, false)) {
                update_post_meta($post_id, AI_CRAFT_POST_FAQ_SCHEMA_META_KEY, $clean_operations['add_faq_schema']['items']);
                update_post_meta($post_id, AI_CRAFT_POST_FAQ_SCHEMA_TITLE_META_KEY, $clean_operations['add_faq_schema']['title']);
                $applied[] = 'add_faq_schema';
            } else {
                $updated_content = rtrim($updated_content) . "\n\n" . $this->render_faq_content($clean_operations['add_faq_schema']['items'], $clean_operations['add_faq_schema']['title']);
                $applied[] = 'add_faq';
            }
        }
        $change_post_url = !empty($params['change_post_url']);
        $update_publication_date = !empty($params['update_publication_date']);
        $slug_source_title = isset($clean_operations['update_title']) ? $clean_operations['update_title'] : (string) $post->post_title;
        $post_update = array(
            'ID' => $post_id,
            'post_name' => $change_post_url ? sanitize_title($slug_source_title) : $post->post_name,
        );
        if ($update_publication_date) {
            $post_update['post_date'] = current_time('mysql');
            $post_update['post_date_gmt'] = current_time('mysql', true);
        }
        $refresh_post_status = sanitize_key((string) ($params['post_status'] ?? $post->post_status));
        if (!in_array($refresh_post_status, array('publish', 'draft', 'pending', 'private'), true)) {
            return new WP_Error('invalid_refresh_post_status', 'Refresh post status is invalid', array('status' => 400));
        }
        if ($refresh_post_status !== $post->post_status) {
            $post_update['post_status'] = $refresh_post_status;
        }
        $refresh_author_id = intval($params['author_id'] ?? $post->post_author);
        $refresh_author = get_user_by('id', $refresh_author_id);
        if (!$refresh_author || !user_can($refresh_author, 'edit_posts')) {
            return new WP_Error('invalid_refresh_author', 'Refresh author is not allowed to edit posts', array('status' => 400));
        }
        if ($refresh_author_id !== intval($post->post_author)) {
            $post_update['post_author'] = $refresh_author_id;
        }
        if ($updated_content !== $post->post_content) {
            $post_update['post_content'] = $updated_content;
        }
        if (isset($clean_operations['update_title'])) {
            $post_update['post_title'] = $clean_operations['update_title'];
            $applied[] = 'update_title';
        }
        if (count($post_update) > 2) {
            $updated_post_id = wp_update_post($post_update, true);
            if (is_wp_error($updated_post_id)) {
                return new WP_Error('refresh_post_update_failed', $updated_post_id->get_error_message(), array('status' => 500));
            }
        }

        $optimized_images = array();
        if (isset($clean_operations['rewrite_full_article'])) {
            $optimized_images = AI_Craft_Post_Image_Handler::get_instance()->optimize_post_images($post_id, $updated_content);
        }

        $seo_params = array();
        if (isset($clean_operations['update_seo_title'])) {
            $seo_params['seo_title'] = $clean_operations['update_seo_title'];
            $applied[] = 'update_seo_title';
        }
        if (isset($clean_operations['update_seo_description'])) {
            $seo_params['seo_description'] = $clean_operations['update_seo_description'];
            $applied[] = 'update_seo_description';
        }
        $seo_result = empty($seo_params) ? array() : $this->write_seo_meta($post_id, $seo_params);

        $refresh_source_language = sanitize_key((string) ($params['source_language'] ?? ''));
        if ($refresh_source_language !== '' && function_exists('pll_languages_list') && function_exists('pll_set_post_language')) {
            $refresh_languages = array_map('sanitize_key', (array) pll_languages_list(array('fields' => 'slug')));
            if (!in_array($refresh_source_language, $refresh_languages, true)) {
                return new WP_Error('invalid_refresh_source_language', 'Refresh source language is not configured in Polylang', array('status' => 400));
            }
            pll_set_post_language($post_id, $refresh_source_language);
        }

        $translated_posts = array();
        $translations = is_array($params['translations'] ?? null) ? $params['translations'] : array();
        if (!empty($translations)) {
            if (!function_exists('pll_languages_list') || !function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
                return new WP_Error('polylang_unavailable', 'Polylang is required for refresh translations', array('status' => 409));
            }
            $available_languages = array_map('sanitize_key', (array) pll_languages_list(array('fields' => 'slug')));
            $linked_posts = function_exists('pll_get_post_translations') ? array_map('intval', (array) pll_get_post_translations($post_id)) : array();
            $source_language = sanitize_key((string) ($params['source_language'] ?? ''));
            if ($source_language === '') $source_language = sanitize_key((string) pll_get_post_language($post_id, 'slug'));
            $linked_posts[$source_language] = $post_id;
            foreach ($translations as $language => $translation) {
                $language = sanitize_key((string) $language);
                if ($language === '' || $language === $source_language || !in_array($language, $available_languages, true) || !is_array($translation)) {
                    return new WP_Error('invalid_refresh_translation', 'Refresh translation language is invalid', array('status' => 400));
                }
                $translated_title = trim(sanitize_text_field((string) ($translation['post_title'] ?? '')));
                $translated_content = $this->sanitize_post_content((string) ($translation['post_content'] ?? ''));
                if ($translated_title === '' || is_wp_error($translated_content) || trim((string) $translated_content) === '') {
                    return new WP_Error('invalid_refresh_translation', 'Translated title and content are required', array('status' => 400));
                }
                $translated_faq_items = $this->sanitize_faq_schema_items($translation['faq'] ?? array());
                $translated_faq_title = sanitize_text_field((string) ($translation['faq_title'] ?? 'FAQ'));
                $faq_metabox_enabled = (bool) get_option(AI_CRAFT_POST_FAQ_METABOX_ENABLED_OPTION, false);
                if (!empty($translated_faq_items) && !$faq_metabox_enabled) {
                    $translated_content = rtrim((string) $translated_content) . "\n\n" . $this->render_faq_content($translated_faq_items, $translated_faq_title);
                }
                $translated_post_id = intval($linked_posts[$language] ?? 0);
                $translated_post = $translated_post_id > 0 ? get_post($translated_post_id) : null;
                if (!empty($params['preserve_inline_images']) && $translated_post instanceof WP_Post) {
                    $translated_images = array();
                    if (preg_match_all('/<figure\b[^>]*>.*?<img\b[^>]*>.*?<\/figure>/isu', (string) $translated_post->post_content, $translated_image_matches)) {
                        $translated_images = array_values(array_filter(array_map('trim', $translated_image_matches[0])));
                    }
                    if (!empty($translated_images)) {
                        $translated_image_index = 0;
                        $translated_content = preg_replace_callback('/<h[23]\b[^>]*>.*?<\/h[23]>/isu', function ($heading) use (&$translated_image_index, $translated_images) {
                            if (!isset($translated_images[$translated_image_index])) return $heading[0];
                            return $heading[0] . "\n" . $translated_images[$translated_image_index++];
                        }, $translated_content);
                        if ($translated_image_index < count($translated_images)) {
                            $translated_content = rtrim($translated_content) . "\n\n" . implode("\n", array_slice($translated_images, $translated_image_index));
                        }
                    }
                }
                $translated_post_data = array(
                    'post_title' => $translated_title,
                    'post_content' => $translated_content,
                    'post_excerpt' => sanitize_textarea_field((string) ($translation['post_excerpt'] ?? '')),
                    'post_status' => $refresh_post_status,
                    'post_author' => $refresh_author_id,
                );
                if ($change_post_url) {
                    $translated_post_data['post_name'] = sanitize_title($translated_title);
                }
                if ($update_publication_date) {
                    $translated_post_data['post_date'] = current_time('mysql');
                    $translated_post_data['post_date_gmt'] = current_time('mysql', true);
                }
                if ($translated_post instanceof WP_Post) {
                    $translated_post_data['ID'] = $translated_post_id;
                    $saved_translation_id = wp_update_post($translated_post_data, true);
                } else {
                    $translated_post_data['post_type'] = $post->post_type;
                    $saved_translation_id = wp_insert_post($translated_post_data, true);
                }
                if (is_wp_error($saved_translation_id)) {
                    return new WP_Error('refresh_translation_save_failed', $saved_translation_id->get_error_message(), array('status' => 500));
                }
                $translated_post_id = intval($saved_translation_id);
                pll_set_post_language($translated_post_id, $language);
                if ($featured_image_id > 0) {
                    set_post_thumbnail($translated_post_id, $featured_image_id);
                }
                $translated_categories = array();
                foreach (wp_get_post_categories($post_id) as $category_id) {
                    $translated_category_id = function_exists('pll_get_term') ? intval(pll_get_term($category_id, $language)) : 0;
                    if ($translated_category_id > 0) $translated_categories[] = $translated_category_id;
                }
                if (!empty($translated_categories)) wp_set_post_categories($translated_post_id, $translated_categories, false);
                $translated_seo = array();
                if (!empty($translation['seo_title'])) $translated_seo['seo_title'] = sanitize_text_field((string) $translation['seo_title']);
                if (!empty($translation['seo_description'])) $translated_seo['seo_description'] = sanitize_text_field((string) $translation['seo_description']);
                if (!empty($translated_seo)) $this->write_seo_meta($translated_post_id, $translated_seo);
                if (!empty($translated_faq_items) && $faq_metabox_enabled) {
                    update_post_meta($translated_post_id, AI_CRAFT_POST_FAQ_SCHEMA_META_KEY, $translated_faq_items);
                    update_post_meta($translated_post_id, AI_CRAFT_POST_FAQ_SCHEMA_TITLE_META_KEY, $translated_faq_title !== '' ? $translated_faq_title : 'FAQ');
                }
                update_post_meta($translated_post_id, '_ai_craft_post_refresh_job_id', $refresh_job_id);
                $linked_posts[$language] = $translated_post_id;
                $translated_posts[$language] = array('post_id' => $translated_post_id, 'post_url' => $this->get_post_response_url($translated_post_id));
            }
            pll_save_post_translations($linked_posts);
        }

        update_post_meta($post_id, '_ai_craft_post_refresh_job_id', $refresh_job_id);

        return rest_ensure_response(array(
            'success' => true,
            'already_applied' => false,
            'post_id' => $post_id,
            'post_url' => $this->get_post_response_url($post_id),
            'applied_operations' => $applied,
            'skipped_operations' => $skipped,
            'featured_image_id' => $featured_image_id,
            'optimized_images' => $optimized_images,
            'seo' => $seo_result,
            'translations' => $translated_posts,
            'message' => 'Approved article refresh applied successfully',
        ));
    }

    /**
     * Validate article payload shape.
     */
    private function validate_article_payload($params)
    {
        $allowed_keys = array(
            'post_id',
            'post_title',
            'post_content',
            'post_excerpt',
            'post_status',
            'post_type',
            'author_id',
            'categories',
            'tags',
            'post_template',
            'comments_status',
            'queue_item_id',
            'article_job_id',
            'image_url',
            'featured_image_alt',
            'section_image_packs',
            'seo_title',
            'seo_description',
            'seo_focus_keyword',
            'keyword',
            'metadesc',
            'language',
            'source_language',
            'translation_group_key',
            'translation_languages',
            'is_translation',
            'translations',
            'faq',
            'faq_title',
            'meta_input',
            'post_meta',
        );

        foreach (array_keys($params) as $key) {
            if (!in_array($key, $allowed_keys, true)) {
                return new WP_Error('unexpected_payload_field', 'Unexpected payload field: ' . sanitize_key($key), array('status' => 400));
            }
        }

        if (empty($params['post_title']) || !is_string($params['post_title'])) {
            return new WP_Error('missing_title', 'Post title is required', array('status' => 400));
        }

        if (empty($params['post_content']) || !is_string($params['post_content'])) {
            return new WP_Error('missing_content', 'Post content is required', array('status' => 400));
        }

        $status = sanitize_key($params['post_status'] ?? 'draft');
        if (!in_array($status, array('draft', 'publish', 'private', 'pending'), true)) {
            return new WP_Error('invalid_post_status', 'Post status is not allowed', array('status' => 400));
        }

        $post_type = sanitize_key($params['post_type'] ?? 'post');
        if (!$this->is_allowed_post_type($post_type)) {
            return new WP_Error('invalid_post_type', 'Post type is not allowed', array('status' => 400));
        }

        if (!empty($params['author_id']) && !get_user_by('id', intval($params['author_id']))) {
            return new WP_Error('invalid_author', 'Author does not exist', array('status' => 400));
        }

        if (!empty($params['categories']) && is_array($params['categories'])) {
            foreach ($params['categories'] as $category_id) {
                if (!is_numeric($category_id) || !term_exists(intval($category_id), 'category')) {
                    return new WP_Error('invalid_category', 'Category does not exist', array('status' => 400));
                }
            }
        }

        if (!empty($params['image_url']) && esc_url_raw($params['image_url']) === '') {
            return new WP_Error('invalid_image_url', 'Image URL is invalid', array('status' => 400));
        }

        if (!empty($params['translation_group_key']) && function_exists('pll_languages_list')) {
            $language = sanitize_key((string) ($params['language'] ?? ''));
            $available_languages = pll_languages_list(array('fields' => 'slug'));
            if ($language !== '' && is_array($available_languages) && !in_array($language, $available_languages, true)) {
                return new WP_Error('invalid_polylang_language', 'Language is not configured in Polylang', array('status' => 400));
            }
        }

        return true;
    }

    /**
     * Check if the post type can be written by this plugin.
     */
    private function is_allowed_post_type($post_type)
    {
        $object = get_post_type_object($post_type);
        return $object && !empty($object->public) && !in_array($post_type, array('attachment', 'revision', 'nav_menu_item'), true);
    }

    /**
     * Sanitize generated post content.
     */
    private function sanitize_post_content($content)
    {
        if (preg_match('/<\s*script\b/i', $content)) {
            return new WP_Error('script_not_allowed', 'Script tags are not allowed', array('status' => 400));
        }

        if (preg_match('/\son[a-z]+\s*=/i', $content)) {
            return new WP_Error('event_handlers_not_allowed', 'Inline event handlers are not allowed', array('status' => 400));
        }

        if (preg_match('/javascript\s*:/i', $content)) {
            return new WP_Error('javascript_urls_not_allowed', 'JavaScript URLs are not allowed', array('status' => 400));
        }

        if (preg_match('/<\s*(iframe|object|embed)\b/i', $content)) {
            return new WP_Error('embed_not_allowed', 'Embedded objects are not allowed', array('status' => 400));
        }

        return wp_kses_post($content);
    }

    /**
     * Sanitize FAQ schema items from structured webhook payloads.
     */
    private function sanitize_faq_schema_items($items)
    {
        if (!is_array($items)) {
            return array();
        }

        $clean_items = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = isset($item['question']) ? sanitize_text_field((string) $item['question']) : '';
            $answer = isset($item['answer']) ? wp_kses_post((string) $item['answer']) : '';
            $answer = trim($answer);

            if ($question === '' || $answer === '') {
                continue;
            }

            $clean_items[] = array(
                'question' => $question,
                'answer' => $answer,
            );
        }

        return $clean_items;
    }

    /**
     * Render structured FAQ items as regular post content.
     */
    private function render_faq_content($items, $title = '')
    {
        $items = $this->sanitize_faq_schema_items($items);
        if (empty($items)) {
            return '';
        }

        $title = trim(sanitize_text_field((string) $title));
        $title = $title !== '' ? $title : 'FAQ';
        $html = '<!-- wp:heading --><h2>' . esc_html($title) . '</h2><!-- /wp:heading -->';
        foreach ($items as $item) {
            $html .= '<!-- wp:heading {"level":3} --><h3>' . esc_html($item['question']) . '</h3><!-- /wp:heading -->';
            $html .= '<!-- wp:paragraph -->' . wpautop(wp_kses_post($item['answer'])) . '<!-- /wp:paragraph -->';
        }

        return $html;
    }

    /**
     * Check whether a post already has FAQ meta or an FAQ section in content.
     */
    private function post_has_faq($post_id, $content)
    {
        $meta_items = get_post_meta(intval($post_id), AI_CRAFT_POST_FAQ_SCHEMA_META_KEY, true);
        if (!empty($meta_items) && is_array($meta_items)) {
            return true;
        }

        if (function_exists('ai_craft_post_extract_faq_items_from_content')) {
            $extracted = ai_craft_post_extract_faq_items_from_content((string) $content);
            if (!empty($extracted['items']) && is_array($extracted['items'])) {
                return true;
            }
        }

        if (!preg_match_all('/<h[2-4]\b[^>]*>(.*?)<\/h[2-4]>/isu', (string) $content, $matches)) {
            return false;
        }
        foreach ($matches[1] as $heading) {
            $heading = mb_strtolower(trim(wp_strip_all_tags((string) $heading)));
            if (preg_match('/\bfaq\b|част[іи]\s+питан|поширен[іи]\s+питан|питан(?:ня)?\s+та\s+відповід|questions?\s+(and|&)\s+answers?/ui', $heading)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set Polylang language and link posts from the same translation group.
     */
    private function apply_polylang_translation_meta($post_id, $params)
    {
        $group_key = isset($params['translation_group_key']) ? sanitize_text_field((string) $params['translation_group_key']) : '';
        $language = isset($params['language']) ? sanitize_key((string) $params['language']) : '';
        if ($group_key === '' || $language === '') {
            return array(
                'enabled' => false,
            );
        }

        update_post_meta($post_id, '_ai_craft_post_translation_group_key', $group_key);
        update_post_meta($post_id, '_ai_craft_post_language', $language);
        update_post_meta($post_id, '_ai_craft_post_is_translation', !empty($params['is_translation']) ? 1 : 0);
        if (!empty($params['source_language'])) {
            update_post_meta($post_id, '_ai_craft_post_source_language', sanitize_key((string) $params['source_language']));
        }
        if (!empty($params['translation_languages']) && is_array($params['translation_languages'])) {
            $languages = array();
            foreach ($params['translation_languages'] as $item) {
                $item = sanitize_key((string) $item);
                if ($item !== '') {
                    $languages[] = $item;
                }
            }
            update_post_meta($post_id, '_ai_craft_post_translation_languages', array_values(array_unique($languages)));
        }

        if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
            return array(
                'enabled' => false,
                'group_key' => $group_key,
                'language' => $language,
            );
        }

        pll_set_post_language($post_id, $language);

        $query = new WP_Query(array(
            'post_type' => get_post_type($post_id),
            'post_status' => array('publish', 'draft', 'pending', 'future', 'private'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Translation groups require an exact indexed post-meta lookup.
            'meta_query' => array(
                array(
                    'key' => '_ai_craft_post_translation_group_key',
                    'value' => $group_key,
                ),
            ),
        ));

        $translations = array();
        foreach ((array) $query->posts as $sibling_id) {
            $sibling_language = (string) get_post_meta($sibling_id, '_ai_craft_post_language', true);
            $sibling_language = sanitize_key($sibling_language);
            if ($sibling_language === '') {
                continue;
            }
            $translations[$sibling_language] = intval($sibling_id);
        }

        if (!empty($translations)) {
            pll_save_post_translations($translations);
        }

        return array(
            'enabled' => true,
            'group_key' => $group_key,
            'language' => $language,
            'linked_languages' => array_keys($translations),
        );
    }

    /**
     * Create translated posts from a batch article payload.
     */
    private function create_translation_posts($params)
    {
        if (empty($params['translations']) || !is_array($params['translations'])) {
            return array();
        }

        $created = array();
        foreach ($params['translations'] as $language => $translation) {
            if (!is_array($translation)) {
                continue;
            }

            $language = sanitize_key((string) $language);
            if ($language === '') {
                continue;
            }

            $translation['language'] = $language;
            $translation['source_language'] = sanitize_key((string) ($params['source_language'] ?? $params['language'] ?? ''));
            $translation['translation_group_key'] = sanitize_text_field((string) ($params['translation_group_key'] ?? ''));
            $translation['translation_languages'] = $params['translation_languages'] ?? array();
            $translation['is_translation'] = true;
            $translation['post_status'] = $translation['post_status'] ?? ($params['post_status'] ?? 'draft');
            $translation['post_type'] = $translation['post_type'] ?? ($params['post_type'] ?? 'post');
            $translation['author_id'] = $translation['author_id'] ?? ($params['author_id'] ?? 1);
            $translation['categories'] = $translation['categories'] ?? ($params['categories'] ?? array());
            $translation['tags'] = $translation['tags'] ?? ($params['tags'] ?? array());
            $translation['article_job_id'] = $params['article_job_id'] ?? 0;

            unset($translation['translations']);

            $request = new class($translation) {
                private $params;

                public function __construct($params)
                {
                    $this->params = $params;
                }

                public function get_json_params()
                {
                    return $this->params;
                }
            };
            $response = $this->process_article($request);
            if ($response instanceof WP_REST_Response) {
                $data = $response->get_data();
                $created[$language] = array(
                    'post_id' => intval($data['post_id'] ?? 0),
                    'post_url' => (string) ($data['post_url'] ?? ''),
                );
            } elseif (is_wp_error($response)) {
                $created[$language] = array(
                    'error' => $response->get_error_message(),
                );
            }
        }

        return $created;
    }

    /**
     * Assign existing categories to the post.
     */
    private function assign_categories($post_id, $categories)
    {
        if (empty($categories)) {
            return;
        }

        if (is_string($categories)) {
            $categories = array_filter(array_map('trim', explode(',', $categories)));
        }

        if (!is_array($categories)) {
            return;
        }

        $category_ids = array();
        $post_language = function_exists('pll_get_post_language') ? (string) pll_get_post_language($post_id) : '';
        foreach ($categories as $category) {
            if (is_numeric($category) && term_exists(intval($category), 'category')) {
                $category_id = intval($category);
                if ($post_language !== '' && function_exists('pll_get_term')) {
                    $translated_id = intval(pll_get_term($category_id, $post_language));
                    if ($translated_id > 0) {
                        $category_id = $translated_id;
                    }
                }
                $category_ids[] = $category_id;
            }
        }

        if (!empty($category_ids)) {
            wp_set_post_categories($post_id, array_values(array_unique($category_ids)));
        }
    }

    /**
     * Assign post tags without creating unknown terms.
     */
    private function assign_tags($post_id, $tags)
    {
        if (empty($tags)) {
            return;
        }

        if (is_string($tags)) {
            $tags = array_filter(array_map('trim', explode(',', $tags)));
        }

        if (!is_array($tags)) {
            return;
        }

        $tag_ids = array();
        $post_language = function_exists('pll_get_post_language') ? (string) pll_get_post_language($post_id) : '';
        foreach ($tags as $tag) {
            if (is_numeric($tag) && term_exists(intval($tag), 'post_tag')) {
                $tag_id = intval($tag);
                if ($post_language !== '' && function_exists('pll_get_term')) {
                    $translated_id = intval(pll_get_term($tag_id, $post_language));
                    if ($translated_id > 0) {
                        $tag_id = $translated_id;
                    }
                }
                $tag_ids[] = $tag_id;
                continue;
            }

            $term = get_term_by('name', sanitize_text_field($tag), 'post_tag');
            if ($term) {
                $tag_ids[] = intval($term->term_id);
            }
        }

        if (!empty($tag_ids)) {
            wp_set_post_terms($post_id, array_values(array_unique($tag_ids)), 'post_tag', false);
        }
    }

    /**
     * Upload and assign the featured image.
     */
    private function upload_featured_image($post_id, $image_url, $post_title, $featured_image_alt = '')
    {
        $image_handler = AI_Craft_Post_Image_Handler::get_instance();
        $attachment_id = $image_handler->upload_image_from_url($image_url, $post_id, $post_title, true);

        if (is_wp_error($attachment_id)) {
            return 0;
        }

        $featured_image_alt = trim(sanitize_text_field($featured_image_alt));
        if ($featured_image_alt !== '') {
            update_post_meta(intval($attachment_id), '_wp_attachment_image_alt', $featured_image_alt);
        }

        return intval($attachment_id);
    }

    /**
     * Write simple custom post meta fields.
     */
    private function write_custom_meta($post_id, $meta)
    {
        if (empty($meta) || !is_array($meta)) {
            return;
        }

        foreach ($meta as $key => $value) {
            $key = sanitize_key($key);
            if ($key === '' || strpos($key, '_') === 0) {
                continue;
            }

            if (is_scalar($value)) {
                update_post_meta($post_id, $key, sanitize_text_field((string) $value));
                continue;
            }

            if (is_array($value)) {
                update_post_meta($post_id, $key, array_map('sanitize_text_field', array_map('strval', $value)));
            }
        }
    }

    /**
     * Download section image packs.
     */
    private function process_section_image_packs($post_id, $section_image_packs, $post_title, $language = '')
    {
        $result = array(
            'received_sections' => 0,
            'downloaded_images' => 0,
            'failed_images' => 0,
            'packs' => array(),
        );

        if (empty($section_image_packs) || !is_array($section_image_packs)) {
            return $result;
        }

        $image_handler = AI_Craft_Post_Image_Handler::get_instance();
        $result['received_sections'] = count($section_image_packs);

        foreach ($section_image_packs as $section_index => $section_pack) {
            if (!is_array($section_pack)) {
                continue;
            }

            $section_title = sanitize_text_field($section_pack['section_title'] ?? ('Section ' . ($section_index + 1)));
            $heading_level = intval($section_pack['heading_level'] ?? 2);
            if (!in_array($heading_level, array(2, 3), true)) {
                $heading_level = 2;
            }

            $section_result = array(
                'section_index' => intval($section_pack['section_index'] ?? $section_index),
                'section_title' => $section_title,
                'heading_level' => $heading_level,
                'images' => array(),
            );

            $images = $section_pack['images'] ?? array();
            if (!is_array($images)) {
                $result['packs'][] = $section_result;
                continue;
            }

            $image_count = 0;
            foreach ($images as $candidate_image) {
                if (is_array($candidate_image) && esc_url_raw($candidate_image['image_url'] ?? '') !== '') {
                    $image_count++;
                }
            }
            $image_meta_position = 0;
            foreach ($images as $image_position => $image_item) {
                if (!is_array($image_item)) {
                    continue;
                }

                $remote_url = esc_url_raw($image_item['image_url'] ?? '');
                $variant = sanitize_key($image_item['variant'] ?? 'variant');
                $size = sanitize_text_field($image_item['size'] ?? '1024x1024');
                $prompt = sanitize_text_field($image_item['prompt'] ?? '');

                if ($remote_url === '') {
                    $result['failed_images']++;
                    $section_result['images'][] = array(
                        'variant' => $variant,
                        'size' => $size,
                        'prompt' => $prompt,
                        'remote_url' => '',
                        'status' => 'skipped_empty_url',
                    );
                    continue;
                }

                $image_meta_position++;
                $attachment_title = $this->section_image_meta_text($section_title, $post_title, $image_meta_position, $language, $image_count);
                $attachment_id = $image_handler->upload_image_from_url($remote_url, $post_id, $attachment_title, false);

                if ($attachment_id && !is_wp_error($attachment_id)) {
                    $result['downloaded_images']++;
                    $section_result['images'][] = array(
                        'variant' => $variant,
                        'size' => $size,
                        'prompt' => $prompt,
                        'remote_url' => $remote_url,
                        'status' => 'downloaded',
                        'attachment_id' => intval($attachment_id),
                        'local_url' => wp_get_attachment_url($attachment_id),
                        'alt_text' => $attachment_title,
                        'title' => $attachment_title,
                    );
                } else {
                    $result['failed_images']++;
                    $section_result['images'][] = array(
                        'variant' => $variant,
                        'size' => $size,
                        'prompt' => $prompt,
                        'remote_url' => $remote_url,
                        'status' => 'failed',
                    );
                }
            }

            $result['packs'][] = $section_result;
        }

        update_post_meta($post_id, 'ai_craft_post_section_image_packs', $result['packs']);

        return $result;
    }

    /**
     * Insert section image blocks below matching headings.
     */
    private function inject_section_images_into_content($content, $packs)
    {
        if (empty($content) || empty($packs) || !is_array($packs)) {
            return $content;
        }

        $normalized_packs = array();
        foreach ($packs as $pack) {
            $normalized_packs[] = array(
                'used' => false,
                'section_title' => $this->normalize_heading_text($pack['section_title'] ?? ''),
                'heading_level' => intval($pack['heading_level'] ?? 2),
                'section_index' => intval($pack['section_index'] ?? count($normalized_packs)),
                'images' => is_array($pack['images'] ?? null) ? $pack['images'] : array(),
            );
        }

        $heading_position = -1;
        $updated = preg_replace_callback('/((<!--\s*wp:heading(?:\s+\{.*?\})?\s*-->\s*)?<h([23])\b[^>]*>(.*?)<\/h\3>(\s*<!--\s*\/wp:heading\s*-->)?)(.*?)(?=(?:<!--\s*wp:heading(?:\s+\{.*?\})?\s*-->\s*)?<h[23]\b|$)/uis', function ($matches) use (&$normalized_packs, &$heading_position) {
            $heading_block = $matches[1];
            $section_content = $matches[6] ?? '';
            $heading_position++;
            $heading_level = intval($matches[3] ?? 2);
            $heading_text = $this->normalize_heading_text($matches[4] ?? '');
            $pack_index = -1;

            if (preg_match('/<img\b/i', $section_content)) {
                return $heading_block . $section_content;
            }

            foreach ($normalized_packs as $idx => $pack) {
                if (!$pack['used'] && intval($pack['heading_level']) === $heading_level && intval($pack['section_index']) <= $heading_position) {
                    $pack_index = $idx;
                    break;
                }
            }

            if ($pack_index < 0) {
                foreach ($normalized_packs as $idx => $pack) {
                    if (!$pack['used'] && intval($pack['heading_level']) === $heading_level && $pack['section_title'] === $heading_text) {
                        $pack_index = $idx;
                        break;
                    }
                }
            }

            if ($pack_index < 0) {
                return $heading_block . $section_content;
            }

            $normalized_packs[$pack_index]['used'] = true;
            $images_block = $this->build_section_images_block($normalized_packs[$pack_index]['images'], $heading_text);

            return $images_block === '' ? $heading_block . $section_content : $heading_block . $images_block . $section_content;
        }, $content);

        return is_string($updated) ? $updated : $content;
    }

    /**
     * Build Gutenberg image blocks.
     */
    private function build_section_images_block($images, $heading_text)
    {
        if (empty($images) || !is_array($images)) {
            return '';
        }

        $output = '';
        foreach ($images as $item) {
            $local_url = !empty($item['local_url']) ? esc_url($item['local_url']) : '';
            $attachment_id = intval($item['attachment_id'] ?? 0);
            if (($item['status'] ?? '') !== 'downloaded' || $local_url === '' || !$attachment_id) {
                continue;
            }

            $variant = sanitize_html_class($item['variant'] ?? 'variant');
            $alt_text = sanitize_text_field($item['alt_text'] ?? ($heading_text ?: 'Section image'));
            $alt = esc_attr($alt_text);
            $output .= '<!-- wp:image {"id":' . $attachment_id . ',"sizeSlug":"large","linkDestination":"none","className":"ai-craft-post-section-image ' . $variant . '"} -->';
            $output .= '<figure class="wp-block-image size-large ai-craft-post-section-image ' . $variant . '">';
            $output .= '<img src="' . $local_url . '" alt="' . $alt . '" class="wp-image-' . $attachment_id . '"/>';
            $output .= '</figure><!-- /wp:image -->';
        }

        return $output;
    }

    /**
     * Build unique media title/alt text for repeated images under the same heading.
     */
    private function section_image_meta_text($section_title, $post_title, $image_position, $language = '', $image_count = 1)
    {
        $base = trim(sanitize_text_field($section_title !== '' ? $section_title : $post_title));
        if ($base === '') {
            $base = 'Section image';
        }

        $suffix_number = max(1, intval($image_position));
        $image_word = $this->localized_image_word($language);
        $suffix = intval($image_count) > 1 ? $image_word . ' ' . $suffix_number : $image_word;

        return trim($base . ' - ' . $suffix);
    }

    /**
     * Localize the image noun used in media titles and alt text.
     */
    private function localized_image_word($language)
    {
        $language = strtolower(sanitize_key((string) $language));
        $words = array(
            'uk' => 'картинка',
            'ru' => 'картинка',
            'pl' => 'obraz',
            'de' => 'Bild',
            'fr' => 'image',
            'it' => 'immagine',
            'es' => 'imagen',
            'pt' => 'imagem',
            'ja' => '画像',
            'ko' => '이미지',
            'he' => 'תמונה',
            'en' => 'image',
        );

        return $words[$language] ?? 'image';
    }

    /**
     * Write SEO fields for the detected SEO provider.
     */
    private function write_seo_meta($post_id, $params)
    {
        $provider = ai_craft_post_detect_seo_provider();
        $written = array();

        if ($provider === 'none') {
            return array(
                'provider' => 'none',
                'written_meta_keys' => $written,
            );
        }

        $seo_title = trim(sanitize_text_field($params['seo_title'] ?? ''));
        $seo_description = trim(sanitize_text_field($params['seo_description'] ?? ($params['metadesc'] ?? '')));
        $seo_focus_keyword = $this->normalize_focus_keyword($params['seo_focus_keyword'] ?? ($params['keyword'] ?? ''));
        $write_title = (bool) get_option(AI_CRAFT_POST_SEO_WRITE_TITLE_OPTION, true);
        $write_description = (bool) get_option(AI_CRAFT_POST_SEO_WRITE_DESCRIPTION_OPTION, true);
        $write_keyword = (bool) get_option(AI_CRAFT_POST_SEO_WRITE_KEYWORD_OPTION, true);

        $site_name = trim(sanitize_text_field(get_bloginfo('name')));
        if ($seo_title !== '' && $write_title && $site_name !== '') {
            $seo_title .= ' - ' . $site_name;
        }

        if ($provider === 'yoast') {
            if ($seo_title !== '' && $write_title) {
                update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
                $written[] = '_yoast_wpseo_title';
            }
            if ($seo_description !== '' && $write_description) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $seo_description);
                $written[] = '_yoast_wpseo_metadesc';
            }
            if ($seo_focus_keyword !== '' && $write_keyword) {
                update_post_meta($post_id, '_yoast_wpseo_focuskw', $seo_focus_keyword);
                $written[] = '_yoast_wpseo_focuskw';
            }
        }

        if ($provider === 'rank_math') {
            if ($seo_title !== '' && $write_title) {
                update_post_meta($post_id, 'rank_math_title', $seo_title);
                $written[] = 'rank_math_title';
            }
            if ($seo_description !== '' && $write_description) {
                update_post_meta($post_id, 'rank_math_description', $seo_description);
                $written[] = 'rank_math_description';
            }
            if ($seo_focus_keyword !== '' && $write_keyword) {
                update_post_meta($post_id, 'rank_math_focus_keyword', $seo_focus_keyword);
                $written[] = 'rank_math_focus_keyword';
            }
        }

        if ($provider === 'aioseo') {
            global $wpdb;

            $table = $wpdb->prefix . 'aioseo_posts';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- AIOSEO metadata is stored in its custom table and must be read at write time.
            $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

            if ($table_exists === $table) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Column detection supports multiple installed AIOSEO schema versions.
                $columns = $wpdb->get_col($wpdb->prepare('DESC %i', $table), 0);
                $data = array();

                if ($seo_title !== '' && $write_title && in_array('title', $columns, true)) {
                    $data['title'] = $seo_title;
                }
                if ($seo_description !== '' && $write_description && in_array('description', $columns, true)) {
                    $data['description'] = $seo_description;
                }
                if ($seo_focus_keyword !== '' && $write_keyword && in_array('keyphrases', $columns, true)) {
                    $data['keyphrases'] = wp_json_encode(array(
                        'focus' => array(
                            'keyphrase' => $seo_focus_keyword,
                            'score' => 0,
                            'analysis' => array(),
                        ),
                        'additional' => array(),
                    ));
                }

                if (!empty($data)) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The current AIOSEO row is required before an update or insert.
                    $existing_id = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE post_id = %d LIMIT 1', $table, $post_id));
                    $now = current_time('mysql');

                    if ($existing_id) {
                        if (in_array('updated', $columns, true)) {
                            $data['updated'] = $now;
                        }
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This writes the third-party AIOSEO custom table.
                        $wpdb->update($table, $data, array('id' => intval($existing_id)));
                    } else {
                        $data['post_id'] = intval($post_id);
                        if (in_array('created', $columns, true)) {
                            $data['created'] = $now;
                        }
                        if (in_array('updated', $columns, true)) {
                            $data['updated'] = $now;
                        }
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This writes the third-party AIOSEO custom table.
                        $wpdb->insert($table, $data);
                    }

                    foreach (array('title', 'description', 'keyphrases') as $aioseo_key) {
                        if (array_key_exists($aioseo_key, $data)) {
                            $written[] = 'aioseo_posts.' . $aioseo_key;
                        }
                    }
                }
            }
        }

        return array(
            'provider' => $provider,
            'written_meta_keys' => $written,
            'focus_keyword' => $seo_focus_keyword,
            'write_fields' => array(
                'title' => $write_title,
                'description' => $write_description,
                'keyword' => $write_keyword,
            ),
        );
    }

    /**
     * Keep one SEO focus keyphrase from a comma-separated keyword list.
     */
    private function normalize_focus_keyword($keyword)
    {
        if (is_array($keyword)) {
            $keyword = reset($keyword);
        }

        $keyword = sanitize_text_field((string) $keyword);
        $parts = explode(',', $keyword, 2);
        $keyword = trim((string) $parts[0]);
        $keyword = preg_replace('/\s+/u', ' ', $keyword);

        return trim((string) $keyword);
    }

    /**
     * Build a stable post URL.
     */
    private function get_post_response_url($post_id)
    {
        $permalink = get_permalink($post_id);
        if (is_string($permalink) && $permalink !== '') {
            return $permalink;
        }

        return add_query_arg('p', intval($post_id), home_url('/'));
    }

    /**
     * Normalize heading text for section matching.
     */
    private function normalize_heading_text($text)
    {
        $clean = wp_strip_all_tags(html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $clean = preg_replace('/\s+/u', ' ', $clean);

        return trim(function_exists('mb_strtolower') ? mb_strtolower((string) $clean) : strtolower((string) $clean));
    }
}
