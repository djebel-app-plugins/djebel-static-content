# Content Creation Guide (Blog Posts and Docs)

This guide covers creating markdown content for blog posts and documentation pages served by the `djebel-static-content` plugin.

## Content Directory

All content lives in:
```
dj-content/data/app/plugins/djebel-static-content/{collection_id}/
```

The `{collection_id}` matches the shortcode's `id` parameter (e.g., `blog`, `docs`).

### Blog Posts
```
blog/
    YYYY/MM/                         <- Date-organized (recommended)
        YYYY-MM-DD-slug.md
    NN-slug-dj-HASH.md              <- Root-level (numbered, hash in filename)
```

### Documentation
```
docs/
    {topic}/
        NN-slug-HASH.md             <- Numbered for ordering
```

## File Naming

### Blog posts (date-organized, recommended)
```
YYYY-MM-DD-descriptive-slug.md
```
Example: `2026-02-15-conditional-config-values-with-dj-if.md`

Keep filenames short. Remove filler words like "how to", "where to", etc.

### Numbered content (docs, root-level blog)
```
NN-descriptive-slug-dj-HASH.md
```
Example: `01-getting-started-dj-abc123def456.md`

The leading number (`NN-`) is stripped from the URL slug. The `-dj-HASH` suffix becomes part of the URL in hash mode.

## Frontmatter

Every markdown file starts with YAML frontmatter between `---` delimiters:

```yaml
---
title: Article Title Here
slug: article-title-here
summary: One or two sentences describing the article.
author: Djebel Team
creation_date: 2026-02-15 14:00:00
category: Configuration
tags: config, environment, app.ini
status: published
hash: 40471b3535bd
---
```

### Field Reference

| Field | Required | Description |
|-------|----------|-------------|
| `title` | Yes | Article title (50-60 chars ideal for SEO) |
| `summary` | Yes | 1-2 sentence description for listings |
| `author` | Yes | Author name. Use `Djebel Team` |
| `creation_date` | Yes | Format: `YYYY-MM-DD HH:MM:SS` |
| `category` | Yes | Single category |
| `tags` | Yes | Comma-separated tags |
| `status` | Yes | `published` or `draft` |
| `hash` | Recommended | 12-char hex identifier for URLs |
| `publish_date` | Optional | Scheduled publication date. Falls back to `creation_date` |
| `slug` | Recommended | Custom URL slug. Auto-generated from the filename if omitted — define it so a file rename doesn't change the URL |
| `sort_order` | Optional | Numeric sort order (default: 0) |
| `meta_title` | Optional | SEO title (falls back to `title`) |
| `meta_keywords` | Optional | SEO keywords |
| `meta_description` | Optional | SEO description |

### Common Categories
- Configuration
- Tutorial
- Documentation
- Announcement
- Development

### Generating Required Fields

Timestamp:
```bash
date +"%Y-%m-%d %H:%M:%S"
```

Hash (12-char unique identifier):
```bash
openssl rand -hex 6
```

## Article Structure

### 1. H1 Title
Repeat the title as H1 immediately after frontmatter:
```markdown
# Article Title Here
```

### 2. Opening paragraphs
Short, engaging intro. 2-4 paragraphs.

### 3. Horizontal rule separator
```markdown
---
```

### 4. Main sections with H2
Separate major sections with `---`:
```markdown
## Section Title

Content here...

---

## Next Section

More content...
```

### 5. Closing section
End with actionable next steps:
```markdown
## Your Next Step

Wrap-up content...
```

## Formatting

- Do NOT bold (`**word**`) or italicize (`*word*`) words in body text unless the site
  owner explicitly requests it. Emphasis and large fonts come from headings and
  subheadings only — if something deserves emphasis, it deserves its own heading or
  its own sentence.
- Inline code in backticks (commands, file names, config keys, URLs) is fine — that is
  code formatting, not emphasis.

## Content Linking

Link to other content using the `(@dj:hash_id)` syntax:

```markdown
# Auto-titled link (uses the target's title as link text)
Check out (@dj:abc123def456)

# Empty brackets (also auto-titled)
See also [](@dj:abc123def456)

# Custom link text
Read the [Getting Started Guide](@dj:abc123def456)
```

The `hash_id` must be 10-15 alphanumeric characters matching the target content's hash.

## Status and Scheduling

- `status: published` - Visible in listings, accessible by URL
- `status: draft` - Hidden from listings, not served
- `publish_date` in the future - Content is scheduled; hidden until that date

## URL Generation

### Hash mode (default)
File: `blog/2026/02/2026-02-15-my-article.md` with `hash: 40471b3535bd`
URL: `/blog/my-article-dj-40471b3535bd`

### Slug mode (per-collection config)
File: `docs/intro/01-introduction-xyz789abc123.md` with `slug: introduction`
URL: `/docs/introduction`

## Full Example: Blog Post

File: `dj-content/data/app/plugins/djebel-static-content/blog/2026/02/2026-02-24-building-plugins-with-djebel.md`

```markdown
---
title: Building Plugins with Djebel
slug: building-plugins-with-djebel
summary: A practical guide to creating your first Djebel plugin, from file structure to hooks and filters.
author: Djebel Team
creation_date: 2026-02-24 10:00:00
category: Development
tags: plugins, development, hooks, tutorial
status: published
hash: 7a3f9b2c1d4e
---

# Building Plugins with Djebel

Djebel's plugin system is designed to be simple. No complex patterns to learn, no abstract factories, no dependency injection containers.

Just a PHP file, a class, and hooks.

---

## Plugin File Structure

Every plugin needs a `plugin.php` file in its directory. The framework scans plugin directories and loads this file automatically.

---

## Registering Hooks

Use `Dj_App_Hooks::addFilter()` to hook into the framework...

---

## Your Next Step

Create a new directory in `dj-content/plugins/`, add a `plugin.php`, and register your first filter. Start small, build up.
```

## Quick Creation Checklist

- [ ] Generate timestamp: `date +"%Y-%m-%d %H:%M:%S"`
- [ ] Generate hash: `openssl rand -hex 6`
- [ ] Create filename: `YYYY-MM-DD-descriptive-slug.md`
- [ ] Place in correct directory: `blog/YYYY/MM/` for blog posts
- [ ] Add complete frontmatter (title, slug, summary, author, creation_date, category, tags, status, hash)
- [ ] Define the slug explicitly so a file rename doesn't change the URL
- [ ] Write H1 title matching frontmatter title
- [ ] Use H2 sections separated by `---`
- [ ] Set status to `published` or `draft`
