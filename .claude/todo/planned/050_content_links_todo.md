# Content links - todo

- [ ] [P50] `(@dj:hash)` content links, documented in `.claude/content-guide.md` ("Content
  Linking"), are never resolved: `[text](@dj:13fea6573638)` renders as
  `href="@dj%3A13fea6573638"` and the bare `(@dj:13fea6573638)` stays as plain text, for a hash
  that exists in the `blog` collection (run, 2026-10-27). `processContentLinks()`
  (`plugin.php:1759`) does run on `app.plugins.markdown.pre_process_content`, but the markdown
  plugin's `$ctx` carries only `file`, `full` and `meta`, so `getContentData($ctx)` looks the
  hash up in the `default` collection. Read, not run: `findOpeningBracket()`
  (`plugin.php:1835`) starts at `$pos - 1`, the `]` of `[text]`, so the custom-text form would
  still lose its text once the lookup works.
