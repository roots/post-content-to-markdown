<?php

/**
 * Plugin Name: Post Content to Markdown
 * Description: Serve post content as Markdown via Accept headers, .md URL suffix, or query parameters.
 * Version: 1.8.0
 * Author: roots.io
 * Requires PHP: 8.1
 */

namespace PostContentToMarkdown;

if (! defined('ABSPATH')) {
    exit;
}

// Load the appropriate autoloader
if (! class_exists('\League\HTMLToMarkdown\HtmlConverter')) {
    if (file_exists(__DIR__.'/vendor_prefixed/vendor/autoload.php')) {
        // Standalone plugin with prefixed dependencies
        require_once __DIR__.'/vendor_prefixed/vendor/autoload.php';
    } elseif (file_exists(__DIR__.'/vendor/autoload.php')) {
        // Composer installation
        require_once __DIR__.'/vendor/autoload.php';
    }
}

/**
 * Find the most specific Accept entry matching a media type (exact > type/* >
 * *\/*, per RFC 9110) and return its q-value, specificity (2, 1, 0, or -1 for
 * no match), and position in the header.
 */
function acceptMatch($accept, $type, $subtype)
{
    $best = ['q' => 0.0, 'specificity' => -1, 'position' => PHP_INT_MAX];

    foreach (explode(',', $accept) as $position => $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }

        $parts = explode(';', $entry);
        [$t, $s] = array_pad(explode('/', strtolower(trim(array_shift($parts))), 2), 2, '');

        $specificity = match (true) {
            $t === $type && $s === $subtype => 2,
            $t === $type && $s === '*' => 1,
            $t === '*' && $s === '*' => 0,
            default => -1,
        };
        if ($specificity < 0) {
            continue;
        }

        $q = 1.0;
        foreach ($parts as $param) {
            $param = trim($param);
            if (stripos($param, 'q=') === 0) {
                $q = (float) substr($param, 2);
            }
        }

        if ($specificity > $best['specificity'] || ($specificity === $best['specificity'] && $q > $best['q'])) {
            $best = ['q' => $q, 'specificity' => $specificity, 'position' => $position];
        }
    }

    return $best;
}

/**
 * Return the q-value for a media type in an Accept header.
 */
function acceptQuality($accept, $type, $subtype)
{
    return acceptMatch($accept, $type, $subtype)['q'];
}

/**
 * Whether the current request reached the site via a `.md` URL suffix
 * (e.g. /about.md). Internally rewritten to the underlying URL during
 * plugins_loaded; this flag preserves the Markdown-preferred signal for
 * later hooks.
 */
function isMdUrlRequest()
{
    return ! empty($GLOBALS['post_content_to_markdown_md_url']);
}

/**
 * Check if Markdown is preferred over HTML via the Accept header, a .md URL
 * suffix, or the ?format=markdown query parameter.
 */
function isMarkdownRequested()
{
    if (isMdUrlRequest()) {
        return true;
    }

    if (isset($_GET['format']) && $_GET['format'] === 'markdown') {
        return true;
    }

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if ($accept === '') {
        return false;
    }

    $markdown = acceptMatch($accept, 'text', 'markdown');
    $html = acceptMatch($accept, 'text', 'html');

    if ($markdown['q'] <= 0.0 || $markdown['q'] !== $html['q']) {
        return $markdown['q'] > $html['q'];
    }

    // On a tie, a named type beats a wildcard, and between two named types the
    // one listed first wins (e.g. `text/markdown, text/html, */*`, as sent by
    // several AI agents). When only a wildcard matches both, it's the same
    // entry for each, so HTML stays the default.
    if ($markdown['specificity'] !== $html['specificity']) {
        return $markdown['specificity'] > $html['specificity'];
    }

    return $markdown['position'] < $html['position'];
}

/**
 * Identify how the client asked for Markdown: md-url (URL suffix), query
 * (?format=markdown), or accept (Accept header). Emitted as X-Markdown-Source
 * so ops can see the negotiation mix in access logs.
 */
