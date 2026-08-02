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

    public const DEFAULT_RECORDS_PER_PAGE = 25;

    // Content linking constants for max performance
    public const LINK_PREFIX = '(@dj:';
    public const HASH_MIN_LEN = 8;
    public const HASH_MAX_LEN = 15;
    public const BRACKET_BACKTRACK_LIMIT = 100;

    // Hash ID URL marker: -dj-HASH (e.g., getting-started-dj-abc123def456)
    public const HASH_MARKER = '-dj-';

    // Configuration keys
    public const CONFIG_USE_CONTENT_SLUGS = 'use_content_slugs';

    private $plugin_id = 'djebel-static-content';
    private $sort_by = 'publish_date';
    private $check_extensions = [ 'md', 'html', 'php', ];
    private $request_param_key = 'djebel_plugin_static_content_data';
    private $site_content_dir = '';
    private $content_extensions = [];

    private $default_data_fields = [
        'title' => '',
        'meta_title' => '',
        'meta_keywords' => '',
        'meta_description' => '',
        'author' => '',
        'category' => '',
        'hash_id' => '',
        'slug' => '',
        'tags' => [],
        'summary' => '',
        'publish_date' => '',
        'creation_date' => '',
        'last_modified' => '',
    ];

    public function init()
    {
        $shortcode_obj = Dj_App_Shortcode::getInstance();
        $shortcode_obj->addShortcode('djebel_static_content', [$this, 'renderContent']);
        $shortcode_obj->addShortcode('djebel_static_content_post', [$this, 'renderSingleContent']);

        // Hook into theme's page file candidates to add content template options
        Dj_App_Hooks::addFilter('app.themes.current_theme_page_file_candidates', [$this, 'addPageFileCandidates']);

        // Hook into markdown pre-processing for (@dj:hash_id) content links
        Dj_App_Hooks::addFilter('app.plugins.markdown.pre_process_content', [$this, 'processContentLinks']);

        // Load site content early - stores content in Page object for theme to render
        $this->loadSiteContent();
    }

    /**
     * Load site content early into Page object
     * Called during init, before theme template lookup
     * Content stored in Page object, theme renders it via getContent()
     *
     * @return Dj_App_Result
     */
    public function loadSiteContent()
    {
        $res_obj = new Dj_App_Result();
        $options_obj = Dj_App_Options::getInstance();

        // Cheapest check first - is feature disabled?
        $is_disabled = $options_obj->isDisabled('plugins.djebel-static-content.site_content_enabled');

        if ($is_disabled) {
            $res_obj->msg = 'Site content feature is disabled';
            return $res_obj;
        }

        $page_obj = Dj_App_Page::getInstance();
        $full_page = $page_obj->get('full_page');

        // Find content file - handles home page internally
        $find_result = $this->findContentFile($full_page);

        if (empty($find_result)) {
            // No site_content file - try to detect hash_id for blog/collection URLs
            $hash_id = $this->parseHashId();

            if (!empty($hash_id)) {
                // Infer content_id from segment1 (e.g., /blog/post-dj-hash → content_id='blog')
                $req_obj = Dj_App_Request::getInstance();
                $content_id = empty($req_obj->segment1) ? 'default' : $req_obj->segment1;

                // Store hash_id in request for renderSingleContent to retrieve
                $plugin_params = [ 'hash_id' => $hash_id, ];
                $req_obj->set($this->request_param_key, $plugin_params);

                $render_params = [ 'content_id' => $content_id, ];
                $rendered = $this->renderSingleContent($render_params);

                if (!empty($rendered) && (strpos($rendered, '<!--') !== 0)) {
                    $page_obj->setContent($rendered);
                    $res_obj->status(true);

                    return $res_obj;
                }
            }

            $res_obj->msg = 'No content file found';
            return $res_obj;
        }

        $content_file = $find_result['file'];
        $content_ext = $find_result['ext'];

        // Hook for caching plugin to handle HTTP cache headers (ETag, 304)
        $cache_ctx = [
            'file' => $content_file,
        ];

        Dj_App_Hooks::doAction('app.plugin.static_content.pre_load_content', $cache_ctx);

        // Load and process content - pass ext to avoid recalculating
        $load_params = [
            'file' => $content_file,
            'ext' => $content_ext,
        ];

        $content = $this->loadContentFile($load_params);

        if (empty($content)) {
            $res_obj->msg = 'Content file is empty';
            return $res_obj;
        }

        // Store in Page object with metadata
        $meta = [
            'file' => $content_file,
            'path' => $full_page,
            'ext' => $content_ext,
        ];

        $page_obj->setContent($content, $meta);

        $res_obj->status(true);
        $res_obj->data($meta);

        return $res_obj;
    }

    /**
     * Find content file matching the given path
     * Priority: {path}.{ext} > {path}/index.{ext}
     * Direct file checked first (most common case)
     *
     * CONSISTENCY: Always return same type (array).
     * Empty array on failure, populated array on success.
     *
     * @param string $page_slug URL slug to match
     * @return array Array with 'file' and 'ext' keys if found, empty array otherwise
     */
    public function findContentFile($page_slug)
    {
        // Home page: empty or / -> check for 'home' file
        if (empty($page_slug) || $page_slug === '/') {
            $page_slug = 'home';
        }

        // Get site_content directory (cached in property)
        $site_content_dir = $this->getSiteContentDir();

        if (empty($site_content_dir)) {
            return [];
        }

        // Cheap check: skip if site_content dir doesn't exist
        if (!is_dir($site_content_dir)) {
            return [];
        }

        // Get file extensions to check
        $extensions = $this->getContentExtensions();

        // Check direct file first (most common case - about.md)
        foreach ($extensions as $ext) {
            $candidate_file = $site_content_dir . '/' . $page_slug . '.' . $ext;

            if (file_exists($candidate_file)) {
                $result = [
                    'file' => $candidate_file,
                    'ext' => $ext,
                ];

                return $result;
            }
        }

        // Home page only checks home.* files, no directory fallback
        if ($page_slug === 'home') {
            return [];
        }

        // Check index files only if directory exists (fallback - about/index.md)
        $page_dir = $site_content_dir . '/' . $page_slug;

        if (is_dir($page_dir)) {
            foreach ($extensions as $ext) {
                $candidate_file = $page_dir . '/index.' . $ext;

                if (file_exists($candidate_file)) {
                    $result = [
                        'file' => $candidate_file,
                        'ext' => $ext,
                    ];

                    return $result;
                }
            }
        }

        return [];
    }

    /**
     * Get site content directory path (cached)
     *
     * @return string Directory path, empty if doesn't exist
     */
    private function getSiteContentDir()
    {
        // Return cached value if already computed
        if (!empty($this->site_content_dir)) {
            return $this->site_content_dir;
        }

        $options_obj = Dj_App_Options::getInstance();
        $site_content_dir_name = $options_obj->get(
            'plugins.djebel-static-content.site_content_dir',
            'site_content'
        );

        $data_dir_params = [
            'plugin' => $this->plugin_id,
        ];

        $site_content_dir = Dj_App_Util::getContentDataDir($data_dir_params) . '/' . $site_content_dir_name;

        // Allow plugins (e.g., djebel-lang) to modify site_content_dir
        // Filter applied BEFORE is_dir check (lang plugin appends /en)
        $site_content_dir = Dj_App_Hooks::applyFilter('app.plugin.static_content.site_content_dir', $site_content_dir);

        // Only cache if final directory exists
        if (!is_dir($site_content_dir)) {
            return '';
        }

        $this->site_content_dir = $site_content_dir;

        return $this->site_content_dir;
    }

    /**
     * Load and process content file
     *
     * @param array $params {
     *     @type string $file Path to content file
     *     @type string $ext File extension (optional, avoids recalculating)
     * }
     * @return string Processed content, empty on failure
     */
    public function loadContentFile($params = [])
    {
        $file = empty($params['file']) ? '' : $params['file'];

        if (empty($file)) {
            return '';
        }

        // Security: Use realpath() to get canonical path
        // - Checks if file exists (returns false if not)
        // - Resolves symlinks to actual location (prevents symlink attacks)
        // - Normalizes ../ sequences (prevents directory traversal)
        $real_file = realpath($file);

        if (empty($real_file)) {
            return '';
        }

        // Security: Verify file is within allowed directories
        // Must use realpath() on allowed dirs too - compare canonical paths only
        // This ensures symlinks in either path can't bypass the security check
        // Early return pattern: check most common dir first, skip second check if found
        $is_allowed = false;
        $site_content_dir = $this->getSiteContentDir();

        if (!empty($site_content_dir)) {
            $real_site_content_dir = realpath($site_content_dir);

            if (!empty($real_site_content_dir) && (strpos($real_file, $real_site_content_dir) === 0)) {
                $is_allowed = true;
            }
        }

        // Only check data_dir if not already allowed (skip realpath call)
        if (!$is_allowed) {
            $data_dir = Dj_App_Util::getContentDataDir([ 'plugin' => $this->plugin_id, ]);

            if (!empty($data_dir)) {
                $real_data_dir = realpath($data_dir);

                if (!empty($real_data_dir) && (strpos($real_file, $real_data_dir) === 0)) {
                    $is_allowed = true;
                }
            }
        }

        if (!$is_allowed) {
            return '';
        }

        // Use canonical path for all subsequent operations
        $file = $real_file;

        // Use passed extension or calculate if not provided
        if (!empty($params['ext'])) {
            $ext = $params['ext'];
        } else {
            $ext = Dj_App_File_Util::getExt($file);
        }

        // Load based on file type
        $content = '';

        if ($ext === 'php') {
            ob_start();
            include $file;
            $content = ob_get_clean();
        } elseif ($ext === 'md') {
            // Markdown: parse frontmatter and convert to HTML
            $ctx = ['file' => $file, 'full' => 1, ];
            $parse_res = Dj_App_Hooks::applyFilter('app.plugins.markdown.parse_front_matter', '', $ctx);

            if (is_object($parse_res) && $parse_res->isSuccess()) {
                $content = $parse_res->content;
                $ctx['meta'] = $parse_res->meta;

                // Convert markdown to HTML
                $content = Dj_App_Hooks::applyFilter('app.plugins.markdown.convert_markdown', $content, $ctx);
            }
        } else {
            $read_res = Dj_App_File_Util::read($file);

            if ($read_res->isSuccess()) {
                $content = $read_res->output;
            }
        }

        $content = Dj_App_String_Util::trim($content);

        $ctx = [
            'file' => $file,
            'ext' => $ext,
        ];

        // Additional content filters
        $content = Dj_App_Hooks::applyFilter('app.page.content', $content, $ctx);

        return $content;
    }

    /**
     * Get content file extensions to check (cached)
     *
     * @return array File extensions
     */
    private function getContentExtensions()
    {
        // Return cached value if already computed
        if (!empty($this->content_extensions)) {
            return $this->content_extensions;
        }

        $options_obj = Dj_App_Options::getInstance();
        $extensions = [];
        $extensions_config = $options_obj->get('plugins.djebel-static-content.content.file_ext');

        if (!empty($extensions_config)) {
            if (is_string($extensions_config)) {
                $extensions = explode(',', $extensions_config);
            } elseif (is_array($extensions_config)) {
                $extensions = $extensions_config;
            }
        }

        $extensions = empty($extensions) ? $this->check_extensions : (array) $extensions;

        // Allow plugins to modify extensions
        $extensions = Dj_App_Hooks::applyFilter('app.plugin.static_content.content_extensions', $extensions);

        // Clean up: trim whitespace and leading dots (user might put .md instead of md)
        $extensions = Dj_App_String_Util::trim($extensions, '.');
        $extensions = array_filter($extensions);
        $extensions = array_unique($extensions);

        $this->content_extensions = $extensions;

        return $this->content_extensions;
    }

    public function getStatuses()
    {
        $statuses = $this->statuses;
        $statuses = Dj_App_Hooks::applyFilter('app.plugin.static_content.statuses', $statuses);

        return $statuses;
    }

    /**
     * Get default fields for page data
     * Allows other plugins to extend the fields via filter
     * @return array
     */
    public function getDefaultDataFields()
    {
        $defaults = $this->default_data_fields;
        $defaults = Dj_App_Hooks::applyFilter('app.plugin.static_content.default_data_fields', $defaults);

        return $defaults;
    }

    /**
     * Get records per page for pagination
     * Checks: params -> options -> default constant
     *
     * @param array $params Optional params with 'results_per_page' key
     * @return int
     */
    public function getResultsPerPage($params = [])
    {
        // Check params first (results_per_page, fall back to per_page for backward compat)
        $per_page_param = empty($params['results_per_page']) ? '' : $params['results_per_page'];

        if (empty($per_page_param)) {
            $per_page_param = empty($params['per_page']) ? '' : $params['per_page'];
        }

        if (!empty($per_page_param)) {
            $per_page = $per_page_param;
            $per_page = (int) $per_page;
            $per_page = Dj_App_Hooks::applyFilter('app.plugin.static_content.results_per_page', $per_page, $params);

            return $per_page;
        }

        // Check options
        $options_obj = Dj_App_Options::getInstance();
        $per_page = $options_obj->get('plugins.djebel-static-content.results_per_page');

        if (empty($per_page)) {
            $per_page = $options_obj->get('plugins.djebel-static-content.per_page');
        }

        $per_page = empty($per_page) ? self::DEFAULT_RECORDS_PER_PAGE : $per_page;
        $per_page = (int) $per_page;
        $per_page = Dj_App_Hooks::applyFilter('app.plugin.static_content.results_per_page', $per_page, $params);

        return $per_page;
    }

    public function renderSingleContent($params = [])
    {
        $req_obj = Dj_App_Request::getInstance();
        $plugin_params = $req_obj->get($this->request_param_key, []);
        $hash_id = empty($plugin_params['hash_id']) ? '' : $plugin_params['hash_id'];

        if (empty($hash_id)) {
            return "<!--\nNo post hash_id provided\n-->";
        }

        // Get content_id from params (support both content_id and section_id)
        if (!empty($params['content_id'])) {
            $content_id = $params['content_id'];
        } elseif (!empty($params['section_id'])) {
            $content_id = $params['section_id'];
        } else {
            $content_id = 'default';
        }

        $content_data = $this->getContentData($params);

        if (empty($content_data[$hash_id])) {
            return "<!--\nPost not found\n-->";
        }

        $post_rec = $content_data[$hash_id];

        // Reload with full content for single post view
        $load_params = [
            'file' => $post_rec['file'],
            'full' => 1,
            'content_id' => $content_id,
        ];

        $post_res_obj = $this->loadPostFromMarkdown($load_params);

        if ($post_res_obj->isError()) {
            return "<!--\nFailed to load post content\n-->";
        }

        $post_rec = $post_res_obj->data();

        // Hook after post loaded - pass last_modified and file for caching
        // Note: fallback from creation_date to last_modified happens in loadPostFromMarkdown()
        $last_modified = empty($post_rec['last_modified']) ? '' : $post_rec['last_modified'];
        $file = empty($post_rec['file']) ? '' : $post_rec['file'];

        $post_load_ctx = [
            'last_modified' => $last_modified,
            'file' => $file,
        ];

        Dj_App_Hooks::doAction('app.plugin.static_content.post_load_post', $post_load_ctx);

        // Publish page data for SEO plugin (maintains separation of concerns)
        // Get default fields (allows other plugins to extend via filter)
        $defaults = $this->getDefaultDataFields();

        // Build page data by merging: defaults -> post record
        $page_data = array_merge($defaults, $post_rec);

        // Special case: meta_title falls back to title if empty
        if (empty($page_data['meta_title']) && !empty($page_data['title'])) {
            $page_data['meta_title'] = $page_data['title'];
        }

        Dj_App_Util::data('djebel_page_data', $page_data);

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
                        <?php if ($show_date && !empty($post_rec['creation_date'])):
                            $date_timestamp = Dj_App_Util::strtotime($post_rec['creation_date']);
                            $formatted_date = date('F j, Y', $date_timestamp);
                            $date_escaped = Djebel_App_HTML::encodeEntities($formatted_date);
                        ?>
                        <span><?php echo $date_escaped; ?></span>
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

        // Auto-detect if this is a single post request by parsing hash_id or slug from URL
        // Note: In slug mode, this returns the slug which serves as hash_id internally
        $hash_id = $this->parseHashId($params);

        if (!empty($hash_id)) {
            // Inject hash_id (or slug in slug mode) into plugin params array
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

        // Hook after content loaded - pass most recent last_modified for caching
        $post_load_ctx = [
            'files' => $content_data,
        ];

        Dj_App_Hooks::doAction('app.plugin.static_content.post_load_listing', $post_load_ctx);

        // @todo move to another method or block for pagination?
        $current_page = empty($plugin_params['page']) ? 1 : $plugin_params['page'];
        $current_page = (int) $current_page;
        $current_page = max(1, $current_page);

        $per_page = $this->getResultsPerPage($params);
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
                            <?php if ($show_date && !empty($post_rec['creation_date'])):
                                $date_timestamp = Dj_App_Util::strtotime($post_rec['creation_date']);
                                $formatted_date = date('F j, Y', $date_timestamp);
                                $date_escaped = Djebel_App_HTML::encodeEntities($formatted_date);
                            ?>
                                <span><?php echo $date_escaped; ?></span>
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
                $current_url = $req_obj->getRequestUrl();
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
        $html = Dj_App_String_Util::trim($html);
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

        $options_obj = Dj_App_Options::getInstance();

        // Check if cache is disabled at per-collection level first
        $cache_disabled = $options_obj->isDisabled("plugins.djebel-static-content.{$content_id}.cache")
                || $options_obj->isDisabled("plugins.djebel-static-content.cache");

        // Request param to bypass cache: ?dj_cache=0
        $req_obj = Dj_App_Request::getInstance();
        $cache_param = $req_obj->get('dj_cache');

        if (Dj_App_Util::isDisabled($cache_param)) {
            $cache_disabled = true;
        }

        $cache_enabled = !$cache_disabled;

        // Check per-collection cache TTL first, fall back to global, then default (4 hours)
        $default_cache_ttl = $options_obj->get('plugins.djebel-static-content.cache_ttl');
        $cache_ttl = $options_obj->get("plugins.djebel-static-content.{$content_id}.cache_ttl", $default_cache_ttl);
        $cache_ttl = empty($cache_ttl) ? 4 * 60 * 60 : $cache_ttl;

        $cache_params = ['plugin' => $this->plugin_id, 'ttl' => (int) $cache_ttl, 'enabled' => $cache_enabled];
        $cached_data = Dj_App_Cache::get($cache_key, $cache_params);

        if (!empty($cached_data)) {
            return $cached_data;
        }

        $content_data = $this->generateContentData($params);

        // Only cache non-empty results to avoid caching failures
        if (!empty($content_data)) {
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

        // Check if this collection uses slug-only mode (no hash_ids in URLs)
        $options_obj = Dj_App_Options::getInstance();
        $use_slugs = $options_obj->isEnabled("plugins.djebel-static-content.{$content_id}." . self::CONFIG_USE_CONTENT_SLUGS);

        // Cheap checks first - calculate params-based values BEFORE disk I/O loops
        $content_prefix = empty($params['content_prefix']) ? '' : $params['content_prefix'];
        $include_content_prefix_param = empty($params['include_content_prefix']) ? '' : $params['include_content_prefix'];
        $include_content_prefix = !Dj_App_Util::isDisabled($include_content_prefix_param);
        $content_prefix_dir_param = empty($params['content_prefix_dir']) ? '' : $params['content_prefix_dir'];
        $content_prefix_dir = Dj_App_Util::isEnabled($content_prefix_dir_param);

        foreach ($scan_dirs as $scan_dir) {
            if (!is_dir($scan_dir)) {
                continue;
            }

            // Normalize scan_dir once per directory (optimization - avoid repeated calls in loop)
            $scan_dir_normalized = Dj_App_File_Util::normalizePath($scan_dir);
            $scan_dir_len = strlen($scan_dir_normalized);

            $md_files = $this->scanMarkdownFiles($scan_dir);

            foreach ($md_files as $file) {
                $content_res_obj = $this->loadPostFromMarkdown([ 'file' => $file, 'content_id' => $content_id, ]);

                if ($content_res_obj->isError()) {
                    continue;
                }

                $content_rec = $content_res_obj->data();
                $hash_id = $content_rec['hash_id'];

                // Optional: Append file's relative directory to content_prefix in URL (content_prefix_dir=1)
                // This allows preserving directory structure from markdown files in the final URLs
                // Example with content_prefix="docs/latest":
                //   File at: docs/api/v2/auth.md
                //   Without content_prefix_dir: /web_path/docs/latest/auth-abc123
                //   With content_prefix_dir=1:  /web_path/docs/latest/api/v2/auth-abc123
                $rel_dir = '';

                if ($content_prefix_dir) {
                    $file_dir = dirname($file);
                    $file_dir_normalized = Dj_App_File_Util::normalizePath($file_dir);

                    if (strpos($file_dir_normalized, $scan_dir_normalized) === 0) {
                        $rel_dir = substr($file_dir_normalized, $scan_dir_len);
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
                $url_params['use_slugs'] = $use_slugs;

                // Filter URL params before generation
                $ctx = ['content_rec' => $content_rec, 'scan_dir' => $scan_dir];
                $url_params = Dj_App_Hooks::applyFilter('app.plugin.static_content.url_params', $url_params, $ctx);

                $content_rec['url'] = $this->generateContentUrl($url_params);
                $content_rec['content_id'] = $content_id; // Store content_id in record

                // Index by hash_id (which is slug in slug mode)
                $content_data[$hash_id] = $content_rec;
            }
        }

        if (empty($content_data)) {
            return $content_data;
        }

        $options_obj = Dj_App_Options::getInstance();

        // Check per-collection sort setting, fall back to global, then default
        $config_key = "plugins.djebel-static-content.{$content_id}.sort_by";
        $sort_by = $options_obj->get($config_key);

        if (empty($sort_by)) {
            $sort_by = $options_obj->get('plugins.djebel-static-content.sort_by');
        }

        // Use default if still empty
        if (empty($sort_by)) {
            $sort_by = $this->sort_by;
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
                $config_dirs = Dj_App_String_Util::trim($config_dirs);
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

        $dir_iterator = new RecursiveDirectoryIterator($scan_dir, RecursiveDirectoryIterator::SKIP_DOTS);

        // Load only .md files recursively
        $filtered_iterator = new RecursiveCallbackFilterIterator($dir_iterator, [$this, 'shouldIncludeFile']);
        $recursive_iterator = new RecursiveIteratorIterator($filtered_iterator);

        foreach ($recursive_iterator as $file) {
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

        // Has extension - check if it's a supported type
        $should_include = in_array($ext, $this->check_extensions);
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

    /**
     * Inject prepend/append content from .config/ directory
     * Loads raw markdown from config files and merges with main content
     * Files checked: .config/prepend_content.md, .config/append_content.md
     *
     * @param array $params {
     *     @type string $content Main post content (raw markdown)
     *     @type string $content_id Collection ID (e.g., 'blog')
     * }
     * @return string Content with prepend/append injected
     */
    private function injectConfigContent($params = [])
    {
        $content = empty($params['content']) ? '' : $params['content'];
        $content_id = empty($params['content_id']) ? 'default' : $params['content_id'];

        // Cheap check: option to disable feature (per-collection or global)
        $options_obj = Dj_App_Options::getInstance();
        $is_disabled = $options_obj->isDisabled("plugins.djebel-static-content.{$content_id}.inject_config_content")
            || $options_obj->isDisabled('plugins.djebel-static-content.inject_config_content');

        if ($is_disabled) {
            return $content;
        }

        // Cache config dir paths per content_id (avoid repeated filesystem checks)
        static $config_dirs = [];

        if (isset($config_dirs[$content_id])) {
            $config_dir = $config_dirs[$content_id];

            // Empty string means we checked before and .config doesn't exist
            if (empty($config_dir)) {
                return $content;
            }
        } else {
            $content_dir = $this->getDataDirectory($params);

            // Cheap check: empty result means no valid directory
            if (empty($content_dir)) {
                $config_dirs[$content_id] = '';
                return $content;
            }

            $config_dir = $content_dir . '/.config';

            // Filesystem check: .config dir must exist
            if (!is_dir($config_dir)) {
                $config_dirs[$content_id] = '';
                return $content;
            }

            // Cache the valid config dir path
            $config_dirs[$content_id] = $config_dir;
        }

        // Load prepend content
        $prepend_file = $config_dir . '/prepend_content.md';
        $prepend_content = $this->loadConfigContentFile($prepend_file);

        // Load append content
        $append_file = $config_dir . '/append_content.md';
        $append_content = $this->loadConfigContentFile($append_file);

        // Merge: prepend + content + append
        if (!empty($prepend_content)) {
            $content = $prepend_content . "\n\n" . $content;
        }

        if (!empty($append_content)) {
            $content = $content . "\n\n---\n\n" . $append_content;
        }

        return $content;
    }

    /**
     * Load raw content from config file (strips frontmatter)
     *
     * @param string $file Path to config file
     * @return string Raw markdown content, empty if file doesn't exist
     */
    private function loadConfigContentFile($file)
    {
        if (empty($file) || !file_exists($file)) {
            return '';
        }

        $ctx = ['file' => $file, 'full' => 1,];
        $parse_res = Dj_App_Hooks::applyFilter('app.plugins.markdown.parse_front_matter', '', $ctx);

        if (!is_object($parse_res) || $parse_res->isError()) {
            return '';
        }

        $content = $parse_res->content;
        $content = Dj_App_String_Util::trim($content);

        return $content;
    }

    /**
     * Load post from markdown file
     * @param array $params
     * @return Dj_App_Result
     */
    private function loadPostFromMarkdown($params)
    {
        $res_obj = new Dj_App_Result();
        $file = $params['file'];
        $full = !empty($params['full']);
        $content_id = empty($params['content_id']) ? 'default' : $params['content_id'];

        if (!file_exists($file)) {
            $res_obj->msg = 'File does not exist';
            return $res_obj;
        }

        $ctx = [
            'file' => $file,
            'full' => $full,
        ];

        $parse_res = Dj_App_Hooks::applyFilter('app.plugins.markdown.parse_front_matter', '', $ctx);

        if (!is_object($parse_res) || $parse_res->isError()) {
            $res_obj->msg = 'Failed to parse markdown front matter';
            return $res_obj;
        }

        $meta = $parse_res->meta;
        $content = $parse_res->content;

        $status = empty($meta['status']) ? self::STATUS_PUBLISHED : $meta['status'];

        if ($status === self::STATUS_DRAFT) {
            $res_obj->msg = 'Post status is draft';
            return $res_obj;
        }

        $statuses = $this->getStatuses();

        if (!in_array($status, $statuses)) {
            $status = self::STATUS_PUBLISHED;
        }

        if (!empty($meta['publish_date'])) {
            $publish_timestamp = Dj_App_Util::strtotime($meta['publish_date']);

            if ($publish_timestamp && $publish_timestamp > Dj_App_Util::time()) {
                $res_obj->msg = 'Post publish date is in the future';
                return $res_obj;
            }
        }

        $ctx['meta'] = $meta;
        $html_content = '';

        if ($full) {
            // Inject prepend/append content from .config/ before markdown conversion
            $inject_params = [
                'content' => $content,
                'content_id' => $content_id,
            ];

            $content = $this->injectConfigContent($inject_params);

            $html_content = Dj_App_Hooks::applyFilter('app.plugins.markdown.convert_markdown', $content, $ctx);
            $html_content = empty($html_content) ? $content : $html_content;
        }

        // Check if collection uses slug mode
        $options_obj = Dj_App_Options::getInstance();
        $use_slugs = $options_obj->isEnabled("plugins.djebel-static-content.{$content_id}." . self::CONFIG_USE_CONTENT_SLUGS);

        // Generate slug
        if (empty($meta['slug'])) {
            $base_name = basename($file);
            $slug = Dj_App_File_Util::removeExt($base_name);

            // In slug mode, use filename as-is; in hash mode, strip leading numbers/dashes
            if (!$use_slugs) {
                $slug = preg_replace('#^[\d\-_]+#', '', $slug);
            }

            $slug = Dj_App_String_Util::formatSlug($slug);
        } else {
            $slug = $meta['slug'];
        }

        $slug = Dj_App_Hooks::applyFilter('app.plugin.static_content.post_slug', $slug, $ctx);

        $title = '';

        if (!empty($meta['title'])) {
            $title = $meta['title'];
        } elseif (!empty($meta['meta_title'])) {
            $title = $meta['meta_title'];
        } else {
            $title = $slug;
            $title = str_replace('-', ' ', $title);
        }

        // In slug mode, treat slug as hash_id; otherwise extract hash from meta/filename
        if ($use_slugs) {
            $hash_id = $slug;
        } else {
            $hash_id = $this->getHash($meta);

            if (empty($hash_id)) {
                $parse_params = [ 'file' => $file, ];
                $hash_id = $this->parseHashId($parse_params);
            }

            if (empty($hash_id)) {
                $hash_id = $slug;
            }
        }

        // Get default fields to ensure all fields are present
        $defaults = $this->getDefaultDataFields();

        // Build override fields that take precedence over defaults and meta
        $override_fields = [
            'hash_id' => $hash_id,
            'title' => $title,
            'slug' => $slug,
            'content' => $html_content,
            'status' => $status,
            'file' => $file,
        ];

        // Build data by merging: defaults -> meta -> override fields
        $data = array_merge($defaults, $meta, $override_fields);

        // Fallback: use creation_date if last_modified is empty
        if (empty($data['last_modified']) && !empty($data['creation_date'])) {
            $data['last_modified'] = $data['creation_date'];
        }

        $res_obj->status(true);
        $res_obj->data($data);

        return $res_obj;
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
        $use_slugs = !empty($data['use_slugs']);

        // Build slug with or without hash_id depending on collection settings
        $slug = $data['slug'];
        $hash_id = empty($data['hash_id']) ? '' : $data['hash_id'];

        // Default to slug as-is
        $full_slug = $slug;

        // Append hash only if: not slug mode, has hash, and doesn't already have it
        // Format: -dj-HASH (e.g., getting-started-dj-abc123def456)
        if (!$use_slugs && !empty($hash_id)) {
            $hash_suffix = self::HASH_MARKER . $hash_id;

            if (strpos($slug, $hash_suffix) === false) {
                $full_slug = $slug . $hash_suffix;
            }
        }

        $full_slug = Dj_App_String_Util::formatSlug($full_slug);

        // Build URL parts array
        $url_parts = [];
        $url_parts[] = $req_obj->getWebPath();

        // Check if content_prefix should be included
        $include_content_prefix = !empty($data['include_content_prefix']);

        if ($include_content_prefix) {
            // Get content_prefix (shortcode > settings > content_id default)
            $content_prefix = empty($data['content_prefix']) ? '' : $data['content_prefix'];

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
     * Extract hash_id from front matter metadata
     *
     * @param array $meta Front matter metadata
     * @return string Hash ID from metadata, empty if not found
     */
    private function getHash($meta = [])
    {
        // Extract hash_id from meta (hash_id > hash > id)
        $hash_id = empty($meta['hash_id']) ? '' : $meta['hash_id'];

        if (empty($hash_id)) {
            $hash_id = empty($meta['hash']) ? '' : $meta['hash'];
        }

        if (empty($hash_id)) {
            $hash_id = empty($meta['id']) ? '' : $meta['id'];
        }

        return $hash_id;
    }

    /**
     * Parse hash_id or slug from URL or file
     *
     * @param array $params Parameters:
     *                      - url: URL to parse (or uses current request)
     *                      - file: File path to parse (alternative to url)
     *                      - content_id: Collection ID (enables slug mode)
     * @return string Hash ID or slug, empty if not found
     */
    public function parseHashId($params = [])
    {
        if (!empty($params['file'])) {
            $url = $params['file'];
        } elseif (!empty($params['url'])) {
            $url = $params['url'];
        } else {
            $req_obj = Dj_App_Request::getInstance();
            $url = $req_obj->getCleanRequestUrl();
        }

        if (empty($url) || $url === '/') {
            return '';
        }

        // Quick check: must contain hash marker
        if (strpos($url, self::HASH_MARKER) === false) {
            return '';
        }

        // Format: -dj-HASH (e.g., getting-started-dj-abc123def456)
        $url_basename = basename($url);
        $url_basename = Dj_App_File_Util::removeExt($url_basename);
        $url_lower = strtolower($url_basename);
        $pattern = '#' . self::HASH_MARKER . '([a-z\d]{10,15})$#i';

        if (!preg_match($pattern, $url_lower, $matches)) {
            return '';
        }

        return $matches[1];
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

        $first_file = reset($page_file_candidates);

        if (empty($first_file)) {
            return $page_file_candidates;
        }

        $parent_dir_file = dirname($first_file);
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
            $rel_url = $req_obj->getRelWebPath();

            // Match segments path against url_contains patterns
            // Using !== false to match pattern anywhere in URL (supports multi-lingual: /en/docs)
            foreach ($url_contains as $pattern => $config_data) {
                if (empty($config_data)) {
                    continue;
                }

                if (strpos($rel_url, $pattern) === false) {
                    continue;
                }

                // Parse query string format: "template_file=docs/latest.php&other_param=value"
                $parsed_data = [];
                parse_str($config_data, $parsed_data);

                if (!empty($parsed_data['template_file'])) {
                    $template_file = $parsed_data['template_file'];

                    // Also add candidate using pages_dir from context (if available)
                    if (!empty($ctx['pages_dir'])) {
                        $pages_dir = Dj_App_Util::removeSlash($ctx['pages_dir']);
                        $pages_dir_candidate = $pages_dir . '/' . $template_file;
                        array_push($page_file_candidates, $pages_dir_candidate);
                    }

                    $new_candidate = $parent_dir_file . '/' . $template_file;
                    array_push($page_file_candidates, $new_candidate);
                }

                break;
            }
        }

        // Try to extract hash_id or slug from file path (supports both modes)
        $hash_id = $this->parseHashId([ 'file' => $first_file, ]);

        if (empty($hash_id)) {
            return $page_file_candidates;
        }

        // Inject hash_id (or slug in slug mode) into plugin params for renderPost method
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
            if (isset($a['file'])) {
                $val_a = basename($a['file']);
            }

            if (isset($b['file'])) {
                $val_b = basename($b['file']);
            }
        } elseif ($field === 'creation_date') {
            if (isset($a['creation_date'])) {
                $val_a = Dj_App_Util::strtotime($a['creation_date']);
            }

            if (isset($b['creation_date'])) {
                $val_b = Dj_App_Util::strtotime($b['creation_date']);
            }
        } elseif ($field === 'last_modified') {
            if (isset($a['last_modified'])) {
                $val_a = Dj_App_Util::strtotime($a['last_modified']);
            }

            if (isset($b['last_modified'])) {
                $val_b = Dj_App_Util::strtotime($b['last_modified']);
            }
        } elseif ($field === 'publish_date') {
            if (isset($a['publish_date'])) {
                $val_a = Dj_App_Util::strtotime($a['publish_date']);
            }

            if (isset($b['publish_date'])) {
                $val_b = Dj_App_Util::strtotime($b['publish_date']);
            }
        } elseif ($field === 'title') {
            if (isset($a['title'])) {
                $val_a = $a['title'];
            }

            if (isset($b['title'])) {
                $val_b = $b['title'];
            }
        } elseif ($field === 'sort_order') {
            if (isset($a['sort_order'])) {
                $val_a = $a['sort_order'];
            }

            if (isset($b['sort_order'])) {
                $val_b = $b['sort_order'];
            }
        }

        if ($val_a && !$val_b) {
            return -1;
        }

        if (!$val_a && $val_b) {
            return 1;
        }

        if ($val_a && $val_b) {
            if (is_numeric($val_a) && is_numeric($val_b)) {
                // For dates (timestamps), sort descending (newest first)
                return $val_b - $val_a;
            } else {
                return strcasecmp($val_a, $val_b);
            }
        }

        return strcasecmp($a['title'], $b['title']);
    }

    /**
     * Process content links in markdown before conversion to HTML
     * Scans for (@dj:hash_id) pattern and resolves to actual URLs
     * Supports multiple syntaxes:
     * - (@dj:hash_id) - bare, auto-title
     * - [](@dj:hash_id) - empty brackets, auto-title
     * - [Custom Text](@dj:hash_id) - custom text
     * @param string $content Raw markdown content
     * @param array $ctx Context information
     * @return string Processed markdown with resolved links
     */
    public function processContentLinks($content, $ctx = [])
    {
        // Early exit if no links to process
        if (strpos($content, self::LINK_PREFIX) === false) {
            return $content;
        }

        $pos = 0;
        $link_prefix_len = strlen(self::LINK_PREFIX);

        // Get all content data (cached) - only called if links exist
        $content_data = $this->getContentData($ctx);

        while (($pos = strpos($content, self::LINK_PREFIX, $pos)) !== false) {
            // Backtrack to find opening [ (optional)
            $bracket_start = $this->findOpeningBracket($content, $pos);
            $has_brackets = $bracket_start !== false;

            // Extract link text (could be empty for auto-title)
            $link_text = '';

            if ($has_brackets) {
                $link_text_start = $bracket_start + 1;
                $link_text_len = $pos - $bracket_start - 1;
                $link_text = substr($content, $link_text_start, $link_text_len);
            }

            // Parse forward to get hash_id (find closing paren)
            $ref_start = $pos + $link_prefix_len;
            $paren_end = strpos($content, ')', $ref_start);

            if ($paren_end === false) {
                $pos++;
                continue;
            }

            $hash_id_len = $paren_end - $ref_start;
            $hash_id = substr($content, $ref_start, $hash_id_len);

            // Validate hash_id: length check first (fastest), then alphanumeric
            $hash_len = strlen($hash_id);

            if ($hash_len < self::HASH_MIN_LEN || $hash_len > self::HASH_MAX_LEN || !ctype_alnum($hash_id)) {
                $pos++;
                continue;
            }

            // Lookup hash_id in content data
            if (isset($content_data[$hash_id])) {
                $url = $content_data[$hash_id]['url'];
                $title = $content_data[$hash_id]['title'];
                $text = empty($link_text) ? $title : $link_text;

                // Build old pattern: [text](@dj:hash) or (@dj:hash) depending on brackets
                $old_pattern = ($has_brackets ? '[' . $link_text . ']' : '') . self::LINK_PREFIX . $hash_id . ')';

                // Build new pattern: standard markdown link [text](url)
                $new_pattern = '[' . $text . '](' . $url . ')';

                $content = str_replace($old_pattern, $new_pattern, $content);
            }

            $pos++;
        }

        return $content;
    }

    /**
     * Find opening bracket by backtracking from a position
     * Looks for [ before the current position with reasonable backtrack limit
     * Stops if ] is encountered first (malformed)
     * @param string $content The content to search
     * @param int $pos Starting position to backtrack from
     * @return int|false Position of [ or false if not found
     */
    private function findOpeningBracket($content, $pos)
    {
        $start_pos = max(0, $pos - self::BRACKET_BACKTRACK_LIMIT);

        // Backtrack to find [
        for ($i = $pos - 1; $i >= $start_pos; $i--) {
            if ($content[$i] === ']') {
                return false; // Found closing bracket before opening - malformed
            }

            if ($content[$i] === '[') {
                return $i; // Found opening bracket
            }
        }

        return false;
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
