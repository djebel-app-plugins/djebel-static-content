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
- [ ] [P150] djebel-markdown's `.claude/CLAUDE.md` ("5. Content Linking"),
  `.claude/markdown-format.md` ("Content Linking") and `readme.txt` (`:41`, `:61`) document the
  links as its own feature, resolved through an `app.plugins.markdown.resolve_content_reference`
  hook with 10-15 character hashes. Nothing fires that hook (a grep of both plugins finds it only
  in those docs); this plugin resolves the links on `pre_process_content` (`plugin.php:85`) and
  takes 8-15 characters (`plugin.php:37-38`) (read, 2026-11-07). Done when those sections are
  gone from djebel-markdown's docs and `.claude/content-guide.md` here is the one place that
  documents the syntax, with the 8-15 the code takes instead of its own "10-15"
  (`content-guide.md:192`).
