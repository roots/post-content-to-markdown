<?php

/**
 * Plugin Name: Post Content to Markdown
 * Description: Serve post content as Markdown via Accept headers or query parameters.
 * Version: 1.2.1
 * Author: roots.io
 * Requires PHP: 8.1
 */

namespace PostContentToMarkdown;

if (! defined('ABSPATH')) {
    exit;
}

// Load the appropriate autoloader
if (!class_exists('\League\HTMLToMarkdown\HtmlConverter')) {
    if (file_exists(__DIR__.'/vendor/autoload.php')) {
        // Composer installation
        require_once __DIR__.'/vendor/autoload.php';
    } elseif (file_exists(__DIR__.'/vendor_prefixed/vendor/autoload.php')) {
        // Standalone plugin with prefixed dependencies
        require_once __DIR__.'/vendor_prefixed/vendor/autoload.php';
    }
}

/**
 * Check if Markdown format is requested via Accept header or query parameter
 */
function isMarkdownRequested()
{
    // Check Accept header
    if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'text/markdown')) {
        return true;
    }

    // Check query parameter
    if (isset($_GET['format']) && $_GET['format'] === 'markdown') {
        return true;
    }

    return false;
}

/**
 * Serve content as Markdown based on Accept header or query parameter
 */
add_action('template_redirect', function () {
    if (! isMarkdownRequested()) {
        return;
    }

    // Handle main feed (/feed/, /feed/markdown/, or /?feed=rss2&format=markdown)
    if (is_feed() && ! is_singular()) {
        outputMarkdownFeed();
        exit;
    }

    // Handle single post comment feed (/post-slug/feed/ or /post-slug/?feed=rss2)
    if (is_singular() && get_query_var('feed')) {
        $post = get_queried_object();
        if ($post && is_a($post, 'WP_Post')) {
            outputSinglePostCommentFeed($post);
            exit;
        }
    }

    // Handle regular single post
    if (is_singular()) {
        $post = get_queried_object();
        if (! $post || ! is_a($post, 'WP_Post')) {
            return;
        }

        $allowed_post_types = apply_filters('post_content_to_markdown/post_types', ['post']);
        if (in_array($post->post_type, $allowed_post_types, true)) {
            header('Content-Type: text/markdown; charset='.get_option('blog_charset'));
            echo '# '.strip_tags($post->post_title)."\n\n".contentToMarkdown($post->post_content);
            exit;
        }
    }
});

/**
 * Output the Markdown feed
 */
