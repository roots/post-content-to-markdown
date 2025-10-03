# Post Content to Markdown

A WordPress plugin that returns post content in Markdown format when requested with an `Accept` header set to `text/markdown`.

## Usage

Visit any single post on your site with the `Accept` header set to `text/markdown` to get the post content directly as Markdown.

**Example:**

```bash
curl -H "Accept: text/markdown" https://example.com/my-awesome-post
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

## Filters

The plugin provides several filters for customization:

### `post_content_to_markdown/post_types`

Filter the post types that can be served as markdown.

```php
add_filter('post_content_to_markdown/post_types', function ($post_types) {
    // Add support for pages and custom post types
    return ['post', 'page', 'product'];
});
```

**Default:** `['post']`

### `post_content_to_markdown/converter_options`

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
- `strip_tags`: Remove HTML tags without markdown equivalents (default: `true`)
- `remove_nodes`: Space-separated list of DOM nodes to remove (default: `'script style'`)
- `hard_break`: Convert `<br>` to newlines (default: `true`)

### `post_content_to_markdown/markdown_output`

Filter the final markdown output after conversion.

```php
add_filter('post_content_to_markdown/markdown_output', function ($markdown, $original_html) {
    // Add a footer to all markdown output
    return $markdown . "\n\n---\nConverted from HTML to Markdown";
}, 10, 2);
```

**Parameters:**
- `$markdown`: The converted markdown text
- `$original_html`: The original HTML content
