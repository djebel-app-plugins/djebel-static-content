# djebel-static-content Plugin

## What This Plugin Does

Serves static content from the filesystem: blog posts, documentation pages, and static site pages. Content can be markdown (`.md`), HTML (`.html`), or PHP (`.php`). File format priority: `md,html,php` (configurable).

**Dependency:** Requires `djebel-markdown` plugin for `.md` file processing (frontmatter parsing + markdown-to-HTML conversion).

**Performance model:** listings/scans read only the first 2KB of each `.md` file (front matter + summary); the full file is read ONLY when a single page/post is actually rendered (the `full` ctx flag to the markdown parser).

## Content Types

### 1. Collections (blog, docs, etc.)
Shortcode-driven content listings with pagination, metadata display, and single-post rendering.

**Shortcode:** `[djebel_static_content content_id="blog" title="Blog Posts" results_per_page="10"]`

Each collection reads from its own directory under the data folder.

### 2. Site Content (static HTML pages)
URL-to-file mapping for static site pages. The URL path maps directly to a file in `site_content/{lang}/`.

## Where Content Lives

All content is stored relative to the site root (`sites/djebel-live/`):

```
dj-content/data/app/plugins/djebel-static-content/
    blog/                    <- Blog posts (recommended: blog/YYYY/MM/)
    docs/                    <- Documentation pages (topic-organized)
    test/                    <- Test content
    site_content/            <- Static site pages
        en/                  <- English pages
        bg/                  <- Bulgarian pages
```

The content directory for each collection is determined by the shortcode's `id` parameter.
For example, `[djebel_static_content id="blog"]` reads from the `blog/` directory.

**Private data** (not web-accessible) is at: `.ht_djebel/data/app/plugins/djebel-static-content/{collection_id}/`

## URL Modes

- **Hash mode (default):** URLs include a hash suffix: `/blog/getting-started-dj-abc123def456`
- **Slug mode:** Clean URLs without hash: `/docs/introduction` (enabled per-collection via config)

## Key Hooks

- `app.plugin.static_content.data` - Filter content data before rendering
- `app.plugin.static_content.render_content` - Filter listing HTML
- `app.plugin.static_content.render_single_content` - Filter single post HTML
- `app.plugin.static_content.site_content_dir` - Change the site content dir (lang plugins hook this)
- `app.plugin.static_content.scan_dirs` - Add extra directories to scan
- `app.plugin.static_content.should_include_file` - Filter files during scan

Full hook inventory (17 filters + 3 actions): see the plugin's readme.md.

## Guides

- [content-guide.md](content-guide.md) - How to create blog posts and docs (markdown format)
- [site-content-guide.md](site-content-guide.md) - How to create static HTML site pages
