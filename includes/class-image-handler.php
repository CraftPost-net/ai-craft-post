<?php
/**
 * CraftPost Site Connector image handler.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('download_url')) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}
if (!function_exists('wp_handle_sideload')) {
    require_once ABSPATH . 'wp-admin/includes/media.php';
}
if (!function_exists('wp_get_image_editor') || !function_exists('wp_generate_attachment_metadata')) {
    $ai_craft_post_image_api_file = ABSPATH . 'wp-admin/includes/image.php';
    if (is_readable($ai_craft_post_image_api_file)) {
        require_once $ai_craft_post_image_api_file;
    }
}

/**
 * Handles media library sideloads.
 */
class AI_Craft_Post_Image_Handler
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
     * Upload an image from a remote URL to the media library.
     */
    public function upload_image_from_url($image_url, $post_id, $attachment_title, $set_as_featured = false)
    {
        $image_url = esc_url_raw($image_url);
        if ($image_url === '') {
            return new WP_Error('empty_image_url', 'Image URL is empty', array('status' => 400));
        }

        $temp_file = download_url($image_url);
        if (is_wp_error($temp_file)) {
            return $temp_file;
        }

        $editor = function_exists('wp_get_image_editor') ? wp_get_image_editor($temp_file) : null;
        if ($editor && !is_wp_error($editor)) {
            $quality = 82;
            $max_width = $set_as_featured ? 1600 : 1200;
            $size = $editor->get_size();

            $editor->set_quality($quality);

            if (!empty($size['width']) && intval($size['width']) > $max_width) {
                $editor->resize($max_width, null, false);
            }

            $saved = $editor->save($temp_file);
            if (!is_wp_error($saved) && !empty($saved['path'])) {
                $temp_file = $saved['path'];
            }
        }

        $attachment_title = sanitize_text_field($attachment_title);
        $post_slug = sanitize_title($attachment_title);
        $url_path = wp_parse_url($image_url, PHP_URL_PATH);
        $extension = pathinfo((string) $url_path, PATHINFO_EXTENSION);
        $extension = $extension ? strtolower(sanitize_key($extension)) : 'jpg';
        $filename = ($post_slug !== '' ? $post_slug : 'ai-craft-post-image') . '.' . $extension;
        $filetype = wp_check_filetype($filename);

        $file = array(
            'name' => $filename,
            'type' => $filetype['type'],
            'tmp_name' => $temp_file,
            'error' => 0,
            'size' => filesize($temp_file),
        );

        $file_info = wp_handle_sideload($file, array('test_form' => false));
        if (!empty($file_info['error'])) {
            wp_delete_file($temp_file);
            return new WP_Error('image_sideload_failed', $file_info['error'], array('status' => 500));
        }

        $attachment = array(
            'post_mime_type' => $file_info['type'],
            'post_title' => $attachment_title,
            'post_name' => $post_slug,
            'post_content' => '',
            'post_status' => 'inherit',
        );

        $attachment_id = wp_insert_attachment($attachment, $file_info['file'], $post_id);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        if (function_exists('wp_generate_attachment_metadata')) {
            $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_info['file']);
            if (!is_wp_error($attachment_data) && !empty($attachment_data)) {
                wp_update_attachment_metadata($attachment_id, $attachment_data);
            }
        }
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $attachment_title);

        if ($set_as_featured) {
            set_post_thumbnail($post_id, $attachment_id);
        }

        clean_post_cache($post_id);

        return $attachment_id;
    }

    /**
     * Optimize local attachment images referenced by an article.
     */
    public function optimize_post_images($post_id, $content)
    {
        $attachment_ids = array();
        $featured_image_id = intval(get_post_thumbnail_id($post_id));
        if ($featured_image_id > 0) {
            $attachment_ids[$featured_image_id] = true;
        }

        if (preg_match_all('/\bwp-image-(\d+)\b/i', (string) $content, $id_matches)) {
            foreach ($id_matches[1] as $attachment_id) {
                $attachment_id = intval($attachment_id);
                if ($attachment_id > 0) {
                    $attachment_ids[$attachment_id] = true;
                }
            }
        }

        if (preg_match_all('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', (string) $content, $src_matches)) {
            foreach ($src_matches[1] as $image_url) {
                $attachment_id = intval(attachment_url_to_postid(esc_url_raw($image_url)));
                if ($attachment_id > 0) {
                    $attachment_ids[$attachment_id] = true;
                }
            }
        }

        $result = array(
            'found_images' => count($attachment_ids),
            'optimized_images' => 0,
            'failed_images' => 0,
        );

        if (!function_exists('wp_get_image_editor') || !function_exists('wp_generate_attachment_metadata')) {
            $result['optimization_skipped'] = !function_exists('wp_get_image_editor')
                ? 'wp_get_image_editor_unavailable'
                : 'wp_generate_attachment_metadata_unavailable';
            return $result;
        }

        foreach (array_keys($attachment_ids) as $attachment_id) {
            if (!wp_attachment_is_image($attachment_id)) {
                $result['failed_images']++;
                continue;
            }

            $file_path = get_attached_file($attachment_id);
            if (!$file_path || !is_file($file_path)) {
                $result['failed_images']++;
                continue;
            }

            $editor = wp_get_image_editor($file_path);
            if (is_wp_error($editor)) {
                $result['failed_images']++;
                continue;
            }

            $max_width = $attachment_id === $featured_image_id ? 1600 : 1200;
            $size = $editor->get_size();
            $editor->set_quality(82);
            if (!empty($size['width']) && intval($size['width']) > $max_width) {
                $editor->resize($max_width, null, false);
            }

            $saved = $editor->save($file_path);
            if (is_wp_error($saved)) {
                $result['failed_images']++;
                continue;
            }

            $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
            if (is_wp_error($attachment_data) || empty($attachment_data)) {
                $result['failed_images']++;
                continue;
            }

            wp_update_attachment_metadata($attachment_id, $attachment_data);
            clean_post_cache($attachment_id);
            $result['optimized_images']++;
        }

        return $result;
    }
}
