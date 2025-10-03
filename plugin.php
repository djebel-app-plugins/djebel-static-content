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

    private $plugin_id = 'djebel-static-blog';
    private $cache_dir;
    private $current_collection_id;
    private $sort_by = 'file';
    private $statuses = [self::STATUS_DRAFT, self::STATUS_PUBLISHED];

    public function init()
    {
        $this->cache_dir = Dj_App_Util::getCoreCacheDir(['plugin' => $this->plugin_id]);

        $shortcode_obj = Dj_App_Shortcode::getInstance();
        $shortcode_obj->addShortcode('djebel_static_blog', [$this, 'renderBlog']);
    }

    public function getStatuses()
    {
        $statuses = $this->statuses;
        $statuses = Dj_App_Hooks::applyFilter('app.plugin.static_blog.statuses', $statuses);

        return $statuses;
    }

    public function renderBlog($params = [])
    {
        $title = empty($params['title']) ? 'Blog Posts' : trim($params['title']);
        $render_title = empty($params['render_title']) ? 0 : 1;
        $blog_data = $this->getBlogData($params);

        if (empty($blog_data)) {
            return '<!-- No blog posts available -->';
        }

        ob_start();
        ?>
        <style>
        .djebel-plugin-static-blog-container {
            max-width: 900px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .djebel-plugin-static-blog-title {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 2rem;
            color: #1f2937;
        }

        .djebel-plugin-static-blog-post {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            padding: 1.5rem;
            background: #ffffff;
            transition: all 0.2s ease;
        }

        .djebel-plugin-static-blog-post:hover {
            border-color: #d1d5db;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .djebel-plugin-static-blog-post-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .djebel-plugin-static-blog-post-title a {
            color: #1f2937;
            text-decoration: none;
        }

        .djebel-plugin-static-blog-post-title a:hover {
            color: #3b82f6;
        }

        .djebel-plugin-static-blog-post-meta {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 1rem;
        }

        .djebel-plugin-static-blog-post-summary {
            color: #374151;
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .djebel-plugin-static-blog-post-tags {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .djebel-plugin-static-blog-tag {
            background: #f3f4f6;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.875rem;
            color: #4b5563;
        }
        </style>

        <div class="djebel-plugin-static-blog-container">
            <?php if ($render_title || !empty($params['title'])): ?>
                <h2 class="djebel-plugin-static-blog-title"><?php echo Djebel_App_HTML::encodeEntities($title); ?></h2>
            <?php endif; ?>

            <?php foreach ($blog_data as $post_rec): ?>
                <article class="djebel-plugin-static-blog-post">
                    <h3 class="djebel-plugin-static-blog-post-title">
                        <a href="<?php echo Djebel_App_HTML::encodeEntities($post_rec['url']); ?>">
                            <?php echo Djebel_App_HTML::encodeEntities($post_rec['title']); ?>
                        </a>
                    </h3>

                    <div class="djebel-plugin-static-blog-post-meta">
                        <?php if (!empty($post_rec['creation_date'])): ?>
                            <span><?php echo Djebel_App_HTML::encodeEntities(date('F j, Y', strtotime($post_rec['creation_date']))); ?></span>
                        <?php endif; ?>

                        <?php if (!empty($post_rec['author'])): ?>
                            <span> · by <?php echo Djebel_App_HTML::encodeEntities($post_rec['author']); ?></span>
                        <?php endif; ?>

                        <?php if (!empty($post_rec['category'])): ?>
                            <span> · <?php echo Djebel_App_HTML::encodeEntities($post_rec['category']); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($post_rec['summary'])): ?>
                        <div class="djebel-plugin-static-blog-post-summary">
                            <?php echo Djebel_App_HTML::encodeEntities($post_rec['summary']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($post_rec['tags'])): ?>
                        <div class="djebel-plugin-static-blog-post-tags">
                            <?php foreach ($post_rec['tags'] as $tag): ?>
                                <span class="djebel-plugin-static-blog-tag"><?php echo Djebel_App_HTML::encodeEntities($tag); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function getBlogData($params = [])
    {
        $collection_id = empty($params['id']) ? 'default' : trim($params['id']);
        $this->current_collection_id = Dj_App_String_Util::formatSlug($collection_id);

        $cache_key = $this->plugin_id . '-' . $this->current_collection_id;
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

    public function clearCache($collection_id = 'default')
    {
        $formatted_id = Dj_App_String_Util::formatSlug($collection_id);
        $cache_key = $this->plugin_id . '-' . $formatted_id;
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

        usort($blog_data, [$this, 'sortPosts']);

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
        $collection_id = empty($params['id']) ? 'default' : trim($params['id']);
        $formatted_id = Dj_App_String_Util::formatSlug($collection_id);

        $data_dir = Dj_App_Util::getCorePrivateDataDir(['plugin' => $this->plugin_id]) . '/' . $formatted_id;

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
        $slug = empty($meta['slug']) ? Dj_App_String_Util::formatSlug($title) : $meta['slug'];

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
        if (!empty($data['site_url'])) {
            $site_url = $data['site_url'];
        } else {
            $req_obj = Dj_App_Request::getInstance();
            $site_url = $req_obj->getSiteUrl();
        }

        $slug_parts = [$data['slug']];

        if (!empty($data['hash_id'])) {
            $slug_parts[] = $data['hash_id'];
        }

        $full_slug = implode('-', $slug_parts);
        $full_slug = Dj_App_String_Util::formatSlug($full_slug);

        $post_url = $site_url . '/' . $full_slug;

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

        $str = basename($str, '.md');
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

    private function sortPosts($a, $b)
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