function markdownSource()
{
    if (isMdUrlRequest()) {
        return 'md-url';
    }

    if (isset($_GET['format']) && $_GET['format'] === 'markdown') {
        return 'query';
    }

    return 'accept';
}

/**
 * Send the response headers common to every Markdown output path.
 */
function sendMarkdownHeaders()
{
    header('Content-Type: text/markdown; charset='.get_option('blog_charset'));
    header('Vary: Accept', false);
    header('X-Markdown-Source: '.markdownSource(), false);
}

/**
 * Whether the client's Accept header allows any representation we can serve
 * (text/html or text/markdown). A .md URL suffix is always acceptable since
 * the URL itself is the preference signal. Missing Accept counts as
 * acceptable per RFC 9110 (absence means any type is acceptable).
 */
function isAcceptable()
{
    if (isMdUrlRequest()) {
        return true;
    }

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if ($accept === '') {
        return true;
    }

    return acceptQuality($accept, 'text', 'html') > 0.0
        || acceptQuality($accept, 'text', 'markdown') > 0.0;
}

/**
 * Whether /index.md is the front page's Markdown URL: only for a static front
 * page, and only when no other post or top-level page uses the slug "index"
 * (which keeps its own /index.md).
 */
function indexMdServesFrontPage()
{
    if (get_option('show_on_front') !== 'page') {
        return false;
    }

    // Check pages first, the same order WordPress routes /index/ in.
    $index = get_page_by_path('index', OBJECT, ['page']) ?: get_page_by_path('index', OBJECT, ['post']);

    return ! $index || $index->ID === (int) get_option('page_on_front');
}

/**
 * Detect a .md URL suffix (e.g. /about.md) and internally rewrite REQUEST_URI
 * to the underlying URL so existing WP and custom routing matches as usual.
 * The URL the client hit is preserved in the address bar; this rewrite only
 * affects server-side lookup. A flag is set so isMarkdownRequested() and
 * isAcceptable() know the request explicitly asked for Markdown.
 */
