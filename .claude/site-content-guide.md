# Site Content Guide (Static HTML Pages)

This guide covers creating static HTML site pages served by the `djebel-static-content` plugin. These are full site pages (home, about, contact, etc.), not blog posts or docs.

## Content Directory

```
dj-content/data/app/plugins/djebel-static-content/site_content/
    en/                    <- English pages
    bg/                    <- Bulgarian pages
    {lang_code}/           <- Any language
```

## Supported File Formats

- `.html` - Primary format for site pages on this site (djebel-live convention)
- `.md` - Markdown (processed by djebel-markdown plugin; front matter supported)
- `.php` - PHP files (executed, output captured)

Format priority when multiple files exist: `md,html,php` (default; configurable
via `djebel-static-content.content.file_ext`)

## URL-to-File Mapping

The URL path maps directly to a file in the language directory:

| URL Path | File Checked | Fallback |
|----------|-------------|----------|
| `/` or empty | `home.{ext}` | — |
| `/about` | `about.{ext}` | `about/index.{ext}` |
| `/docs/latest` | `docs/latest.{ext}` | `docs/latest/index.{ext}` |
| `/support/faq` | `support/faq.{ext}` | `support/faq/index.{ext}` |

The plugin tries each extension in priority order (`md`, `html`, `php` by default), then falls back to looking for an `index.{ext}` file in a matching directory.

## Current Site Structure (English)

```
site_content/en/
    home.html              <- Homepage (/)
    about.html             <- /about
    blog.html              <- /blog (listing page)
    contact.html           <- /contact
    downloads.html         <- /downloads
    sponsors.html          <- /sponsors
    support.html           <- /support
    about/
        author.html        <- /about/author
        vision.html        <- /about/vision
    docs/
        latest.html        <- /docs/latest
    support/
        faq.html           <- /support/faq
```

## HTML Format

Site pages use plain HTML. No frontmatter needed. The content is injected into the active theme template.

### Shortcodes

Shortcodes are supported and processed before rendering:

```html
[djebel-simple-newsletter cta_text="Subscribe" render_agree=1 auto_focus=0]
[djebel_static_content id="blog" title="Blog Posts" per_page="10"]
```

### Site Variables

Use placeholder variables that get replaced at render time:

| Variable | Description |
|----------|-------------|
| `__SITE_WEB_PATH__` | Base URL path of the site |

Example usage:
```html
<a href="__SITE_WEB_PATH__/vision">Djebel Vision</a>
```

## Full Example: Site Page

File: `site_content/en/features.html`

```html
<div>
    <h2>Features</h2>
    <div>
        Djebel comes with a powerful set of features designed for simplicity and speed.
    </div>

    <br/>

    <div>
        <h3>Plugin System</h3>
        <ul>
            <li>Drop-in plugin architecture</li>
            <li>Hooks and filters for extensibility</li>
            <li>No complex dependency management</li>
        </ul>
    </div>

    <br/>

    <div>
        <h3>Want updates?</h3>
        [djebel-simple-newsletter cta_text="Subscribe to our newsletter" render_agree=1 auto_focus=0]
    </div>

    <div>
        <br>
        Learn more about <a href="__SITE_WEB_PATH__/about">our story</a>
    </div>
</div>
```

## Multi-Language Support

To add a page in another language, create the same file path under a different language directory:

```
site_content/en/home.html    <- English homepage
site_content/bg/home.html    <- Bulgarian homepage
```

The active language determines which directory is used.

## Notes

- HTML site pages do NOT use frontmatter; `.md` site pages DO support it
  (title, summary, status) — markdown-first sites use a flat `pages/` dir
  via `djebel-static-content.site_content_dir = pages`
- HTML is served directly (no markdown processing unless the file is `.md`)
- The theme template wraps the page content (header, footer, navigation)
- Shortcodes inside HTML pages are processed before output
- Use `<br/>` for line breaks within HTML content
