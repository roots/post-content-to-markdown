<?php

/**
 * Plugin Name: Post Content to Markdown
 * Description: Exposes post content as Markdown via HTTP `Accept` headers.
 * Version: 1.0.0
 * Author: roots.io
 */

namespace PostContentToMarkdown;

if (! defined('ABSPATH')) {
    exit;
}

// Load the appropriate autoloader
if (file_exists(__DIR__.'/vendor/autoload.php')) {
    // Composer installation
    require_once __DIR__.'/vendor/autoload.php';
} else {
    // Standalone plugin with prefixed dependencies
    require_once __DIR__.'/vendor_prefixed/vendor/autoload.php';
}

add_action('template_redirect', function () {
    if (! isset($_SERVER['HTTP_ACCEPT']) || ! str_contains($_SERVER['HTTP_ACCEPT'], 'text/markdown')) {
        return;
    }

    $post = get_queried_object();
    if (! $post || ! is_a($post, 'WP_Post')) {
        return;
    }

    $allowed_post_types = apply_filters('post_content_to_markdown/post_types', ['post']);

    if (is_single() && in_array($post->post_type, $allowed_post_types, true)) {
        header('Content-Type: text/markdown');
        echo '# '.strip_tags($post->post_title)."\n\n".contentToMarkdown($post->post_content);
        exit;
    }
});

/**
 * Converts an HTML string into Markdown using the league/html-to-markdown library.
 *
 * @param  string  $content  The HTML content to convert.
 * @return string The converted Markdown.
 */
function contentToMarkdown($content)
{
    if (empty(trim($content))) {
        return '';
    }

    $default_options = [
        'header_style' => 'atx',
        'strip_tags' => true,  // Remove HTML tags without markdown equivalents
        'remove_nodes' => 'script style',  // Remove script and style elements
        'hard_break' => true,  // Convert <br> to newlines
    ];

    $options = apply_filters('post_content_to_markdown/converter_options', $default_options);

    // Determine which HtmlConverter class to use based on what's available
    if (class_exists('\League\HTMLToMarkdown\HtmlConverter')) {
        $converter = new \League\HTMLToMarkdown\HtmlConverter($options);
    } else {
        $converter = new \PostContentToMarkdown\Vendor\League\HTMLToMarkdown\HtmlConverter($options);
    }
    $markdown = $converter->convert($content);

    // Clean up excessive newlines (more than 2 consecutive)
    $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown);

    return apply_filters('post_content_to_markdown/markdown_output', $markdown, $content);
}