function outputMarkdownFeed()
{
    // Check for cached feed
    $cache_key = 'post_content_to_markdown_feed_'.md5(serialize([
        apply_filters('post_content_to_markdown/feed_post_types', ['post']),
        apply_filters('post_content_to_markdown/feed_posts_per_page', 10),
        apply_filters('post_content_to_markdown/feed_include_comments', false),
        apply_filters('post_content_to_markdown/feed_include_excerpt', false),
    ]));

    $cached_feed = get_transient($cache_key);
    if ($cached_feed !== false) {
        header('Content-Type: text/markdown; charset='.get_option('blog_charset'));
        echo $cached_feed;
        exit;
    }

    ob_start();

    // Feed header
    echo '# '.get_bloginfo('name')." - Markdown Feed\n\n";
    echo '**Description:** '.get_bloginfo('description')."\n";
    echo '**Last Updated:** '.date('c')."\n";
    echo '**Feed URL:** '.get_feed_link('markdown')."\n\n";
    echo "---\n\n";

    $posts = get_posts([
        'post_type' => apply_filters('post_content_to_markdown/feed_post_types', ['post']),
        'posts_per_page' => apply_filters('post_content_to_markdown/feed_posts_per_page', 10),
        'post_status' => 'publish',
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    $include_comments = apply_filters('post_content_to_markdown/feed_include_comments', false);
    $include_excerpt = apply_filters('post_content_to_markdown/feed_include_excerpt', false);

    if (empty($posts)) {
        echo "No posts available in this feed.\n";
    }

    foreach ($posts as $post) {
        echo '# '.strip_tags($post->post_title)."\n\n";

        // Post metadata
        echo '**Author:** '.get_the_author_meta('display_name', $post->post_author)."\n";
        echo '**Published:** '.get_the_date('c', $post)."\n";
        echo '**URL:** '.get_permalink($post)."\n";

        // Categories
        $categories = get_the_category($post->ID);
        if (! empty($categories)) {
            $cat_names = array_map(function ($cat) {
                return $cat->name;
            }, $categories);
            echo '**Categories:** '.implode(', ', $cat_names)."\n";
        }

        // Tags
        $tags = get_the_tags($post->ID);
        if (! empty($tags)) {
            $tag_names = array_map(function ($tag) {
                return $tag->name;
            }, $tags);
            echo '**Tags:** '.implode(', ', $tag_names)."\n";
        }

        echo "\n";

        // Excerpt if enabled
        if ($include_excerpt) {
            $excerpt = has_excerpt($post->ID) ? $post->post_excerpt : wp_trim_words($post->post_content, 55);
            echo '**Excerpt:** '.\PostContentToMarkdown\contentToMarkdown($excerpt)."\n\n";
        }

        // Post content
        echo \PostContentToMarkdown\contentToMarkdown($post->post_content)."\n\n";

        // Comments if enabled
        if ($include_comments) {
            $comments = get_comments([
                'post_id' => $post->ID,
                'status' => 'approve',
                'orderby' => 'comment_date',
                'order' => 'ASC',
            ]);

            if (! empty($comments)) {
                echo "## Comments\n\n";
                foreach ($comments as $comment) {
                    echo '**'.$comment->comment_author.'** - '.get_comment_date('c', $comment)."\n\n";
                    echo \PostContentToMarkdown\contentToMarkdown($comment->comment_content)."\n\n";
                }
            }
        }

        echo "---\n\n";
    }

    $feed_content = ob_get_clean();

    // Cache for 1 hour
    set_transient($cache_key, $feed_content, apply_filters('post_content_to_markdown/feed_cache_duration', HOUR_IN_SECONDS));

    header('Content-Type: text/markdown; charset='.get_option('blog_charset'));
    echo $feed_content;
    exit;
}

/**
 * Output single post with comments feed
 */
function outputSinglePostCommentFeed($post)
{
    header('Content-Type: text/markdown; charset='.get_option('blog_charset'));

    echo '# '.strip_tags($post->post_title)."\n\n";

    // Post metadata
    echo '**Author:** '.get_the_author_meta('display_name', $post->post_author)."\n";
    echo '**Published:** '.get_the_date('c', $post)."\n";
    echo '**URL:** '.get_permalink($post)."\n";

    // Categories
    $categories = get_the_category($post->ID);
    if (! empty($categories)) {
        $cat_names = array_map(function ($cat) {
            return $cat->name;
        }, $categories);
        echo '**Categories:** '.implode(', ', $cat_names)."\n";
    }

    // Tags
    $tags = get_the_tags($post->ID);
    if (! empty($tags)) {
        $tag_names = array_map(function ($tag) {
            return $tag->name;
        }, $tags);
        echo '**Tags:** '.implode(', ', $tag_names)."\n";
    }

    echo "\n";

    // Post content
    echo \PostContentToMarkdown\contentToMarkdown($post->post_content)."\n\n";

    // Comments
    $comments = get_comments([
        'post_id' => $post->ID,
        'status' => 'approve',
        'orderby' => 'comment_date',
        'order' => 'ASC',
    ]);

    if (! empty($comments)) {
        echo "## Comments\n\n";
        foreach ($comments as $comment) {
            echo '**'.$comment->comment_author.'** - '.get_comment_date('c', $comment)."\n\n";
            echo \PostContentToMarkdown\contentToMarkdown($comment->comment_content)."\n\n";
            echo "---\n\n";
        }
    }

    if (empty($comments)) {
        echo "## Comments\n\nNo comments yet.\n\n";
    }
}

/**
 * Registers a custom feed that outputs posts in Markdown format.
 * The feed URL will be `http://yoursite.com/feed/markdown`.
 */
add_action('init', function () {
    add_feed('markdown', function () {
        outputMarkdownFeed();
    });
});

/**
 * Add Markdown feed link to RSS feed header for autodiscovery
 */
add_action('rss2_head', function () {
    $markdown_feed_url = get_feed_link('markdown');
    echo '<atom:link href="'.esc_url($markdown_feed_url).'" rel="alternate" type="text/markdown" />'."\n";
});

/**
 * Clear feed cache when posts are published, updated, or deleted
 */
add_action('save_post', function ($post_id) {
    $post = get_post($post_id);
    if (! $post) {
        return;
    }

    // Only clear cache if the post type is included in the feed
    $feed_post_types = apply_filters('post_content_to_markdown/feed_post_types', ['post']);
    if (in_array($post->post_type, $feed_post_types, true)) {
        clearFeedCache();
    }
});

add_action('delete_post', function ($post_id) {
    $post = get_post($post_id);
    if (! $post) {
        return;
    }

    // Only clear cache if the post type was included in the feed
    $feed_post_types = apply_filters('post_content_to_markdown/feed_post_types', ['post']);
    if (in_array($post->post_type, $feed_post_types, true)) {
        clearFeedCache();
    }
});

/**
 * Clear feed cache when comments are added, edited, or deleted (if comments are included in feed)
 */
add_action('comment_post', function ($comment_id, $comment_approved) {
    // Only clear if comments are included in the feed
    if (apply_filters('post_content_to_markdown/feed_include_comments', false)) {
        clearFeedCache();
    }
}, 10, 2);

add_action('edit_comment', function ($comment_id) {
    if (apply_filters('post_content_to_markdown/feed_include_comments', false)) {
        clearFeedCache();
    }
});

add_action('delete_comment', function ($comment_id) {
    if (apply_filters('post_content_to_markdown/feed_include_comments', false)) {
        clearFeedCache();
    }
});

add_action('wp_set_comment_status', function ($comment_id, $comment_status) {
    if (apply_filters('post_content_to_markdown/feed_include_comments', false)) {
        clearFeedCache();
    }
}, 10, 2);

/**
 * Clear all cached Markdown feeds
 */
function clearFeedCache()
{
    global $wpdb;

    $like = $wpdb->esc_like('_transient_post_content_to_markdown_feed_').'%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));

    $like_timeout = $wpdb->esc_like('_transient_timeout_post_content_to_markdown_feed_').'%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout));
}

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
        'strip_tags' => true,  // Remove HTML tags without Markdown equivalents
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
