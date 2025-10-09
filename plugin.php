<?php
/*
plugin_name: Djebel Static Blog
plugin_uri: https://djebel.com/plugins/djebel-static-blog
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
text_domain: djebel-static-blog
license: gpl2
requires: djebel-markdown
*/

$obj = new Djebel_Plugin_Static_Blog();
Dj_App_Hooks::addAction('app.core.init', [$obj, 'init']);

class Djebel_Plugin_Static_Blog
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const PARTIAL_READ_BYTES = 512;
    public const FULL_READ_BYTES = 5242880;
    public const DEFAULT_RECORDS_PER_PAGE = 10;

    private $plugin_id = 'djebel-static-blog';
    private $cache_dir;
    private $sort_by = 'file';
    private $statuses = [self::STATUS_DRAFT, self::STATUS_PUBLISHED];
    private $request_param_key = 'djebel_plugin_static_blog_data';

    public function init()
    {
        $this->cache_dir = Dj_App_Util::getCoreCacheDir(['plugin' => $this->plugin_id]);

        $shortcode_obj = Dj_App_Shortcode::getInstance();
        $shortcode_obj->addShortcode('djebel_static_blog', [$this, 'renderBlog']);
        $shortcode_obj->addShortcode('djebel_static_blog_post', [$this, 'renderPost']);

        // Hook into theme's page file resolution to redirect blog post URLs to blog template
        Dj_App_Hooks::addFilter('app.themes.current_theme.page_content_file', [$this, 'resolvePageContentFile'], 10, 2);
    }

    public function getStatuses()
    {
        $statuses = $this->statuses;
        $statuses = Dj_App_Hooks::applyFilter('app.plugin.static_blog.statuses', $statuses);

        return $statuses;
    }

    public function renderPost($params = [])
    {
        $req_obj = Dj_App_Request::getInstance();
        $plugin_params = $req_obj->get($this->request_param_key, []);
        $hash_id = !empty($plugin_params['hash_id']) ? $plugin_params['hash_id'] : '';

        if (empty($hash_id)) {
            return "<!--\nNo post hash_id provided\n-->";
        }

        $blog_data = $this->getBlogData($params);

        if (empty($blog_data[$hash_id])) {
            return "<!--\nPost not found\n-->";
        }

        $post_rec = $blog_data[$hash_id];

        $options_obj = Dj_App_Options::getInstance();
        $show_date = Dj_App_Util::isEnabled($options_obj->get('plugins.djebel-static-blog.show_date'));
        $show_author = Dj_App_Util::isEnabled($options_obj->get('plugins.djebel-static-blog.show_author'));
        $show_category = Dj_App_Util::isEnabled($options_obj->get('plugins.djebel-static-blog.show_category'));
        $show_tags = Dj_App_Util::isEnabled($options_obj->get('plugins.djebel-static-blog.show_tags'));

        ob_start();
        ?>
        <article class="djebel-plugin-static-blog-post-single">
            <h1 class="djebel-plugin-static-blog-post-single-title"><?php echo Djebel_App_HTML::encodeEntities($post_rec['title']); ?></h1>

            <?php if ($show_date || $show_author || $show_category): ?>
                <div class="djebel-plugin-static-blog-post-single-meta">
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
                <div class="djebel-plugin-static-blog-post-single-tags">
                    <?php foreach ($post_rec['tags'] as $tag): ?>
                        <span class="djebel-plugin-static-blog-tag"><?php echo Djebel_App_HTML::encodeEntities($tag); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="djebel-plugin-static-blog-post-single-content">
                <?php echo $post_rec['content']; ?>
            </div>
        </article>
        <?php
        $html = ob_get_clean();
        $ctx = ['post_rec' => $post_rec];
        $html = Dj_App_Hooks::applyFilter('app.plugin.static_blog.render_blog_post', $html, $ctx);

        return $html;
    }

    public function renderBlog($params = [])
    {
        $req_obj = Dj_App_Request::getInstance();
        $plugin_params = $req_obj->get($this->request_param_key, []);

        // Auto-detect if this is a single post request
        $hash_id = $this->isBlogPostRequest($params);

        if (!empty($hash_id)) {
            // Inject hash_id into plugin params array
            $plugin_params['hash_id'] = $hash_id;
            $req_obj->set($this->request_param_key, $plugin_params);

            // Delegate to renderPost for single post rendering
            return $this->renderPost($params);
        }

        // Render blog listing (existing code)
        $title = empty($params['title']) ? 'Blog Posts' : trim($params['title']);
        $render_title = empty($params['render_title']) ? 0 : 1;
        $blog_data = $this->getBlogData($params);

        if (empty($blog_data)) {
            return "<!--\nNo blog posts available\n-->";
        }
        $current_page = !empty($plugin_params['page']) ? (int) $plugin_params['page'] : 1;
        $current_page = max(1, $current_page);

        $per_page = empty($params['per_page']) ? self::DEFAULT_RECORDS_PER_PAGE : (int) $params['per_page'];
        $total_posts = count($blog_data);
        $total_pages = ceil($total_posts / $per_page);
        $offset = ($current_page - 1) * $per_page;

        $blog_data = array_slice($blog_data, $offset, $per_page, true);

        if (empty($blog_data)) {
            return "<!--\nNo blog posts available on this page\n-->";
        }

        ob_start();
        ?>
        <div class="djebel-plugin-static-blog-container">
            <?php if ($render_title || !empty($params['title'])): ?>
                <h2 class="djebel-plugin-static-blog-title"><?php echo Djebel_App_HTML::encodeEntities($title); ?></h2>
            <?php endif; ?>

            <?php
            $options_obj = Dj_App_Options::getInstance();
            $show_date = Dj_App_Util::isEnabled($options_obj->get('plugins.djebel-static-blog.show_date'));
            $show_author = Dj_App_Util::isEnabled($options_obj->get('plugins.djebel-static-blog.show_author'));
            $show_category = Dj_App_Util::isEnabled($options_obj->get('plugins.djebel-static-blog.show_category'));
            $show_summary = Dj_App_Util::isEnabled($options_obj->get('plugins.djebel-static-blog.show_summary', 1)); // default enabled
            $show_tags = Dj_App_Util::isEnabled($options_obj->get('plugins.djebel-static-blog.show_tags'));
            ?>
            <?php foreach ($blog_data as $post_rec): ?>
                <article class="djebel-plugin-static-blog-post">
                    <h3 class="djebel-plugin-static-blog-post-title">
                        <a href="<?php echo Djebel_App_HTML::encodeEntities($post_rec['url']); ?>">
                            <?php echo Djebel_App_HTML::encodeEntities($post_rec['title']); ?>
                        </a>
                    </h3>

                    <?php if ($show_date || $show_author || $show_category): ?>
                        <div class="djebel-plugin-static-blog-post-meta">
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
                        <div class="djebel-plugin-static-blog-post-summary">
                            <?php echo Djebel_App_HTML::encodeEntities($post_rec['summary']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($show_tags && !empty($post_rec['tags'])): ?>
                        <div class="djebel-plugin-static-blog-post-tags">
                            <?php foreach ($post_rec['tags'] as $tag): ?>
                                <span class="djebel-plugin-static-blog-tag"><?php echo Djebel_App_HTML::encodeEntities($tag); ?></span>
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
                <div class="djebel-plugin-static-blog-pagination">
                    <?php if ($current_page > 1): ?>
                        <span class="djebel-plugin-static-blog-pagination-prev">
                            <a href="<?php echo Djebel_App_HTML::encodeEntities($prev_url); ?>">← Previous</a>
                        </span>
                    <?php endif; ?>

                    <?php if ($current_page && $total_pages): ?>
                        <span class="djebel-plugin-static-blog-pagination-info">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>
                    <?php endif; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <span class="djebel-plugin-static-blog-pagination-next">
                            <a href="<?php echo Djebel_App_HTML::encodeEntities($next_url); ?>">Next →</a>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $html = ob_get_clean();
        $ctx = ['blog_data' => $blog_data, 'params' => $params];
        $html = Dj_App_Hooks::applyFilter('app.plugin.static_blog.render_blog', $html, $ctx);

        return $html;
    }

    public function getBlogData($params = [])
    {
        $cache_key = $this->plugin_id;
        $cache_params = ['plugin' => $this->plugin_id, 'ttl' => 8 * 60 * 60];

        $options_obj = Dj_App_Options::getInstance();

        $cache_blog = $options_obj->get('plugins.djebel-static-blog.cache');
        $cache_blog = !Dj_App_Util::isDisabled($cache_blog);

        $cached_data = $cache_blog ? Dj_App_Cache::get($cache_key, $cache_params) : false;

        if (!empty($cached_data)) {
            return $cached_data;
        }

        $blog_data = $this->generateBlogData($params);

        Dj_App_Cache::set($cache_key, $blog_data, $cache_params);

        return $blog_data;
    }

    public function clearCache()
    {
        $cache_key = $this->plugin_id;
        $cache_params = ['plugin' => $this->plugin_id];

        $result = Dj_App_Cache::remove($cache_key, $cache_params);

        return $result;
    }

    private function generateBlogData($params = [])
    {
        $blog_data = [];
        $scan_dirs = $this->getScanDirectories($params);

        foreach ($scan_dirs as $scan_dir) {
            if (!is_dir($scan_dir)) {
                continue;
            }

            $md_files = $this->scanMarkdownFiles($scan_dir);

            foreach ($md_files as $file) {
                $post_rec = $this->loadPostFromMarkdown(['file' => $file, 'partial' => true]);

                if (empty($post_rec)) {
                    continue;
                }

                $hash_id = $post_rec['hash_id'];
                $post_rec['url'] = $this->generatePostUrl(['slug' => $post_rec['slug'], 'hash_id' => $hash_id]);
                $blog_data[$hash_id] = $post_rec;
            }
        }

        $options_obj = Dj_App_Options::getInstance();
        $sort_by = $options_obj->get('plugins.djebel-static-blog.sort_by');

        if (!empty($sort_by)) {
            $this->sort_by = $sort_by;
        }

        $this->sort_by = Dj_App_Hooks::applyFilter('app.plugin.static_blog.sort_by', $this->sort_by);

        // Allow customization of the sort callback
        $sort_callback = Dj_App_Hooks::applyFilter('app.plugin.static_blog.sort_callback', [$this, 'sortPosts']);

        // Use uasort to maintain hash_id keys for fast lookups
        uasort($blog_data, $sort_callback);

        $blog_data = Dj_App_Hooks::applyFilter('app.plugin.static_blog.data', $blog_data);

        return $blog_data;
    }

    private function getScanDirectories($params = [])
    {
        $default_dir = $this->getDataDirectory($params);
        $scan_dirs = [$default_dir];

        $options_obj = Dj_App_Options::getInstance();
        $config_dirs = $options_obj->get('plugins.djebel-static-blog.scan_dirs');

        if (!empty($config_dirs)) {
            if (is_string($config_dirs)) {
                $config_dirs = explode(',', $config_dirs);
                $config_dirs = array_map('trim', $config_dirs);
            }

            if (is_array($config_dirs)) {
                $scan_dirs = array_merge($scan_dirs, $config_dirs);
            }
        }

        $scan_dirs = Dj_App_Hooks::applyFilter('app.plugin.static_blog.scan_dirs', $scan_dirs);
        $scan_dirs = array_unique($scan_dirs);

        return $scan_dirs;
    }

    private function scanMarkdownFiles($scan_dir)
    {
        $md_files = [];

        if (!is_dir($scan_dir)) {
            return $md_files;
        }

        $directory = new RecursiveDirectoryIterator($scan_dir, RecursiveDirectoryIterator::SKIP_DOTS);

        $filtered = new RecursiveCallbackFilterIterator($directory, function($file) {
            return $file->getExtension() === 'md' && $file->isFile();
        });

        $iterator = new RecursiveIteratorIterator($filtered);

        foreach ($iterator as $file) {
            $md_files[] = $file->getPathname();
        }

        return $md_files;
    }

    private function getDataDirectory($params = [])
    {
        $data_dir = Dj_App_Util::getCorePrivateDataDir(['plugin' => $this->plugin_id]) . '/posts';

        return $data_dir;
    }

    private function loadPostFromMarkdown($params)
    {
        $file = $params['file'];
        $partial = empty($params['partial']) ? false : true;

        if (!file_exists($file)) {
            return [];
        }

        $max_len = $partial ? self::PARTIAL_READ_BYTES : self::FULL_READ_BYTES;
        $res_obj = Dj_App_File_Util::readPartially($file, $max_len);

        if ($res_obj->isError()) {
            return [];
        }

        $file_content = $res_obj->output;

        if (empty($file_content)) {
            return [];
        }

        $meta = Dj_App_Util::extractMetaInfo($file_content);
        $status = empty($meta['status']) ? self::STATUS_PUBLISHED : $meta['status'];

        if ($status === self::STATUS_DRAFT) {
            return [];
        }

        $statuses = $this->getStatuses();

        if (!in_array($status, $statuses)) {
            $status = self::STATUS_PUBLISHED;
        }

        if (!empty($meta['publish_date'])) {
            $publish_timestamp = strtotime($meta['publish_date']);

            if ($publish_timestamp && $publish_timestamp > time()) {
                return [];
            }
        }

        $ctx = [
            'file' => $file,
            'meta' => $meta,
        ];

        $html_content = Dj_App_Hooks::applyFilter('app.plugins.markdown.parse_markdown', $file_content, $ctx);

        if (empty($html_content)) {
            $html_content = $file_content;
        }

        $hash_id = !empty($meta['id']) ? $meta['id'] : '';

        if (empty($hash_id)) {
            $hash_id = $this->parseHashId($file);
        }

        $defaults = [
            'title' => '',
            'summary' => '',
            'creation_date' => '',
            'last_modified' => '',
            'publish_date' => '',
            'sort_order' => 0,
            'category' => '',
            'tags' => [],
            'author' => '',
            'slug' => '',
        ];

        foreach ($defaults as $key => $default_value) {
            if (empty($meta[$key])) {
                $meta[$key] = $default_value;
            }
        }

        if (is_string($meta['tags'])) {
            $meta['tags'] = (array) $meta['tags'];
        }

        $meta['sort_order'] = (int) $meta['sort_order'];

        $title = $meta['title'];

        if (empty($meta['slug'])) {
            $slug = basename($file, '.md');
            $slug = preg_replace('#^[\d\-_]+#', '', $slug);
            $slug = Dj_App_String_Util::formatSlug($slug);
        } else {
            $slug = $meta['slug'];
        }

        $slug = Dj_App_Hooks::applyFilter('app.plugin.static_blog.post_slug', $slug, $ctx);

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
     * Generate post URL from post data
     * @param array $data
     * @return string
     */
    private function generatePostUrl($data)
    {
        $req_obj = Dj_App_Request::getInstance();

        if (!empty($data['base_url'])) {
            $base_url = $data['base_url'];
        } else {
            $base_url = $req_obj->getCleanRequestUrl();
        }

        $ctx = ['data' => $data];
        $base_url = Dj_App_Hooks::applyFilter('app.plugin.static_blog.post_base_url', $base_url, $ctx);

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

        $post_url = $base_url . '/' . $full_slug;

        return $post_url;
    }

    /**
     * Parse hash ID from string (filename or URL)
     * Extracts 10-12 character alphanumeric hash from end of string
     * @param string $str
     * @return string
     */
    public function parseHashId($str)
    {
        if (empty($str)) {
            return '';
        }

        $str = basename($str);
        $str = str_replace(['.md', '.php'], '', $str);
        $str = substr($str, -15);

        if (!Dj_App_String_Util::isAlphaNumericExt($str)) {
            return '';
        }

        $str = strtolower($str);

        if (preg_match('#[\-\_]([a-z\d]{10,12})$#i', $str, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Detects if current request is for a single blog post
     * Fast detection: checks URL for blog prefix and extracts hash from last segment
     * @param array $params Shortcode parameters
     * @return string|false Hash ID if blog post request, false otherwise
     */
    public function isBlogPostRequest($params = [])
    {
        $options_obj = Dj_App_Options::getInstance();
        $blog_prefix = $options_obj->get('plugins.djebel-static-blog.blog_prefix', '/blog');

        $req_obj = Dj_App_Request::getInstance();
        $req_url = $req_obj->getCleanRequestUrl();

        // Quick check: does URL contain blog prefix anywhere?
        if (strpos($req_url, $blog_prefix) === false) {
            return false;
        }

        // Get the last 15 characters from URL for hash detection
        // This is fast and works regardless of how many segments we have
        $last_segment = substr($req_url, -15);

        // Quick check: does it contain a dash? (hash separator)
        if (strpos($last_segment, '-') === false) {
            return false;
        }

        // Try to extract hash_id from the last segment
        $hash_id = $this->parseHashId($last_segment);

        return !empty($hash_id) ? $hash_id : false;
    }

    /**
     * Resolves page content file for blog posts
     * Intercepts theme file resolution and redirects blog post URLs to blog template
     * Example: /blog/getting-started-abc123def456 -> blog.php (if hash matches existing post)
     * @param string $loop_file The file path being checked by theme
     * @param array $ctx Context from theme (pages_dir, theme_dir, page, full_page)
     * @return string Modified file path or original if not a blog post
     */
    public function resolvePageContentFile($loop_file, $ctx = [])
    {
        // Early exit: check if we have required data
        if (empty($ctx['pages_dir']) || empty($loop_file)) {
            return $loop_file;
        }

        $pages_dir = $ctx['pages_dir'];

        // Only process if file doesn't exist
        if (file_exists($loop_file)) {
            return $loop_file;
        }

        // Quick check: does filename contain a dash? (fast string check)
        if (strpos($loop_file, '-') === false) {
            return $loop_file;
        }

        // Try to extract hash from filename (parseHashId handles .php/.md removal)
        $hash_id = $this->parseHashId($loop_file);

        if (empty($hash_id)) {
            return $loop_file;
        }

        // Check if this hash matches an existing blog post
        $blog_data = $this->getBlogData();

        if (empty($blog_data[$hash_id])) {
            return $loop_file;
        }

        $options_obj = Dj_App_Options::getInstance();
        $blog_template = $options_obj->get('plugins.djebel-static-blog.blog_template', 'blog');

        // Append .php extension if not present
        $file_ext = pathinfo($blog_template, PATHINFO_EXTENSION);

        if (empty($file_ext)) {
            $blog_template .= '.php';
        }

        // Build path to blog template
        $blog_template_file = $pages_dir . '/' . $blog_template;

        // Inject hash_id into plugin params array
        $req_obj = Dj_App_Request::getInstance();
        $plugin_params = $req_obj->get($this->request_param_key, []);
        $plugin_params['hash_id'] = $hash_id;
        $req_obj->set($this->request_param_key, $plugin_params);

        return $blog_template_file;
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
            $val_a = isset($a['creation_date']) ? strtotime($a['creation_date']) : false;
            $val_b = isset($b['creation_date']) ? strtotime($b['creation_date']) : false;
        } elseif ($field === 'last_modified') {
            $val_a = isset($a['last_modified']) ? strtotime($a['last_modified']) : false;
            $val_b = isset($b['last_modified']) ? strtotime($b['last_modified']) : false;
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
}
