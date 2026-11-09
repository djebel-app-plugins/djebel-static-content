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
        NN-slug-dj-HASH.md          <- Numbered for ordering
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
| `hash` | In hash mode, unless the file name has `-dj-HASH` | 12-char hex identifier for URLs (`hash_id` or `id` also work). A post with none of them is listed but has no page of its own |
| `publish_date` | Optional | Scheduled publication date. Falls back to `creation_date` |
| `slug` | Recommended | Custom URL slug. Auto-generated from the filename if omitted — define it so a file rename doesn't change the URL |
| `sort_order` | Optional | Numeric sort order (default: 0) |
| `meta_title` | Optional | SEO title (falls back to `title`) |
| `meta_keywords` | Optional | SEO keywords |
| `meta_description` | Optional | SEO description |
| `featured_image` | Optional | Image shown between the title and the post text, with the title as its alt text: a site path such as `/dj-content/files/images/2026/02/2026-02-15-my-post.webp` (dated like the post), or an `https://` URL. Write it without quotes |

### Common Categories
- Configuration
- Tutorial
- Documentation
- Announcement
- Development

### Generating Required Fields

Timestamp, in the site's time zone (`[site] timezone` in `app.ini`). The plugin compares
dates in that zone, so a time taken in another zone can hide a new post as scheduled for
hours:
```bash
TZ=America/Toronto date +"%Y-%m-%d %H:%M:%S"
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

The text every post ends with (a call to action, a contact invite) is not written into the
post. It comes from the collection's `.config/append_content.md` - see
[Text Added to Every Post](#text-added-to-every-post).

## Formatting

- Do NOT bold (`**word**`) or italicize (`*word*`) words in body text unless the site
  owner explicitly requests it. Emphasis and large fonts come from headings and
  subheadings only - if something deserves emphasis, it deserves its own heading or
  its own sentence.
- Inline code in backticks (commands, file names, config keys, URLs) is fine - that is
  code formatting, not emphasis.
- Use regular dashes (`-`) in content text - never em (—) or en (–) dashes.
- Links to a page on the same site start with `/`: `[contact us](/contact)`. When the site
  runs under a sub-path, such as a dev or staging copy at `/projects/my-site/`, Djebel adds
  that path to the link.

## Language

When writing content in a non-English language, phrase it as close to native as
possible - translate the meaning, not the sentences word for word. If a phrase only
works in the source language, say the same idea the way a native writer would say it.

## Fact Check (before a post is done)

Self-check every post, in every language, to make sure things are factually correct
and not invented:

- URLs open the page they claim - verify each link exists, never guess one
- Commands and their sample outputs are real - run or verified, not made up
- Product names, tool claims, and technical statements are accurate
- If something cannot be verified, leave it out

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

## Text Added to Every Post

Text that every post in a collection shows, such as a call to action or a disclaimer, is
written once instead of being copied into each post. It goes in the collection's `.config/`
folder:

```
blog/
    .config/
        prepend_content.md    <- added before the text of every post
        append_content.md     <- added after the text of every post
    2026/10/2026-10-25-my-post.md
```

Example `blog/.config/append_content.md`:

```markdown
Questions about this post? [Write to us](/contact).
```

- Both files are optional. Write plain markdown; front matter is allowed and ignored.
- Don't open the file with a `# ` heading: like a post's first line, it is taken as a title
  and dropped. Start with text, or with `##`.
- The prepended text comes right after the post title, before the first paragraph. The
  appended text comes after the post's last line, below a horizontal rule (`---`) the plugin
  adds, so the file does not start with one.
- The text is added only when a single post is shown. Listings and summaries never show it.
- `.config/` starts with a dot, so it is never listed as a post.
- Each collection has its own `.config/`, and each site its own data folder, so a site in
  another language keeps its own text.

To turn it off, in `app.ini`:

```ini
[plugins]
; for one collection
djebel-static-content.blog.inject_config_content = 0
; for every collection
djebel-static-content.inject_config_content = 0
```

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

- [ ] Generate timestamp in the site's time zone: `TZ=<site timezone> date +"%Y-%m-%d %H:%M:%S"`
- [ ] Generate hash: `openssl rand -hex 6`
- [ ] Create filename: `YYYY-MM-DD-descriptive-slug.md`
- [ ] Place in correct directory: `blog/YYYY/MM/` for blog posts
- [ ] Add complete frontmatter (title, slug, summary, author, creation_date, category, tags, status, hash)
- [ ] Define the slug explicitly so a file rename doesn't change the URL
- [ ] No bold/italic in body text; regular dashes only (no em/en dashes)
- [ ] No closing call to action in the post; it comes from `.config/append_content.md`
- [ ] Same-site links start with `/`
- [ ] Fact-check: every URL, command, and output is real and verified
- [ ] Write H1 title matching frontmatter title
- [ ] Use H2 sections separated by `---`
- [ ] Set status to `published` or `draft`
