<?php
/*
plugin_name: Djebel Static Content
plugin_uri: https://djebel.com/plugins/djebel-static-content
description: Static blog using markdown files with support for multiple directories and recursive scanning
version: 1.0.0
load_priority: 20
tags: blog, markdown, static, posts
stable_version: 1.0.0
min_php_ver: 7.4
min_dj_app_ver: 1.0.0
tested_with_dj_app_ver: 1.0.0
author_name: Svetoslav Marinov (Slavi)
company_name: Orbisius
author_uri: https://orbisius.com
text_domain: djebel-static-content
license: gpl2
requires: djebel-markdown
*/

$obj = Djebel_Plugin_Static_Content::getInstance();
Dj_App_Hooks::addAction('app.core.init', [$obj, 'init']);

class Djebel_Plugin_Static_Content
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    private $statuses = [ 
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
    ];

    public const DEFAULT_RECORDS_PER_PAGE = 10;

    private $plugin_id = 'djebel-static-content';
    private $cache_dir = '';
    private $sort_by = 'publish_date';
    private $request_param_key = 'djebel_plugin_static_content_data';

    public function init()
    {
        $this->cache_dir = Dj_App_Util::getCoreCacheDir(['plugin' => $this->plugin_id]);

        $shortcode_obj = Dj_App_Shortcode::getInstance();
        $shortcode_obj->addShortcode('djebel_static_content', [$this, 'renderContent']);
        $shortcode_obj->addShortcode('djebel_static_content_post', [$this, 'renderSingleContent']);

        // Hook into theme's page file candidates to add content template options
        Dj_App_Hooks::addFilter('app.themes.current_theme_page_file_candidates', [$this, 'addPageFileCandidates'], 10, 2);
    }

    public function getStatuses()
    {
        $statuses = $this->statuses;
        $statuses = Dj_App_Hooks::applyFilter('app.plugin.static_content.statuses', $statuses);

        return $statuses;
    }

    public function renderSingleContent($params = [])
    {
        $req_obj = Dj_App_Request::getInstance();
        $plugin_params = $req_obj->get($this->request_param_key, []);
        $hash_id = !empty($plugin_params['hash_id']) ? $plugin_params['hash_id'] : '';

        if (empty($hash_id)) {
            return "<!--\nNo post hash_id provided\n-->";
        }

        $content_data = $this->getContentData($params);

        if (empty($content_data[$hash_id])) {
            return "<!--\nPost not found\n-->";
        }

        $post_rec = $content_data[$hash_id];

        // Reload with full content for single post view
        $post_rec = $this->loadPostFromMarkdown(['file' => $post_rec['file'], 'full' => 1]);

        if (empty($post_rec)) {
            return "<!--\nFailed to load post content\n-->";
        }

        $options_obj = Dj_App_Options::getInstance();
        $show_date = $options_obj->isEnabled('plugins.djebel-static-content.show_date');
        $show_author = $options_obj->isEnabled('plugins.djebel-static-content.show_author');
        $show_category = $options_obj->isEnabled('plugins.djebel-static-content.show_category');
        $show_tags = $options_obj->isEnabled('plugins.djebel-static-content.show_tags');

        ob_start();
        ?>
        <article class="djebel-plugin-static-content-post-single">
            <h1 class="djebel-plugin-static-content-post-single-title"><?php echo Djebel_App_HTML::encodeEntities($post_rec['title']); ?></h1>

            <?php if ($show_date || $show_author || $show_category): ?>
                <div class="djebel-plugin-static-content-post-single-meta">
                    <?php if ($show_date && !empty($post_rec['creation_date'])): ?>
                        <span><?php echo Djebel_App_HTML::encodeEntities(date('F j, Y', strtotime($post_rec['creation_date']))); ?></span>
                    <?php endif; ?>

                    <?php if ($show_author && !empty($post_rec['author'])): ?>
                        <span> · by <?php echo Djebel_App_HTML::encodeEntities($post_rec['author']); ?></span>
                    <?php endif; ?>

                    <?php if ($show_category && !empty($post_rec['category'])): ?>
                        <span> · <?php echo Djebel_App_HTML::encodeEntities($post_rec['category']); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($show_tags && !empty($post_rec['tags'])): ?>
                <div class="djebel-plugin-static-content-post-single-tags">
                    <?php foreach ($post_rec['tags'] as $tag): ?>
                        <span class="djebel-plugin-static-content-tag"><?php echo Djebel_App_HTML::encodeEntities($tag); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="djebel-plugin-static-content-post-single-content">
                <?php echo $post_rec['content']; ?>
            </div>
        </article>
        <?php
        $html = ob_get_clean();
        $ctx = ['post_rec' => $post_rec];
        $html = Dj_App_Hooks::applyFilter('app.plugin.static_content.render_single_content', $html, $ctx);

        return $html;
    }

    public function renderContent($params = [])
    {
        $req_obj = Dj_App_Request::getInstance();
        $plugin_params = $req_obj->get($this->request_param_key, []);

        // Auto-detect if this is a single post request by parsing hash_id from URL
        $hash_id = $this->parseHashId();

        if (!empty($hash_id)) {
            // Inject hash_id into plugin params array
            $plugin_params['hash_id'] = $hash_id;
            $req_obj->set($this->request_param_key, $plugin_params);

            // Pass template file internally (not via request params for security)
            if (!empty($params['template_file'])) {
                Dj_App_Util::data('djebel_static_content_template_file', $params['template_file']);
            }

            // Delegate to renderSingleContent for single post rendering
            return $this->renderSingleContent($params);
        }

        // Render content listing
        $title = empty($params['title']) ? 'Blog Posts' : trim($params['title']);
        $render_title = empty($params['render_title']) ? 0 : 1;
        $content_data = $this->getContentData($params);

        if (empty($content_data)) {
            return "<!--\nNo content available\n-->";
        }
        $current_page = !empty($plugin_params['page']) ? (int) $plugin_params['page'] : 1;
        $current_page = max(1, $current_page);

        $per_page = empty($params['per_page']) ? self::DEFAULT_RECORDS_PER_PAGE : (int) $params['per_page'];
        $total_posts = count($content_data);
        $total_pages = ceil($total_posts / $per_page);
        $offset = ($current_page - 1) * $per_page;

        $content_data = array_slice($content_data, $offset, $per_page, true);

        if (empty($content_data)) {
            return "<!--\nNo content available on this page\n-->";
        }

        ob_start();
        ?>
        <div class="djebel-plugin-static-content-container">
            <?php if ($render_title || !empty($params['title'])): ?>
                <h2 class="djebel-plugin-static-content-title"><?php echo Djebel_App_HTML::encodeEntities($title); ?></h2>
            <?php endif; ?>

            <?php
            $options_obj = Dj_App_Options::getInstance();
            $show_date = $options_obj->isEnabled('plugins.djebel-static-content.show_date');
            $show_author = $options_obj->isEnabled('plugins.djebel-static-content.show_author');
            $show_category = $options_obj->isEnabled('plugins.djebel-static-content.show_category');
            $show_summary = $options_obj->isEnabled('plugins.djebel-static-content.show_summary', 1); // default enabled
            $show_tags = $options_obj->isEnabled('plugins.djebel-static-content.show_tags');
            ?>
            <?php foreach ($content_data as $post_rec): ?>
                <article class="djebel-plugin-static-content-post">
                    <h3 class="djebel-plugin-static-content-post-title">
                        <a href="<?php echo Djebel_App_HTML::encodeEntities($post_rec['url']); ?>">
                            <?php echo Djebel_App_HTML::encodeEntities($post_rec['title']); ?>
                        </a>
                    </h3>

                    <?php if ($show_date || $show_author || $show_category): ?>
                        <div class="djebel-plugin-static-content-post-meta">
                            <?php if ($show_date && !empty($post_rec['creation_date'])): ?>
                                <span><?php echo Djebel_App_HTML::encodeEntities(date('F j, Y', strtotime($post_rec['creation_date']))); ?></span>
                            <?php endif; ?>

                            <?php if ($show_author && !empty($post_rec['author'])): ?>
                                <span> · by <?php echo Djebel_App_HTML::encodeEntities($post_rec['author']); ?></span>
                            <?php endif; ?>

                            <?php if ($show_category && !empty($post_rec['category'])): ?>
                                <span> · <?php echo Djebel_App_HTML::encodeEntities($post_rec['category']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($show_summary && !empty($post_rec['summary'])): ?>
                        <div class="djebel-plugin-static-content-post-summary">
                            <?php echo Djebel_App_HTML::encodeEntities($post_rec['summary']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($show_tags && !empty($post_rec['tags'])): ?>
                        <div class="djebel-plugin-static-content-post-tags">
                            <?php foreach ($post_rec['tags'] as $tag): ?>
                                <span class="djebel-plugin-static-content-tag"><?php echo Djebel_App_HTML::encodeEntities($tag); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>

            <?php if ($total_pages > 1): ?>
                <?php
                $current_url = $req_obj->getRequestUri();
                $prev_url = Dj_App_Request::addQueryParam($this->request_param_key . '[page]', $current_page - 1, $current_url);
                $next_url = Dj_App_Request::addQueryParam($this->request_param_key . '[page]', $current_page + 1, $current_url);
                ?>
                <div class="djebel-plugin-static-content-pagination">
                    <?php if ($current_page > 1): ?>
                        <span class="djebel-plugin-static-content-pagination-prev">
                            <a href="<?php echo Djebel_App_HTML::encodeEntities($prev_url); ?>">← Previous</a>
                        </span>
                    <?php endif; ?>

                    <?php if ($current_page && $total_pages): ?>
                        <span class="djebel-plugin-static-content-pagination-info">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>
                    <?php endif; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <span class="djebel-plugin-static-content-pagination-next">
                            <a href="<?php echo Djebel_App_HTML::encodeEntities($next_url); ?>">Next →</a>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $html = ob_get_clean();
        $ctx = ['content_data' => $content_data, 'params' => $params];
        $html = Dj_App_Hooks::applyFilter('app.plugin.static_content.render_content', $html, $ctx);

        return $html;
    }

    public function getContentData($params = [])
    {
        // Support both content_id and section_id (section_id is an alias)
        if (!empty($params['content_id'])) {
            $content_id = $params['content_id'];
        } elseif (!empty($params['section_id'])) {
            $content_id = $params['section_id'];
        } else {
            $content_id = 'default';
        }
        $content_id = Dj_App_String_Util::formatSlug($content_id); // Sanitize and format
        $cache_key = $this->plugin_id . '_' . $content_id;
        $cache_params = ['plugin' => $this->plugin_id, 'ttl' => 8 * 60 * 60];

        $options_obj = Dj_App_Options::getInstance();

        // Check per-collection cache setting first, fall back to global
        $cache_setting = $options_obj->get("plugins.djebel-static-content.{$content_id}.cache");

        if (empty($cache_setting)) {
            $cache_setting = $options_obj->get('plugins.djebel-static-content.cache');
        }

        // Default to enabled if not explicitly disabled
        $cache_content = !Dj_App_Util::isDisabled($cache_setting);

        $cached_data = $cache_content ? Dj_App_Cache::get($cache_key, $cache_params) : false;

        if (!empty($cached_data)) {
            return $cached_data;
        }

        $content_data = $this->generateContentData($params);

        if ($cache_content && !empty($content_data)) {
            Dj_App_Cache::set($cache_key, $content_data, $cache_params);
        }

        return $content_data;
    }

    public function clearCache($content_id = null)
    {
        $cache_params = ['plugin' => $this->plugin_id];

        if ($content_id) {
            // Clear specific collection
            $content_id = Dj_App_String_Util::formatSlug($content_id);
            $cache_key = $this->plugin_id . '_' . $content_id;
            $result = Dj_App_Cache::remove($cache_key, $cache_params);
        } else {
            // Clear all cache files for this plugin
            $result = Dj_App_Cache::removeAll($cache_params);
        }

        return $result;
    }

    private function generateContentData($params = [])
    {
        $content_data = [];

        // Support both content_id and section_id (section_id is an alias)
        if (!empty($params['content_id'])) {
            $content_id = $params['content_id'];
        } elseif (!empty($params['section_id'])) {
            $content_id = $params['section_id'];
        } else {
            $content_id = 'default';
        }

        $content_id = Dj_App_String_Util::formatSlug($content_id); // Sanitize and format
        $scan_dirs = $this->getScanDirectories($params);

        foreach ($scan_dirs as $scan_dir) {
            if (!is_dir($scan_dir)) {
                continue;
            }

            // Normalize scan_dir once per directory (optimization - avoid repeated calls in loop)
            $scan_dir_normalized = Dj_App_File_Util::normalizePath($scan_dir);

            $md_files = $this->scanMarkdownFiles($scan_dir);

            foreach ($md_files as $file) {
                $content_rec = $this->loadPostFromMarkdown(['file' => $file]);

                if (empty($content_rec)) {
                    continue;
                }

                $hash_id = $content_rec['hash_id'];
                $content_prefix = !empty($params['content_prefix']) ? $params['content_prefix'] : '';
                $include_content_prefix_param = isset($params['include_content_prefix']) ? $params['include_content_prefix'] : '';
                $include_content_prefix = !Dj_App_Util::isDisabled($include_content_prefix_param);

                // Optional: Append file's relative directory to content_prefix in URL (content_prefix_dir=1)
                // This allows preserving directory structure from markdown files in the final URLs
                // Example with content_prefix="docs/latest":
                //   File at: docs/api/v2/auth.md
                //   Without content_prefix_dir: /web_path/docs/latest/auth-abc123
                //   With content_prefix_dir=1:  /web_path/docs/latest/api/v2/auth-abc123
                $rel_dir = '';
                $content_prefix_dir_param = isset($params['content_prefix_dir']) ? $params['content_prefix_dir'] : '';
                $content_prefix_dir = Dj_App_Util::isEnabled($content_prefix_dir_param);

                if ($content_prefix_dir) {
                    $file_dir = dirname($file);
                    $file_dir_normalized = Dj_App_File_Util::normalizePath($file_dir);

                    if (strpos($file_dir_normalized, $scan_dir_normalized) === 0) {
                        $rel_dir = substr($file_dir_normalized, strlen($scan_dir_normalized));
                        $rel_dir = Dj_App_Util::removeSlash($rel_dir, Dj_App_Util::FLAG_BOTH);
                    }
                }

                // Copy params and extend with URL generation data
                $url_params = $params;
                $url_params['slug'] = $content_rec['slug'];
                $url_params['hash_id'] = $hash_id;
                $url_params['content_id'] = $content_id;
                $url_params['content_prefix'] = $content_prefix;
                $url_params['include_content_prefix'] = $include_content_prefix;
                $url_params['rel_dir'] = $rel_dir;

                // Filter URL params before generation
                $ctx = ['content_rec' => $content_rec, 'scan_dir' => $scan_dir];
                $url_params = Dj_App_Hooks::applyFilter('app.plugin.static_content.url_params', $url_params, $ctx);

                $content_rec['url'] = $this->generateContentUrl($url_params);

                $content_rec['content_id'] = $content_id; // Store content_id in record
                $content_data[$hash_id] = $content_rec;
            }
        }

        if (empty($content_data)) {
            return $content_data;
        }

        $options_obj = Dj_App_Options::getInstance();

        // Check per-collection sort setting, fall back to global
        $config_key = "plugins.djebel-static-content.{$content_id}.sort_by";
        $sort_by = $options_obj->get($config_key);

        if ($sort_by === null) {
            $sort_by = $options_obj->get('plugins.djebel-static-content.sort_by');
        }

        $ctx = ['content_id' => $content_id, 'params' => $params];
        $sort_by = Dj_App_Hooks::applyFilter('app.plugin.static_content.sort_by', $sort_by, $ctx);
        $this->sort_by = $sort_by;

        // Allow customization of the sort callback
        $sort_callback = Dj_App_Hooks::applyFilter('app.plugin.static_content.sort_callback', [$this, 'sortPosts'], $ctx);

        // Use uasort to maintain hash_id keys for fast lookups
        uasort($content_data, $sort_callback);

        $content_data = Dj_App_Hooks::applyFilter('app.plugin.static_content.data', $content_data, $ctx);

        return $content_data;
    }

    private function getScanDirectories($params = [])
    {
        $default_dir = $this->getDataDirectory($params);
        $scan_dirs = [$default_dir];

        $options_obj = Dj_App_Options::getInstance();
        $config_dirs = $options_obj->get('plugins.djebel-static-content.scan_dirs');

        if (!empty($config_dirs)) {
            if (is_string($config_dirs)) {
                $config_dirs = explode(',', $config_dirs);
                $config_dirs = array_map('trim', $config_dirs);
            }

            if (is_array($config_dirs)) {
                $scan_dirs = array_merge($scan_dirs, $config_dirs);
            }
        }

        $scan_dirs = Dj_App_Hooks::applyFilter('app.plugin.static_content.scan_dirs', $scan_dirs);
        $scan_dirs = array_unique($scan_dirs);

        return $scan_dirs;
    }

    private function scanMarkdownFiles($scan_dir)
    {
        $content_files = [];

        if (!is_dir($scan_dir)) {
            return $content_files;
        }

        $directory = new RecursiveDirectoryIterator($scan_dir, RecursiveDirectoryIterator::SKIP_DOTS);

        // Load only .md files recursively
        $filtered = new RecursiveCallbackFilterIterator($directory, [$this, 'shouldIncludeFile']);
        $iterator = new RecursiveIteratorIterator($filtered);

        foreach ($iterator as $file) {
            $content_files[] = $file->getPathname();
        }

        return $content_files;
    }

    /**
     * Filter callback to determine if a file should be included in scan results
     * IMPORTANT: Must accept directories to allow recursion
     * Performance: Avoids isDir() filesystem calls by checking filename pattern
     * @param SplFileInfo $file_obj File object from directory iterator
     * @return bool True to include file, false to exclude
     */
    public function shouldIncludeFile($file_obj)
    {
        $ctx = ['file_obj' => $file_obj];
        $filename = $file_obj->getFilename();

        // Early exit: skip hidden files/dirs (starts with dot)
        $first_char = Dj_App_String_Util::getFirstChar($filename);

        if ($first_char == '.') {
            return false;
        }

        $ext = $file_obj->getExtension();

        // No extension - verify it's a directory before accepting for recursion
        if (empty($ext)) {
            // Only accept directories for recursion
            if ($file_obj->isDir()) {
                $local_ctx = $ctx;
                $local_ctx['is_dir'] = true;
                $should_include = Dj_App_Hooks::applyFilter('app.plugin.static_content.should_include_file', true, $local_ctx);
                return $should_include;
            }

            // No extension but not a directory - reject
            return false;
        }

        // Has extension - check if it's .md
        $should_include = $ext == 'md';
        $should_include = Dj_App_Hooks::applyFilter('app.plugin.static_content.should_include_file', $should_include, $ctx);

        return $should_include;
    }

    private function getDataDirectory($params = [])
    {
        // Support both content_id and section_id (section_id is an alias)
        if (!empty($params['content_id'])) {
            $content_id = $params['content_id'];
        } elseif (!empty($params['section_id'])) {
            $content_id = $params['section_id'];
        } else {
            $content_id = 'default';
        }
        $content_id = Dj_App_String_Util::formatSlug($content_id); // Sanitize and format

        // Default to public directory
        $is_public = true;

        // Check if explicitly set to private
        if (isset($params['public']) && empty($params['public'])) {
            $is_public = false;
        }

        // Allow per-collection config override
        $options_obj = Dj_App_Options::getInstance();
        $config_key = "plugins.djebel-static-content.{$content_id}.public";
        $config_public = $options_obj->isEnabled($config_key);

        if ($config_public) {
            $is_public = (bool) $config_public;
        }

        if ($is_public) {
            // Public: dj-content/data/plugins/{plugin_id}/{content_id}/
            $base_dir = Dj_App_Util::getContentDataDir(['plugin' => $this->plugin_id]);
            $data_dir = $base_dir . '/' . $content_id;
        } else {
            // Private: .ht_djebel/data/plugins/{plugin_id}/{content_id}/
            $base_dir = Dj_App_Util::getCorePrivateDataDir(['plugin' => $this->plugin_id]);
            $data_dir = $base_dir . '/' . $content_id;
        }

        return $data_dir;
    }

    private function loadPostFromMarkdown($params)
    {
        $file = $params['file'];
        $full = !empty($params['full']);

        if (!file_exists($file)) {
            return [];
        }

        $ctx = [
            'file' => $file,
            'full' => $full,
        ];

        $parse_res = Dj_App_Hooks::applyFilter('app.plugins.markdown.parse_front_matter', '', $ctx);

        if (!is_object($parse_res) || $parse_res->isError()) {
            return [];
        }

        $meta = $parse_res->meta;
        $content = $parse_res->content;

        $status = empty($meta['status']) ? self::STATUS_PUBLISHED : $meta['status'];

        if ($status === self::STATUS_DRAFT) {
            return [];
        }

        $statuses = $this->getStatuses();

        if (!in_array($status, $statuses)) {
            $status = self::STATUS_PUBLISHED;
        }

        if (!empty($meta['publish_date'])) {
            $publish_timestamp = Dj_App_Util::strtotime($meta['publish_date']);

            if ($publish_timestamp && $publish_timestamp > Dj_App_Util::time()) {
                return [];
            }
        }

        $ctx['meta'] = $meta;
        $html_content = '';

        if ($full) {
            $html_content = Dj_App_Hooks::applyFilter('app.plugins.markdown.convert_markdown', $content, $ctx);
            $html_content = empty($html_content) ? $content : $html_content;
        }

        // Check for hash_id first, then fallback to id
        $hash_id = !empty($meta['hash_id']) ? $meta['hash_id'] : '';

        if (empty($hash_id)) {
            $hash_id = !empty($meta['id']) ? $meta['id'] : '';
        }

        if (empty($hash_id)) {
            $hash_id = $this->parseHashId($file);
        }

        $title = $meta['title'];

        if (empty($meta['slug'])) {
            $slug = basename($file, '.md');
            $slug = preg_replace('#^[\d\-_]+#', '', $slug);
            $slug = Dj_App_String_Util::formatSlug($slug);
        } else {
            $slug = $meta['slug'];
        }

        $slug = Dj_App_Hooks::applyFilter('app.plugin.static_content.post_slug', $slug, $ctx);

        $result = [
            'hash_id' => $hash_id,
            'title' => $title,
            'slug' => $slug,
            'content' => $html_content,
            'summary' => $meta['summary'],
            'creation_date' => $meta['creation_date'],
            'last_modified' => $meta['last_modified'],
            'publish_date' => $meta['publish_date'],
            'sort_order' => $meta['sort_order'],
            'category' => $meta['category'],
            'tags' => $meta['tags'],
            'author' => $meta['author'],
            'status' => $status,
            'file' => $file,
        ];

        return $result;
    }

    /**
     * Generate content URL from content data
     * @param array $params
     * @return string
     */
    public function generateContentUrl($params)
    {
        // Make local copy and filter
        $data = $params;
        $ctx = ['params' => $params];
        $data = Dj_App_Hooks::applyFilter('app.plugin.static_content.generate_content_url_data', $data, $ctx);

        $req_obj = Dj_App_Request::getInstance();

        // Build slug with hash_id
        $slug_parts = [$data['slug']];

        if (!empty($data['hash_id'])) {
            $hash_id = $data['hash_id'];
            $pos = strpos($data['slug'], $hash_id);

            // Only add hash_id if it's not already in slug with proper separator (- or _)
            if ($pos === false || $pos === 0) {
                $slug_parts[] = $hash_id;
            } elseif ($pos > 0) {
                $sep = $data['slug'][$pos - 1];

                if ($sep !== '-' && $sep !== '_') {
                    $slug_parts[] = $hash_id;
                }
            }
        }

        $full_slug = implode('-', $slug_parts);
        $full_slug = Dj_App_String_Util::formatSlug($full_slug);

        // Build URL parts array
        $url_parts = [];
        $url_parts[] = $req_obj->getWebPath();

        // Check if content_prefix should be included
        $include_content_prefix = !empty($data['include_content_prefix']);

        if ($include_content_prefix) {
            // Get content_prefix (shortcode > settings > content_id default)
            $content_prefix = !empty($data['content_prefix']) ? $data['content_prefix'] : '';

            if (empty($content_prefix)) {
                if (!empty($data['content_id'])) {
                    $options_obj = Dj_App_Options::getInstance();
                    $content_id = $data['content_id'];
                    $config_key = "plugins.djebel-static-content.{$content_id}.content_prefix";
                    $content_prefix = $options_obj->get($config_key);

                    if (empty($content_prefix)) {
                        $content_prefix = $content_id;
                    }
                }
            }

            if (!empty($content_prefix)) {
                $url_parts[] = $content_prefix;
            }
        }

        // Add relative directory if provided
        if (!empty($data['rel_dir'])) {
            $url_parts[] = $data['rel_dir'];
        }

        $url_parts[] = $full_slug;

        // Filter hook for URL parts customization
        $ctx = ['data' => $data];
        $url_parts = Dj_App_Hooks::applyFilter('app.plugin.static_content.url_parts', $url_parts, $ctx);

        // Join and normalize path
        $content_url = implode('/', $url_parts);
        $content_url = Dj_App_File_Util::normalizePath($content_url);

        // Filter hook for final URL customization
        $content_url = Dj_App_Hooks::applyFilter('app.plugin.static_content.content_url', $content_url, $ctx);

        return $content_url;
    }

    /**
     * Parse hash ID from string (filename or URL)
     * Extracts 10-12 character alphanumeric hash from end of string
     * Get the last 15 characters from URL for hash detection
     * This is fast and works regardless of how many segments we have
     * @param string $str String to parse, defaults to current request URL if empty
     * @return string
     */
    public function parseHashId($str = '')
    {
        if (empty($str)) {
            $req_obj = Dj_App_Request::getInstance();
            $str = $req_obj->getCleanRequestUrl();
        }

        if (empty($str) || $str == '/') {
            return '';
        }

        // This is fast and works regardless of how many segments we have
        $str = basename($str);
        $str = str_replace(['.md', '.php'], '', $str);
        // Get the last 15 characters for hash detection
        $str = substr($str, -18);

        // Quick check: must contain a dash (hash separator)
        if (strpos($str, '-') === false) {
            return '';
        }

        if (!Dj_App_String_Util::isAlphaNumericExt($str)) {
            return '';
        }

        $str = strtolower($str);

        if (preg_match('#[\-\_]([a-z\d]{10,15})$#i', $str, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Adds page file candidates for content posts
     * Provides multiple fallback options for content post templates
     * Example: /blog/getting-started-abc123def456 or /docs/latest/intro-abc123def456
     * Adds candidates like:
     *   1. /blog.php or /docs/latest.php (parent directory as PHP file - handles multi-lingual setups)
     *   2. /blog/blog.php or /docs/latest/latest.php (configured template in subdirectory)
     * @param array $page_file_candidates Initial candidate files from theme
     * @param array $ctx Context from theme (pages_dir, theme_dir, page, full_page)
     * @return array Modified candidates array with content templates prepended
     */
    public function addPageFileCandidates($page_file_candidates, $ctx = [])
    {
        // Early exit: check if we have required data
        if (empty($page_file_candidates)) {
            return $page_file_candidates;
        }

        $first_candidate = reset($page_file_candidates);
        $parent_dir_file = dirname($first_candidate);
        $parent_dir_file = Dj_App_Util::removeSlash($parent_dir_file);

        // Check template_file first - highest priority if explicitly provided
        // The template_file is passed internally from shortcode (not via request for security)
        $content_template_file = Dj_App_Util::data('djebel_static_content_template_file');

        if (!empty($content_template_file)) {
            $new_candidate = $parent_dir_file . '/' . $content_template_file;
            array_unshift($page_file_candidates, $new_candidate);
        }

        // Check url_contains configuration for template file matching
        // This allows admins to configure templates based on URL patterns in app.ini
        // Timing: This runs early during theme routing, before shortcode rendering
        $options_obj = Dj_App_Options::getInstance();
        $url_contains = $options_obj->get('plugins.djebel-static-content.url_contains', []);

        if (!empty($url_contains) && is_array($url_contains)) {
            // Get relative web path (URL after web path) for pattern matching
            $req_obj = Dj_App_Request::getInstance();
            $segments_path = $req_obj->getRelativeWebPath();

            // Match segments path against url_contains patterns
            // Using !== false to match pattern anywhere in URL (supports multi-lingual: /en/docs)
            foreach ($url_contains as $pattern => $config_data) {
                if (empty($config_data)) {
                    continue;
                }

                if (strpos($segments_path, $pattern) === false) {
                    continue;
                }

                // Parse query string format: "template_file=docs/latest.php&other_param=value"
                $parsed_data = [];
                parse_str($config_data, $parsed_data);

                if (!empty($parsed_data['template_file'])) {
                    $template_file = $parsed_data['template_file'];
                    $new_candidate = $parent_dir_file . '/' . $template_file;
                    array_unshift($page_file_candidates, $new_candidate);
                }

                break;
            }
        }

        // Try to extract hash from filename (parseHashId handles validation)
        $hash_id = $this->parseHashId($first_candidate);

        if (empty($hash_id)) {
            return $page_file_candidates;
        }

        // Inject hash_id into plugin params for renderPost method
        $req_obj = Dj_App_Request::getInstance();
        $plugin_params = $req_obj->get($this->request_param_key, []);
        $plugin_params['hash_id'] = $hash_id;
        $req_obj->set($this->request_param_key, $plugin_params);

        // Fallback: Parent directory as PHP file (handles multi-lingual setups)
        // The way request is parsed, the theme tries to map it to local file.
        // Normally it's ok to use pages_dir ... but if we have multi-lingual setup
        // we need to go 1 level up.
        $page_file_candidates[] = $parent_dir_file . '.php';

        return $page_file_candidates;
    }

    /**
     * Sort posts by configured field
     * @param array $a First post
     * @param array $b Second post
     * @return int Comparison result
     */
    public function sortPosts($a, $b)
    {
        $field = $this->sort_by;
        $val_a = false;
        $val_b = false;

        if ($field === 'file') {
            $val_a = isset($a['file']) ? basename($a['file']) : false;
            $val_b = isset($b['file']) ? basename($b['file']) : false;
        } elseif ($field === 'creation_date') {
            $val_a = isset($a['creation_date']) ? Dj_App_Util::strtotime($a['creation_date']) : false;
            $val_b = isset($b['creation_date']) ? Dj_App_Util::strtotime($b['creation_date']) : false;
        } elseif ($field === 'last_modified') {
            $val_a = isset($a['last_modified']) ? Dj_App_Util::strtotime($a['last_modified']) : false;
            $val_b = isset($b['last_modified']) ? Dj_App_Util::strtotime($b['last_modified']) : false;
        } elseif ($field === 'publish_date') {
            $val_a = isset($a['publish_date']) ? Dj_App_Util::strtotime($a['publish_date']) : false;
            $val_b = isset($b['publish_date']) ? Dj_App_Util::strtotime($b['publish_date']) : false;
        } elseif ($field === 'title') {
            $val_a = isset($a['title']) ? $a['title'] : false;
            $val_b = isset($b['title']) ? $b['title'] : false;
        } elseif ($field === 'sort_order') {
            $val_a = isset($a['sort_order']) ? $a['sort_order'] : false;
            $val_b = isset($b['sort_order']) ? $b['sort_order'] : false;
        }

        if ($val_a && !$val_b) {
            return -1;
        }

        if (!$val_a && $val_b) {
            return 1;
        }

        if ($val_a && $val_b) {
            if (is_numeric($val_a) && is_numeric($val_b)) {
                return $val_a - $val_b;
            } else {
                return strcasecmp($val_a, $val_b);
            }
        }

        return strcasecmp($a['title'], $b['title']);
    }

    /**
     * Singleton pattern i.e. we have only one instance of this obj
     * @staticvar static $instance
     * @return static
     */
    public static function getInstance() {
        static $instance = null;

        // This will make the calling class to be instantiated.
        // no need each sub class to define this method.
        if (is_null($instance)) {
            $instance = new static();
        }

        return $instance;
    }
}
