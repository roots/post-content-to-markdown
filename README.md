# Post Content to Markdown

A WordPress plugin that returns post content in Markdown format when requested with an `Accept` header set to `text/markdown`.

## Requirements

PHP 8.1+

## Usage

### Single posts

Visit any single post on your site with the `Accept` header set to `text/markdown` to get the post content directly as Markdown.

**Example:**

```bash
curl -H "Accept: text/markdown" https://example.com/my-awesome-post
```

### Markdown feed

Access a feed of your posts in Markdown format at `/feed/markdown/`:

**Example:**

```bash
curl https://example.com/feed/markdown/
```

The feed includes:
- Feed metadata (site name, description, last updated, feed URL)
- Post title, author, publication date (ISO 8601), and permalink
- Post categories and tags
- Post content converted to Markdown
- Optional excerpt support
- Optional comment support

**Example Output:**

```markdown
# My WordPress Site - Markdown Feed

**Description:** Just another WordPress site
**Last Updated:** 2025-10-03T19:45:00+00:00
**Feed URL:** https://example.com/feed/markdown/

---

# Hello World!

**Author:** John Doe
**Published:** 2025-10-03T12:00:00+00:00
**URL:** https://example.com/hello-world/
**Categories:** News, Updates
**Tags:** announcement, wordpress

Welcome to WordPress. This is your first post. Edit or delete it, then start writing!

---
```

**Feed URL structure:**

The Markdown feed is accessible at `https://yoursite.com/feed/markdown/`. Note that WordPress requires pretty permalinks to be enabled (Settings → Permalinks must be set to anything other than "Plain").

**Autodiscovery:**

The plugin automatically adds a `<atom:link>` element to your site's RSS feed, allowing feed readers and LLMs to discover the Markdown version:

```xml
<atom:link href="https://example.com/feed/markdown/" rel="alternate" type="text/markdown" />
```

## Installation

### via Composer

```bash
composer require roots/post-content-to-markdown
```

### Manual

1. Download the [latest release](https://github.com/roots/post-content-to-markdown/releases)
2. Place in `wp-content/plugins/post-content-to-markdown/`
3. Activate via wp-admin or WP-CLI

**Note:** After activation, you may need to flush rewrite rules by visiting Settings → Permalinks and clicking "Save Changes" if the `/feed/markdown/` endpoint doesn't work immediately.

## Filters

The plugin provides several filters for customization:

### Single post filters

#### `post_content_to_markdown/post_types`

Filter the post types that can be served as Markdown for single posts.

```php
add_filter('post_content_to_markdown/post_types', function ($post_types) {
    // Add support for pages and custom post types
    return ['post', 'page', 'product'];
});
```

**Default:** `['post']`

### Feed filters

#### `post_content_to_markdown/feed_post_types`

Filter the post types included in the Markdown feed.

```php
add_filter('post_content_to_markdown/feed_post_types', function ($post_types) {
    return ['post', 'page'];
});
```

**Default:** `['post']`

#### `post_content_to_markdown/feed_posts_per_page`

Filter the number of posts included in the Markdown feed.

```php
add_filter('post_content_to_markdown/feed_posts_per_page', function ($count) {
    return 20;
});
```

**Default:** `10`

#### `post_content_to_markdown/feed_include_comments`

Enable or disable comments in the Markdown feed.

```php
add_filter('post_content_to_markdown/feed_include_comments', function () {
    return true;
});
```

**Default:** `false`

#### `post_content_to_markdown/feed_include_excerpt`

Enable or disable post excerpts in the Markdown feed.

```php
add_filter('post_content_to_markdown/feed_include_excerpt', function () {
    return true;
});
```

**Default:** `false`

#### `post_content_to_markdown/feed_cache_duration`

Filter the cache duration for the Markdown feed in seconds.

```php
add_filter('post_content_to_markdown/feed_cache_duration', function ($duration) {
    return 2 * HOUR_IN_SECONDS; // Cache for 2 hours
});
```

**Default:** `HOUR_IN_SECONDS` (1 hour)

### Conversion filters

#### `post_content_to_markdown/converter_options`

Filter the HTML to Markdown converter options.

```php
add_filter('post_content_to_markdown/converter_options', function ($options) {
    return [
        'header_style' => 'setext',           // Use underline style for H1/H2
        'strip_tags' => false,                // Keep HTML tags without markdown equivalents
        'remove_nodes' => 'script style img', // Remove script, style, and img elements
        'hard_break' => false,                // Convert <br> to two spaces + newline
    ];
});
```

**Available options:**
- `header_style`: `'atx'` (default) or `'setext'`
- `strip_tags`: Remove HTML tags without Markdown equivalents (default: `true`)
- `remove_nodes`: Space-separated list of DOM nodes to remove (default: `'script style'`)
- `hard_break`: Convert `<br>` to newlines (default: `true`)

#### `post_content_to_markdown/markdown_output`

Filter the final Markdown output after conversion.

```php
add_filter('post_content_to_markdown/markdown_output', function ($markdown, $original_html) {
    // Add a footer to all Markdown output
    return $markdown . "\n\n---\nConverted from HTML to Markdown";
}, 10, 2);
```

**Parameters:**
- `$markdown`: The converted Markdown text
- `$original_html`: The original HTML content

## Performance

The Markdown feed is cached for 1 hour by default to optimize performance. The cache is automatically cleared when:
- A post is published or updated
- A post is deleted

You can customize the cache duration using the `post_content_to_markdown/feed_cache_duration` filter.

## Resources

* [Serving Markdown Based on Accept Headers and User Agent Detection](https://benword.com/serving-markdown-based-on-accept-headers-and-user-agent-detection)
* [The `text/markdown` Media Type](https://www.rfc-editor.org/rfc/rfc7763.html)