add_action('plugins_loaded', function () {
    if (! apply_filters('post_content_to_markdown/md_url_enabled', true)) {
        return;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $parts = explode('?', $uri, 2);
    $path = $parts[0];
    $query = isset($parts[1]) ? '?'.$parts[1] : '';

    if (! preg_match('#\.md$#', $path)) {
        return;
    }

    // rest_url() depends on $wp_rewrite which isn't ready at plugins_loaded
    // priority 0, so build the REST path from home_url() + rest_get_url_prefix().
    $home_path = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/').'/';
    $skip_prefixes = array_filter([
        wp_parse_url(admin_url(), PHP_URL_PATH),
        $home_path.rest_get_url_prefix().'/',
        wp_parse_url(wp_login_url(), PHP_URL_PATH),
        wp_parse_url(home_url('xmlrpc.php'), PHP_URL_PATH),
    ]);
    foreach ($skip_prefixes as $skip) {
        if (str_starts_with($path, $skip)) {
            return;
        }
    }

    $stripped = substr($path, 0, -3);

    if ($stripped === rtrim($home_path, '/') || ($stripped === $home_path.'index' && indexMdServesFrontPage())) {
        $stripped = $home_path;
    }

    // Match the permalink structure's trailing slash so WordPress doesn't
    // canonical-redirect the stripped URL, which would drop the .md. Plain
    // permalinks (no structure) keep the slash. $wp_rewrite isn't set up yet,
    // so read the structure directly, as WP_Rewrite::init() does.
    $structure = (string) get_option('permalink_structure');
    if ($stripped === '' || (($structure === '' || str_ends_with($structure, '/')) && ! str_ends_with($stripped, '/'))) {
        $stripped .= '/';
    }

    $_SERVER['REQUEST_URI'] = $stripped.$query;
    $GLOBALS['post_content_to_markdown_md_url'] = true;
}, 0);

/**
 * Tell caching plugins (WP Super Cache, etc.) to skip caching for same-URL
 * Accept-header negotiation, where the cache key wouldn't distinguish the
 * Markdown and HTML representations. .md URL requests are a distinct URL
 * and safely cacheable, but WP page caches may transform the body assuming
 * HTML (gzip, footer injection), so the opt-in stays off by default.
 */
add_action('plugins_loaded', function () {
    if (! isMarkdownRequested() || defined('DONOTCACHEPAGE')) {
        return;
    }

    if (isMdUrlRequest() && apply_filters('post_content_to_markdown/cache_md_urls', false)) {
        return;
    }

    define('DONOTCACHEPAGE', true);
});

/**
 * Add X-Robots-Tag: noindex, nofollow to .md URL responses so search engines
 * don't index the Markdown alias alongside the canonical HTML page.
 */
add_action('send_headers', function () {
    if (is_admin()) {
        return;
    }

    if (! isMdUrlRequest()) {
        return;
    }

    header('X-Robots-Tag: noindex, nofollow', false);
});

/**
 * Advertise that responses vary by Accept header so caches key on it. Applied to
 * every front-end response (not just Markdown) so an HTML response cached first
 * can't be served to a later Markdown request for the same URL.
 */
add_action('send_headers', function () {
    if (is_admin()) {
        return;
    }

    if (! apply_filters('post_content_to_markdown/emit_vary', true)) {
        return;
    }

    header('Vary: Accept', false);
});

/**
 * Whether a single post may be served as Markdown to the current visitor:
 * its type is allowed, it isn't password-protected (or the visitor has
 * already entered the password), and the post_allowed filter permits it.
 */
function canServePost($post)
{
    if (! $post || ! is_a($post, 'WP_Post')) {
        return false;
    }

    $allowed = apply_filters('post_content_to_markdown/post_types', ['post']);
    if (! in_array($post->post_type, $allowed, true)) {
        return false;
    }

    // Markdown output reads post_content directly, bypassing the password form
    // that core substitutes via the_content, so enforce the password here.
    if (post_password_required($post)) {
        return false;
    }

    return (bool) apply_filters('post_content_to_markdown/post_allowed', true, $post);
}

/**
 * Return the .md URL for the current singular post if the plugin would serve
 * a Markdown representation, or null when no alternate exists or should be
 * advertised (admin, Markdown response, disallowed post, filter disabled).
 */
function currentMdAlternateUrl()
{
    if (is_admin() || isMarkdownRequested() || ! is_singular()) {
        return null;
    }

    if (! apply_filters('post_content_to_markdown/md_url_enabled', true)) {
        return null;
    }

    $post = get_queried_object();
    if (! canServePost($post)) {
        return null;
    }

    $permalink = get_permalink($post);

    // The front page's permalink is the site root, where appending .md would
    // give https://example.com.md, so it uses /index.md instead.
    if (untrailingslashit($permalink) === untrailingslashit(home_url())) {
        return indexMdServesFrontPage() ? home_url('/index.md') : null;
    }

    return rtrim($permalink, '/').'.md';
}

/**
 * Advertise the .md sibling on HTML responses so RFC 8288-aware crawlers can
 * discover the Markdown representation without sending Accept: text/markdown.
 */
add_action('template_redirect', function () {
    $md_url = currentMdAlternateUrl();
    if ($md_url === null) {
        return;
    }

    header('Link: <'.esc_url_raw($md_url).'>; rel="alternate"; type="text/markdown"', false);
});

/**
 * Also advertise the .md sibling inside <head> so HTML-parsing clients
 * (browsers, feed readers) discover it alongside the HTTP Link header.
 */
add_action('wp_head', function () {
    $md_url = currentMdAlternateUrl();
    if ($md_url === null) {
        return;
    }

    echo '<link rel="alternate" type="text/markdown" href="'.esc_url($md_url).'">'."\n";
});

/**
 * Return 406 Not Acceptable when the client's Accept header rules out every
 * representation we can serve. Disable via the strict_accept filter to fall
 * back to HTML instead.
 */
add_action('template_redirect', function () {
    if (is_feed() || is_admin()) {
        return;
    }

    if (! apply_filters('post_content_to_markdown/strict_accept', true)) {
        return;
    }

    if (isAcceptable()) {
        return;
    }

    status_header(406);
    header('Content-Type: text/plain; charset=utf-8');
    header('Vary: Accept', false);
    echo "406 Not Acceptable\n\nAvailable representations: text/html, text/markdown\n";
    exit;
}, 1);

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

    if (! is_singular()) {
        return;
    }

    // Fall through to the normal render (e.g. the password form) for posts
    // that can't be served as Markdown.
    $post = get_queried_object();
    if (! canServePost($post)) {
        return;
    }

    // Only visitors with the password cookie get here for a protected post, so
    // keep page caches from storing their response for the shared URL.
    if ($post->post_password !== '' && ! defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }

    // Handle single post comment feed (/post-slug/feed/ or /post-slug/?feed=rss2)
    if (get_query_var('feed')) {
        outputSinglePostCommentFeed($post);
        exit;
    }

    // Handle regular single post
    sendMarkdownHeaders();
    echo '# '.strip_tags($post->post_title)."\n\n".contentToMarkdown($post->post_content);
    exit;
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
        sendMarkdownHeaders();
        echo $cached_feed;
        exit;
    }

    // The feed is cached and shared by every visitor, so render it with no
    // current user (user ID 0). Otherwise user-aware shortcodes, blocks, or
    // post_allowed filters would cache one user's view for everyone.
    $current_user_id = get_current_user_id();
    wp_set_current_user(0);

    try {
        $feed_content = renderMarkdownFeed();
    } finally {
        wp_set_current_user($current_user_id);
    }

    // Cache for 1 hour
    set_transient($cache_key, $feed_content, apply_filters('post_content_to_markdown/feed_cache_duration', HOUR_IN_SECONDS));

    sendMarkdownHeaders();
    echo $feed_content;
    exit;
}

