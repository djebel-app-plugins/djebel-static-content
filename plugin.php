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
    private $plugin_id = 'djebel-static-blog';
    private $cache_dir;
    private $current_collection_id;
    private $sort_by = 'file';
    private $statuses = ['draft', 'published'];

    public function init()
    {
        $this->cache_dir = Dj_App_Util::getCoreCacheDir(['plugin' => $this->plugin_id]);

        $shortcode_obj = Dj_App_Shortcode::getInstance();
        $shortcode_obj->addShortcode('djebel_static_blog', [$this, 'renderBlog']);
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
        .djebel-blog-container {
            max-width: 900px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .djebel-blog-title {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 2rem;
            color: #1f2937;
        }

        .djebel-blog-post {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            padding: 1.5rem;
            background: #ffffff;
            transition: all 0.2s ease;
        }

        .djebel-blog-post:hover {
            border-color: #d1d5db;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .djebel-blog-post-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #1f2937;
        }

        .djebel-blog-post-meta {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 1rem;
        }

        .djebel-blog-post-excerpt {
            color: #374151;
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .djebel-blog-post-tags {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .djebel-blog-tag {
            background: #f3f4f6;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.875rem;
            color: #4b5563;
        }
        </style>

        <div class="djebel-blog-container">
            <?php if ($render_title || !empty($params['title'])): ?>
                <h2 class="djebel-blog-title"><?php echo esc_html($title); ?></h2>
            <?php endif; ?>

            <?php foreach ($blog_data as $post): ?>
                <article class="djebel-blog-post">
                    <h3 class="djebel-blog-post-title"><?php echo esc_html($post['title']); ?></h3>

                    <div class="djebel-blog-post-meta">
                        <?php if (!empty($post['creation_date'])): ?>
                            <span><?php echo esc_html(date('F j, Y', strtotime($post['creation_date']))); ?></span>
                        <?php endif; ?>

                        <?php if (!empty($post['author'])): ?>
                            <span> · by <?php echo esc_html($post['author']); ?></span>
                        <?php endif; ?>

                        <?php if (!empty($post['category'])): ?>
                            <span> · <?php echo esc_html($post['category']); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($post['excerpt'])): ?>
                        <div class="djebel-blog-post-excerpt">
                            <?php echo esc_html($post['excerpt']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($post['tags']) && is_array($post['tags'])): ?>
                        <div class="djebel-blog-post-tags">
                            <?php foreach ($post['tags'] as $tag): ?>
                                <span class="djebel-blog-tag"><?php echo esc_html($tag); ?></span>
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
                $post = $this->loadPostFromMarkdown($file);

                if ($post) {
                    $blog_data[] = $post;
                }
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
        $iterator = new RecursiveIteratorIterator($directory);

        $filtered = new RecursiveCallbackFilterIterator($iterator, function($file) {
            return $file->isFile() && $file->getExtension() === 'md';
        });

        foreach ($filtered as $file) {
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

    private function loadPostFromMarkdown($file)
    {
        if (!file_exists($file)) {
            return false;
        }

        $file_content = Dj_App_File_Util::read($file);

        if (empty($file_content)) {
            return false;
        }

        $meta = Dj_App_Util::extractMetaInfo($file_content);

        $status = isset($meta['status']) ? $meta['status'] : 'published';

        if (!in_array($status, $this->statuses)) {
            $status = 'published';
        }

        if ($status === 'draft') {
            return false;
        }

        $ctx = [
            'file' => $file,
            'meta' => $meta,
        ];

        $html_content = Dj_App_Hooks::applyFilter('app.plugins.markdown.parse_markdown', $file_content, $ctx);

        if (empty($html_content)) {
            $html_content = $file_content;
        }

        $result = [
            'title' => isset($meta['title']) ? $meta['title'] : '',
            'content' => $html_content,
            'excerpt' => isset($meta['excerpt']) ? $meta['excerpt'] : '',
            'creation_date' => isset($meta['creation_date']) ? $meta['creation_date'] : '',
            'last_modified' => isset($meta['last_modified']) ? $meta['last_modified'] : '',
            'sort_order' => isset($meta['sort_order']) ? (int)$meta['sort_order'] : 0,
            'category' => isset($meta['category']) ? $meta['category'] : '',
            'tags' => isset($meta['tags']) ? (array) $meta['tags'] : [],
            'author' => isset($meta['author']) ? $meta['author'] : '',
            'status' => $status,
            'file' => $file,
        ];

        return $result;
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
