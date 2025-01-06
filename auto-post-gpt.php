<?php
/**
 * Plugin Name: Auto Post with ChatGPT
 * Description: A WordPress plugin that automatically generates blog posts using ChatGPT and includes AI-generated images.
 * Version: 1.1
 * Author: [Votre Nom]
 */

if (!defined('ABSPATH')) {
    exit;
}

// Add a menu in the WordPress admin dashboard
add_action('admin_menu', 'auto_post_gpt_menu');

function auto_post_gpt_menu() {
    add_menu_page(
        'Auto Post GPT',
        'Auto Post GPT',
        'manage_options',
        'auto-post-gpt',
        'auto_post_gpt_page',
        'dashicons-edit',
        20
    );

    add_submenu_page(
        'auto-post-gpt',
        'API Settings',
        'API Settings',
        'manage_options',
        'auto-post-gpt-settings',
        'auto_post_gpt_settings_page'
    );
}

// Admin page for generating posts
function auto_post_gpt_page() {
    ?>
    <div class="wrap">
        <h1>Auto Post with ChatGPT</h1>
        <form method="post" action="">
            <?php wp_nonce_field('generate_post', 'generate_post_nonce'); ?>
            <label for="post_topic">Enter a Topic for the Post:</label>
            <input type="text" id="post_topic" name="post_topic" required style="width: 100%; padding: 10px; margin: 10px 0;">
            <input type="submit" name="generate_post" value="Generate Post" class="button button-primary">
        </form>
    </div>
    <?php

    if (isset($_POST['generate_post']) && check_admin_referer('generate_post', 'generate_post_nonce')) {
        $topic = sanitize_text_field($_POST['post_topic']);
        $response = auto_post_gpt_generate_post($topic);

        if ($response) {
            echo '<div class="updated"><p>Post generated successfully!</p></div>';
        } else {
            echo '<div class="error"><p>Failed to generate the post. Please try again.</p></div>';
        }
    }
}

// Settings page for API keys
function auto_post_gpt_settings_page() {
    if (isset($_POST['save_settings']) && check_admin_referer('save_settings', 'save_settings_nonce')) {
        $api_key = sanitize_text_field($_POST['openai_api_key']);
        update_option('auto_post_gpt_api_key', $api_key);
        echo '<div class="updated"><p>Settings saved successfully!</p></div>';
    }

    $saved_api_key = get_option('auto_post_gpt_api_key', '');

    ?>
    <div class="wrap">
        <h1>API Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field('save_settings', 'save_settings_nonce'); ?>
            <label for="openai_api_key">OpenAI API Key:</label>
            <input type="text" id="openai_api_key" name="openai_api_key" value="<?php echo esc_attr($saved_api_key); ?>" style="width: 100%; padding: 10px; margin: 10px 0;">
            <input type="submit" name="save_settings" value="Save Settings" class="button button-primary">
        </form>
    </div>
    <?php
}

// Generate post using ChatGPT
function auto_post_gpt_generate_post($topic) {
    $openai_api_key = get_option('auto_post_gpt_api_key');

    if (empty($openai_api_key)) {
        return false;
    }

    // ChatGPT API Call
    $chatgpt_url = 'https://api.openai.com/v1/completions';
    $chatgpt_body = [
        'model' => 'text-davinci-003',
        'prompt' => "Write a detailed blog post about: $topic",
        'max_tokens' => 1000,
    ];
    $chatgpt_response = wp_remote_post($chatgpt_url, [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $openai_api_key,
        ],
        'body' => json_encode($chatgpt_body),
    ]);

    if (is_wp_error($chatgpt_response)) {
        return false;
    }

    $chatgpt_data = json_decode(wp_remote_retrieve_body($chatgpt_response), true);
    $post_content = $chatgpt_data['choices'][0]['text'] ?? '';

    // DALL-E API Call for Image
    $dalle_url = 'https://api.openai.com/v1/images/generations';
    $dalle_body = [
        'prompt' => "A high-quality image related to $topic",
        'n' => 1,
        'size' => '1024x1024',
    ];
    $dalle_response = wp_remote_post($dalle_url, [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $openai_api_key,
        ],
        'body' => json_encode($dalle_body),
    ]);

    if (is_wp_error($dalle_response)) {
        return false;
    }

    $dalle_data = json_decode(wp_remote_retrieve_body($dalle_response), true);
    $image_url = $dalle_data['data'][0]['url'] ?? '';

    // Upload the image to WordPress Media Library
    $image_id = auto_post_gpt_upload_image($image_url);

    // Create a new post
    $post_id = wp_insert_post([
        'post_title'   => $topic,
        'post_content' => $post_content,
        'post_status'  => 'publish',
        'post_type'    => 'post',
    ]);

    if ($post_id && $image_id) {
        set_post_thumbnail($post_id, $image_id);
    }

    return $post_id;
}

// Upload image to WordPress Media Library
function auto_post_gpt_upload_image($image_url) {
    $image = file_get_contents($image_url);
    $upload = wp_upload_bits(basename($image_url), null, $image);

    if (!$upload['error']) {
        $filetype = wp_check_filetype($upload['file']);
        $attachment = [
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name($upload['file']),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }

    return false;
}