/**
 * Render the Markdown feed body
 */
function renderMarkdownFeed()
{
    ob_start();

    // Feed header
    echo '# '.get_bloginfo('name')." - Markdown Feed\n\n";
    echo '**Description:** '.get_bloginfo('description')."\n";
    echo '**Last Updated:** '.date('c')."\n";
    echo '**Feed URL:** '.get_feed_link('markdown')."\n\n";
    echo "---\n\n";

    $per_page = (int) apply_filters('post_content_to_markdown/feed_posts_per_page', 10);
    $query = [
        'post_type' => apply_filters('post_content_to_markdown/feed_post_types', ['post']),
        'posts_per_page' => $per_page,
        'post_status' => 'publish',
        // The feed is cached and shared across visitors, so always exclude
        // password-protected posts regardless of the requester's cookies.
        'has_password' => false,
        // ID breaks ties between posts with the same date, so paging is stable.
        'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
    ];

    // Apply the same per-post gate as single-post Markdown responses, fetching
    // more pages so rejected posts don't leave the feed short. The scan stops
    // after 10 pages so a filter that rejects most posts can't walk the whole
    // site.
    $posts = [];
    for ($page = 1; $page <= 10; $page++) {
        $batch = get_posts($query + ['paged' => $page]);

        foreach ($batch as $post) {
            // Keyed by ID so a post that shifts across pages isn't listed twice.
            if (! isset($posts[$post->ID]) && apply_filters('post_content_to_markdown/post_allowed', true, $post)) {
                $posts[$post->ID] = $post;
            }
        }

        if ($per_page < 1 || count($batch) < $per_page || count($posts) >= $per_page) {
            break;
        }
    }

    if ($per_page > 0) {
        $posts = array_slice($posts, 0, $per_page);
    }

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
            if (has_excerpt($post->ID)) {
                $excerpt = \PostContentToMarkdown\contentToMarkdown($post->post_excerpt);
            } else {
                // Strip shortcodes and blocks before trimming, as core's
                // wp_trim_excerpt() does: wp_trim_words() strips tags, including
                // block delimiters, and can cut a shortcode's closing tag, which
                // would expose gated content. Don't render the result:
                // strip_shortcodes() turns an escaped [[tag]] into a live [tag].
                $excerpt = \PostContentToMarkdown\contentToMarkdown(
                    wp_trim_words(excerpt_remove_blocks(strip_shortcodes($post->post_content)), 55),
                    false
                );
            }
            echo '**Excerpt:** '.$excerpt."\n\n";
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
                    echo \PostContentToMarkdown\contentToMarkdown($comment->comment_content, false)."\n\n";
                }
            }
        }

        echo "---\n\n";
    }

    return ob_get_clean();
}

