# Djebel Static Content Plugin

A simple, fast, and flexible static content plugin for Djebel that uses markdown files.

## Overview

The plugin supports two modes of operation for content URLs and identification:

### 1. Hash Mode (default)
- **URLs**: `/collection/slug-abc123def` (hash appended to slug)
- Content indexed by hash_id extracted from filename or meta
- Fast detection via parseHashId() using regex pattern
- **Example**: `/blog/getting-started-abc123def456`

### 2. Slug Mode (per-collection config)
- **URLs**: `/collection/slug` (clean URLs, no hash)
- Content indexed by slug (hash_id = slug internally)
- Requires `use_content_slugs` enabled in collection config
- Detection via parseSlugOrHashId() with content lookup
- **Example**: `/pages/about`

### Important Internal Behavior

- In slug mode, the 'hash_id' field contains the slug value
- This allows unified content lookup: `$content_data[$hash_id]` works in both modes
- Content is ALWAYS indexed by hash_id in the content_data array
- The difference is: hash mode uses generated hash, slug mode uses slug as hash_id

### URL Routing Differences

- **Hash mode**: parseHashId() succeeds → direct rendering via renderContent()
- **Slug mode**: parseHashId() fails → 404 handler → handleFileNotFound() → slug lookup
- **With parseSlugOrHashId()**: both modes can use direct rendering (optimized path)

## Features

- Markdown-based blog posts
- Multiple scan directories support
- Recursive file scanning
- Status support (draft, published)
- Scheduled publishing with publish_date
- Pagination support
- Sorting by file, creation_date, last_modified, title, or sort_order
- Tags and categories
- Caching for performance
- Extensible with hooks and filters

## Usage

### Basic Shortcode

```
[djebel_static_content]
```

### Shortcode Parameters

- `id` - Collection ID (default: 'default')
- `title` - Blog title (default: 'Blog Posts')
- `render_title` - Display title (0 or 1)
- `per_page` - Posts per page (default: 10)

### Example

```
[djebel_static_content id="news" title="Latest News" render_title="1" per_page="5"]
```

## Post Front Matter

Create markdown files with the following optional front matter:

```
title: My Blog Post Title
summary: A short description of the post
author: John Doe
creation_date: 2025-01-15
publish_date: 2025-01-20
category: Technology
tags: php, blogging, djebel
status: published
slug: custom-url-slug
id: abc123def456
sort_order: 10
```

## File Naming

Files can be named with optional leading numbers for ordering:
- `001-first-post.md`
- `02-second-post.md`

The numbers and separators are automatically stripped from the slug.

## Configuration

Add to your `app.ini` file:

### Cache

Enable/disable caching:
```ini
[plugins]
djebel-static-content.cache = 1
```

### Sorting

Set default sort field:
```ini
[plugins]
djebel-static-content.sort_by = creation_date
```

Options: file, creation_date, last_modified, title, sort_order

### Additional Scan Directories

```ini
[plugins]
djebel-static-content.scan_dirs = /path/to/dir1,/path/to/dir2
```

### Slug Mode Configuration

Enable slug mode for a collection (clean URLs without hash):
```ini
[plugins]
djebel-static-content.collections[pages].use_content_slugs = 1
```

## Hooks and Filters

### Filters

- `app.plugin.static_content.statuses` - Modify available statuses
- `app.plugin.static_content.sort_by` - Modify sort field
- `app.plugin.static_content.scan_dirs` - Modify scan directories
- `app.plugin.static_content.data` - Modify blog data before rendering

## Pagination

Pagination uses the query parameter `djebel_plugin_static_content_page`.

## Site Content Feature

Serve static pages from a `site_content/` folder, allowing simple themes with just an `index.php`.

### How It Works

1. Request comes in (e.g., `/contact`)
2. Theme looks for `pages/contact.php` (not found)
3. 404 hook fires
4. Plugin checks `site_content/contact.{md, html, php}` (configurable order)
5. Plugin finds theme template:
   - `{theme}/templates/contact.php` (specific)
   - `{theme}/templates/{parent}.php` (for nested paths)
   - `{theme}/index.php` (fallback)
6. Content rendered through template

### Folder Structure

```
dj-content/
├── data/app/plugins/djebel-static-content/
│   └── site_content/           # Site pages
│       ├── home.md
│       ├── contact.md
│       ├── about.md
│       └── docs/
│           └── intro.md        # Accessible at /docs/intro
├── themes/{theme}/
│   ├── index.php               # Main layout (fallback template)
│   └── templates/              # Optional page-specific templates
│       ├── contact.php
│       └── docs.php            # For /docs/* pages
```

### Configuration

```ini
[plugins]
; Directory name (default: site_content)
djebel-static-content.site_content_dir = site_content

; File extension priority (default: php,html,md)
djebel-static-content.content.file_ext = php,html,md

; Enable/disable feature (default: true)
djebel-static-content.site_content_enabled = 1
```

### Supported File Types

- `.php` - Executed and output captured
- `.html` - Served directly
- `.md` - Raw content passed to `site_content` filter (other plugins can convert)

### Hooks

| Hook | Type | Purpose |
|------|------|---------|
| `app.plugin.static_content.site_content` | Filter | Post-process content |
| `app.plugin.static_content.site_content_file` | Filter | Modify content file path |
| `app.plugin.static_content.content.file_ext` | Filter | Reorder/add/remove extensions |

### Hook Examples

**Reorder extensions (markdown first):**
```php
$obj = My_Plugin::getInstance();
Dj_App_Hooks::addFilter('app.plugin.static_content.content.file_ext', [ $obj, 'filterFileExt', ]);

// In your class:
public function filterFileExt($extensions, $ctx)
{
    $result = [ 'md', 'html', 'php', ];

    return $result;
}
```

**Add custom extension:**
```php
public function filterFileExt($extensions, $ctx)
{
    $extensions[] = 'txt';

    return $extensions;
}
```

**Remove PHP support (security):**
```php
public function filterFileExt($extensions, $ctx)
{
    $filtered = [];

    foreach ($extensions as $ext) {
        if ($ext === 'php') {
            continue;
        }

        $filtered[] = $ext;
    }

    return $filtered;
}
```

**Per-page customization:**
```php
public function filterFileExt($extensions, $ctx)
{
    $page = empty($ctx['page']) ? '' : $ctx['page'];

    // Only allow markdown for docs section
    if (strpos($page, 'docs/') === 0) {
        $result = [ 'md', ];

        return $result;
    }

    return $extensions;
}
```

## Requirements

- PHP 7.4+
- djebel-markdown plugin (optional, for .md file conversion)
