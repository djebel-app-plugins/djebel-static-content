# Djebel Static Content Plugin

A simple, fast, and flexible static content plugin for Djebel. Serves blog
posts, docs, and whole site pages from files — markdown (`.md`), HTML
(`.html`), or PHP (`.php`).

## Performance model: partial scans, full single loads

Listings/scans read only the FIRST 2KB of each markdown file (front matter +
enough for summaries) via the markdown plugin's partial parse. The full file
is read ONLY when a single piece of content is actually rendered:

- a site page render
- a single post view
- prepend/append config content (full mode only)

Scan results are cached (see Cache below), so a listing costs one 2KB read
per file once per cache window.

## Content URL modes

### 1. Hash Mode (default)
- **URLs**: `/collection/slug-dj-abc123def456` (hash appended to slug)
- Content indexed by hash_id: front matter `hash_id` > `hash` > `id` meta,
  or a `-dj-HASH` marker in the filename; falls back to the slug
- **Example**: `/blog/getting-started-dj-abc123def456`

### 2. Slug Mode (per-collection config)
- **URLs**: `/collection/slug` (clean URLs, no hash)
- Content indexed by slug (hash_id = slug internally)
- **Example**: `/pages/about`

```ini
[plugins]
djebel-static-content.pages.use_content_slugs = 1
```

### Important internal behavior
- In slug mode the `hash_id` field contains the slug value, so
  `$content_data[$hash_id]` works in both modes — content is ALWAYS indexed
  by hash_id.

## Features

- Markdown-based blog posts and docs collections
- Whole site pages from files (site content — see below)
- Multiple scan directories, recursive scanning
- Status support (draft, published) + scheduled publishing via publish_date
- Pagination, sorting (file, creation_date, last_modified, title, sort_order)
- Tags and categories; per-page meta for the SEO plugin
- Prepend/append config content around single posts
- Caching for performance; extensible with hooks and filters

## Usage

### Listing shortcode

```
[djebel_static_content content_id="blog" results_per_page="15" template_file="blog.php"]
```

### Shortcode parameters

- `content_id` — collection id, i.e. the content subdirectory (alias:
  `section_id`; default: `default`)
- `results_per_page` — posts per page (alias: `per_page`)
- `title` — listing title (default: `Blog Posts`)
- `render_title` — display the title (0 or 1)
- `template_file` — theme template that renders the listing loop

### Single content shortcode

```
[djebel_static_content_post content_id="blog"]
```

Renders the single post the current URL's hash_id points at.

## Post front matter

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
hash: abc123def456
sort_order: 10
```

All fields are optional. `hash` is a 12-char hex id (generate:
`openssl rand -hex 6`) — it keeps post URLs stable across renames.
Define `slug` explicitly too: when omitted it is derived from the
filename, so renaming the file changes the URL. Setting both `slug`
and `hash` pins the URL for good.
`meta_title` / `meta_keywords` / `meta_description` are also read and
published to the SEO plugin.

## File naming

- Blog posts (date-organized, recommended): `blog/YYYY/MM/YYYY-MM-DD-slug.md`
- Numbered content: `001-first-post.md` — the leading number is stripped
  from the slug
- Hash-in-filename: `01-getting-started-dj-abc123def456.md`

## Configuration

Add to the site's `app.ini`, `[plugins]` section:

```ini
; Cache (global and per collection); ?dj_cache=0 bypasses per request
djebel-static-content.cache = 1
djebel-static-content.blog.cache = 0
djebel-static-content.cache_ttl = 14400
djebel-static-content.blog.cache_ttl = 3600

; Sorting: file, creation_date, last_modified, title, sort_order
djebel-static-content.sort_by = creation_date

; Additional scan directories
djebel-static-content.scan_dirs = /dir1,/dir2

; Metadata display in listings (0/1)
djebel-static-content.show_date = 0
djebel-static-content.show_tags = 0
djebel-static-content.show_author = 0
djebel-static-content.show_summary = 1
djebel-static-content.show_category = 0

; Map URL substrings to theme template files
djebel-static-content.url_contains[/blog] = "template_file=blog.php"
```

## Site Content feature

Serves whole site pages from files — design stays in the theme, content in
files. Two directory layouts:

```ini
[plugins]
; Layout A (default): site_content/ — optionally with language subdirs
; (site_content/en/home.html) when a lang plugin sets the dir
djebel-static-content.site_content_dir = site_content

; Layout B: a flat pages/ dir with .md files (front matter: title, summary,
; status) — used by markdown-first sites
djebel-static-content.site_content_dir = pages

; File extension priority (default: md,html,php)
djebel-static-content.content.file_ext = md,html,php

; Enable/disable the feature (default: enabled)
djebel-static-content.site_content_enabled = 1
```

### How it works

1. Request comes in (e.g. `/contact`)
2. The plugin maps the URL to a file: `contact.{md|html|php}`, falling back
   to `contact/index.{ext}`; the home page uses `home.{ext}`
3. `.md` files get front matter parsed + markdown converted (full read);
   `.php` files are executed and output captured; `.html` served as-is
4. The theme template wraps the content; `url_contains` config can route a
   URL to a specific theme template

## Hooks

### Filters

| Filter | Purpose |
|---|---|
| `app.plugin.static_content.content_extensions` | Reorder/add/remove file extensions |
| `app.plugin.static_content.site_content_dir` | Change the site content dir (lang plugins hook this) |
| `app.plugin.static_content.data` | Modify content data before rendering |
| `app.plugin.static_content.render_content` | Filter listing HTML |
| `app.plugin.static_content.render_single_content` | Filter single post HTML |
| `app.plugin.static_content.scan_dirs` | Add extra scan directories |
| `app.plugin.static_content.should_include_file` | Filter files during scan |
| `app.plugin.static_content.statuses` | Modify available statuses |
| `app.plugin.static_content.sort_by` / `.sort_callback` | Sorting behavior |
| `app.plugin.static_content.results_per_page` | Listing page size |
| `app.plugin.static_content.post_slug` | Modify a post's slug |
| `app.plugin.static_content.content_url` / `.url_params` / `.url_parts` / `.generate_content_url_data` | Content URL building |
| `app.plugin.static_content.default_data_fields` | Extend the page data fields published to other plugins |

### Actions

| Action | Fires |
|---|---|
| `app.plugin.static_content.pre_load_content` | Before content loads |
| `app.plugin.static_content.post_load_post` | After a single post loads |
| `app.plugin.static_content.post_load_listing` | After a listing loads |

### Example: reorder extensions

```php
$obj = My_Plugin::getInstance();
Dj_App_Hooks::addFilter('app.plugin.static_content.content_extensions', [ $obj, 'filterContentExtensions' ]);

// In your class:
public function filterContentExtensions($extensions, $ctx = [])
{
    $result = [ 'md', 'html', ]; // drop php support

    return $result;
}
```

## Pagination

Pagination uses the query parameter `djebel_plugin_static_content_page`.

## Install

```bash
git submodule add https://github.com/djebel-app-plugins/djebel-static-content.git dj-content/plugins/djebel-static-content
```

Djebel auto-loads plugins from the plugins dir — no registration needed.

## Requirements

- PHP 7.4+
- djebel-markdown plugin (for `.md` parsing/conversion + the partial-read
  performance model)