/**
 * Output single post with comments feed
 */
function outputSinglePostCommentFeed($post)
{
    sendMarkdownHeaders();

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
            echo \PostContentToMarkdown\contentToMarkdown($comment->comment_content, false)."\n\n";
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
 * @param  bool  $render  Whether to render blocks and shortcodes before converting.
 *                        Pass false for text that must not be rendered, such
 *                        as comments and generated excerpts.
 * @return string The converted Markdown.
 */
function contentToMarkdown($content, $render = true)
{
    if (empty(trim($content))) {
        return '';
    }

    // Rendering can depend on the current user (e.g. membership shortcodes),
    // so only logged-out conversions, which every visitor shares, are cached.
    $cacheable = ! is_user_logged_in();

    $cache_key = 'md_'.($render ? '' : 'raw_').md5($content);
    $cached = $cacheable ? wp_cache_get($cache_key, 'post_content_to_markdown') : false;
    if ($cached !== false) {
        return apply_filters('post_content_to_markdown/markdown_output', $cached, $content);
    }

    // Render dynamic Gutenberg blocks and shortcodes so the HTML is complete
    // before conversion, unless the caller opts out for content core doesn't
    // render them in (e.g. comments, whose authors must not be able to invoke
    // blocks or shortcodes).
    $rendered = $content;
    if ($render) {
        $rendered = do_blocks($rendered);
        $rendered = do_shortcode($rendered);
    }

    // Flatten <pre> blocks so syntax-highlighter markup doesn't leak into the code fence.
    $rendered = preg_replace_callback('#<pre\b[^>]*>(.*?)</pre>#is', function ($m) {
        $inner = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<pre><code>'.htmlspecialchars($inner, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</code></pre>';
    }, $rendered);

    $default_options = [
        'header_style' => 'atx',
        'strip_tags' => true,  // Remove HTML tags without Markdown equivalents
        'remove_nodes' => 'script style',  // Remove script and style elements
        'hard_break' => true,  // Convert <br> to newlines
    ];

    $options = apply_filters('post_content_to_markdown/converter_options', $default_options);

    // Determine which HtmlConverter / TableConverter classes to use based on what's available
    if (class_exists('\League\HTMLToMarkdown\HtmlConverter')) {
        $converter = new \League\HTMLToMarkdown\HtmlConverter($options);
        $table_converter_class = '\League\HTMLToMarkdown\Converter\TableConverter';
    } else {
        $converter = new \PostContentToMarkdown\Vendor\League\HTMLToMarkdown\HtmlConverter($options);
        $table_converter_class = '\PostContentToMarkdown\Vendor\League\HTMLToMarkdown\Converter\TableConverter';
    }

    // league/html-to-markdown ships a TableConverter but doesn't register it by default.
    if (class_exists($table_converter_class)) {
        $converter->getEnvironment()->addConverter(new $table_converter_class);
    }

    $markdown = $converter->convert($rendered);

    // Clean up excessive newlines (more than 2 consecutive)
    $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown);

    if ($cacheable) {
        wp_cache_set(
            $cache_key,
            $markdown,
            'post_content_to_markdown',
            apply_filters('post_content_to_markdown/conversion_cache_duration', HOUR_IN_SECONDS)
        );
    }

    return apply_filters('post_content_to_markdown/markdown_output', $markdown, $content);
}
