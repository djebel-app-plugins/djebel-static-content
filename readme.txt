# Djebel Static Blog Plugin

A simple, fast, and flexible static blog plugin for Djebel that uses markdown files.

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

### Cache

Enable/disable caching:
```
plugins.djebel-static-blog.cache = 1
```

### Sorting

Set default sort field:
```
plugins.djebel-static-blog.sort_by = creation_date
```

Options: file, creation_date, last_modified, title, sort_order

### Additional Scan Directories

```
plugins.djebel-static-blog.scan_dirs = /path/to/dir1,/path/to/dir2
```

## Hooks and Filters

### Filters

- `app.plugin.static_content.statuses` - Modify available statuses
- `app.plugin.static_content.sort_by` - Modify sort field
- `app.plugin.static_content.scan_dirs` - Modify scan directories
- `app.plugin.static_content.data` - Modify blog data before rendering

## Pagination

Pagination uses the query parameter `djebel_plugin_static_content_page`.

## Requirements

- PHP 7.4+
- djebel-markdown plugin
