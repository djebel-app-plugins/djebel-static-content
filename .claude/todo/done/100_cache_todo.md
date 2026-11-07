# Cache - todo

- [x] [P100] `?dj_cache=0` does not bypass the content cache, although the comment at
  `plugin.php:801` and the readme's Configuration block (`readme.md:118`) say it does;
  `?dj_cache=no`, `off` and `false` work (run, 2026-10-22). Cause (read, 2026-10-27): core
  `Dj_App_Request::get()` (`request.php:533`) returns the default for any `empty()` value, so
  `'0'` arrives as `''`, which `Dj_App_Util::isDisabled()` treats as not set. Decided
  2026-10-28: core `get()` keeps `'0'`, and djebel-app carries the fix. Done 2026-11-05 (run: on
  a site on that core, `/blog?dj_cache=0` took as long as `?dj_cache=no`, 0.067s against 0.046s
  from the cache; on a site still on the older core, `=0` stayed at the cached time).
