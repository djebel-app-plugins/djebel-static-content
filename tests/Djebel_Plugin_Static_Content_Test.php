<?php
/**
 * Unit tests for Djebel_Plugin_Static_Content. The framework + plugin are loaded by the
 * shared test bootstrap — this file loads nothing.
 */

use PHPUnit\Framework\TestCase;

class Djebel_Plugin_Static_Content_Test extends TestCase
{
    private $plugin_obj;

    protected function setUp(): void
    {
        $this->plugin_obj = Djebel_Plugin_Static_Content::getInstance();
    }

    /**
     * Each test registers exactly one web path filter, so exactly one removal succeeds.
     * The hook registry is global, and a filter left behind would decide the web path
     * for every test that runs later.
     */
    protected function tearDown(): void
    {
        $sub_path_removed = Dj_App_Hooks::removeFilter('app.core.request.web_path', ['Djebel_Plugin_Static_Content_Test', 'filterSubPathWebPath']);
        $root_removed = Dj_App_Hooks::removeFilter('app.core.request.web_path', ['Djebel_Plugin_Static_Content_Test', 'filterRootWebPath']);

        $this->assertTrue($sub_path_removed || $root_removed, 'No web path filter was left to remove');
    }

    /**
     * A site served from a sub-path, such as a dev copy.
     * @param string $web_path
     * @return string
     */
    public static function filterSubPathWebPath($web_path)
    {
        return '/projects/my-site';
    }

    /**
     * A site served from the domain root, where detection answers '/'.
     * @param string $web_path
     * @return string
     */
    public static function filterRootWebPath($web_path)
    {
        return '/';
    }

    /**
     * Stands in for a site's cached collection: each key is a hash a page can ask for, and each
     * record names the file to open.
     * @param array $records
     * @return array
     */
    public static function filterCachedRecords($records)
    {
        $data_dir = __DIR__ . '/data/single_post';

        $records = [
            'abc123def456' => [ 'file' => $data_dir . '/front_matter_hash.md', ],
            'fedcba654321' => [ 'file' => $data_dir . '/file_name-dj-fedcba654321.md', ],
            'f00d1234abcd' => [ 'file' => $data_dir . '/front_matter_hash.md', ],
            'documentation' => [ 'file' => $data_dir . '/documentation.txt', ],
            '0e2222222222' => [ 'file' => $data_dir . '/zero_e_hash.md', ],
            'introduction' => [ 'file' => $data_dir . '/introduction.md', ],
            'a1b2c3d4e5f6' => [ 'file' => $data_dir . '/array_hash.md', ],
            '333333333333' => [ 'file' => $data_dir . '/all_ids.md', ],
            '222222222222' => [ 'file' => $data_dir . '/all_ids.md', ],
            '555555555555' => [ 'file' => $data_dir . '/hash_and_id.md', ],
            '444444444444' => [ 'file' => $data_dir . '/hash_and_id.md', ],
        ];

        return $records;
    }

    /**
     * Stands in for the markdown plugin, which this suite does not load: the whole file is the
     * content, and the id lines are the only front matter read.
     * @param string $content
     * @param array $ctx
     * @return Dj_App_Result
     */
    public static function filterParseFrontMatter($content, $ctx = [])
    {
        $res_obj = new Dj_App_Result();
        $read_res = Dj_App_File_Util::read($ctx['file']);
        $meta = [];
        $matches = [];

        // A line that is only an id field, the value optionally in [brackets], which front matter
        // parsing turns into an array. Matches "hash: abc123" and "id: [abc]", not "a hash: abc".
        if (preg_match_all('/^(hash_id|hash|id):\s*(\[?)(\w+)\]?\s*$/m', $read_res->output, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $id_line) {
                $field_name = $id_line[1];
                $meta[$field_name] = empty($id_line[2]) ? $id_line[3] : [ $id_line[3], ];
            }
        }

        $res_obj->meta = $meta;
        $res_obj->content = $read_res->output;
        $res_obj->status(true);

        return $res_obj;
    }

    /**
     * Points the collection scan at the committed posts.
     * @param array $scan_dirs
     * @return array
     */
    public static function filterScanDirs($scan_dirs)
    {
        $scan_dirs = [ __DIR__ . '/data/single_post', ];

        return $scan_dirs;
    }

    /**
     * Renders the single-post page for one hash, with the listeners in place only for the call.
     * The web path filter stays, for tearDown to take off.
     * @param string|array $hash_id
     * @return string
     */
    public function renderPostByHash($hash_id)
    {
        Dj_App_Hooks::addFilter('app.core.request.web_path', ['Djebel_Plugin_Static_Content_Test', 'filterRootWebPath']);
        Dj_App_Hooks::addFilter('app.cache.pre_get.data', ['Djebel_Plugin_Static_Content_Test', 'filterCachedRecords']);
        Dj_App_Hooks::addFilter('app.plugins.markdown.parse_front_matter', ['Djebel_Plugin_Static_Content_Test', 'filterParseFrontMatter']);

        $req_obj = Dj_App_Request::getInstance();

        try {
            $saved_plugin_params = $req_obj->get('djebel_plugin_static_content_data', []);
            $plugin_params = [ 'hash_id' => $hash_id, ];
            $req_obj->set('djebel_plugin_static_content_data', $plugin_params);

            $render_params = [ 'content_id' => 'blog', ];
            $html = $this->plugin_obj->renderSingleContent($render_params);
        } finally {
            $req_obj->set('djebel_plugin_static_content_data', $saved_plugin_params);
            $is_cache_removed = Dj_App_Hooks::removeFilter('app.cache.pre_get.data', ['Djebel_Plugin_Static_Content_Test', 'filterCachedRecords']);
            $is_parser_removed = Dj_App_Hooks::removeFilter('app.plugins.markdown.parse_front_matter', ['Djebel_Plugin_Static_Content_Test', 'filterParseFrontMatter']);
        }

        $this->assertTrue($is_cache_removed, 'the cache listener came off');
        $this->assertTrue($is_parser_removed, 'the front matter listener came off');

        return $html;
    }

    /**
     * Pins the normal case: a post that carries the requested hash in its front matter opens.
     */
    public function testRenderSingleContentOpensAPostWithTheHashInFrontMatter()
    {
        $html = $this->renderPostByHash('abc123def456');

        $this->assertStringContainsString('Text of the post with a front matter hash.', $html);
    }

    /**
     * Pins the other place a post may carry its hash: the -dj- marker in its file name.
     */
    public function testRenderSingleContentOpensAPostWithTheHashInItsName()
    {
        $html = $this->renderPostByHash('fedcba654321');

        $this->assertStringContainsString('Text of the post with a file name hash.', $html);
    }

    /**
     * Pins that a record cannot open a post under a hash the post doesn't carry.
     */
    public function testRenderSingleContentRefusesAFileCarryingAnotherHash()
    {
        $html = $this->renderPostByHash('f00d1234abcd');

        $this->assertStringNotContainsString('Text of the post with a front matter hash.', $html);
        $this->assertStringStartsWith('<!--', $html);
    }

    /**
     * Pins that the slug a file name gives doesn't count as a hash, even when the slug is the very
     * value that was asked for.
     */
    public function testRenderSingleContentRefusesAFileCarryingNoHash()
    {
        $html = $this->renderPostByHash('documentation');

        $this->assertStringNotContainsString('Text of a file that is not a post.', $html);
        $this->assertStringStartsWith('<!--', $html);
    }

    /**
     * Pins the comparison as text: 0e1111111111 and 0e2222222222 are different hashes, though PHP's
     * == reads both as the number zero.
     */
    public function testRenderSingleContentComparesHashesAsText()
    {
        $html = $this->renderPostByHash('0e2222222222');

        $this->assertStringNotContainsString('Text of the post whose hash reads as a number.', $html);
        $this->assertStringStartsWith('<!--', $html);
    }

    /**
     * Pins that only text can name a post: a request parameter can hold an array, and the page
     * answers with its no-post comment instead of failing.
     */
    public function testRenderSingleContentRefusesAHashThatIsNotText()
    {
        $hash_id_list = [ 'abc123def456', ];
        $html = $this->renderPostByHash($hash_id_list);

        $this->assertStringNotContainsString('Text of the post with a front matter hash.', $html);
        $this->assertStringStartsWith('<!--', $html);
    }

    /**
     * Pins that a list in a post's hash field counts as no hash: only text can be a hash.
     */
    public function testRenderSingleContentRefusesAFileWhoseHashIsAList()
    {
        $html = $this->renderPostByHash('a1b2c3d4e5f6');

        $this->assertStringNotContainsString('Text of the post whose hash is a list.', $html);
        $this->assertStringStartsWith('<!--', $html);
    }

    /**
     * Pins hash_id as the field that names a post carrying several id fields.
     */
    public function testRenderSingleContentOpensAPostByItsHashId()
    {
        $html = $this->renderPostByHash('333333333333');

        $this->assertStringContainsString('Text of the post with every id field.', $html);
    }

    /**
     * Pins that the fields behind hash_id don't name the post as well.
     */
    public function testRenderSingleContentRefusesAHashBehindAHashId()
    {
        $html = $this->renderPostByHash('222222222222');

        $this->assertStringNotContainsString('Text of the post with every id field.', $html);
        $this->assertStringStartsWith('<!--', $html);
    }

    /**
     * Pins hash ahead of id on a post with no hash_id.
     */
    public function testRenderSingleContentOpensAPostByItsHashBeforeItsId()
    {
        $html = $this->renderPostByHash('555555555555');

        $this->assertStringContainsString('Text of the post with a hash and an id.', $html);
    }

    /**
     * Pins that an id behind a hash doesn't name the post as well.
     */
    public function testRenderSingleContentRefusesAnIdBehindAHash()
    {
        $html = $this->renderPostByHash('444444444444');

        $this->assertStringNotContainsString('Text of the post with a hash and an id.', $html);
        $this->assertStringStartsWith('<!--', $html);
    }

    /**
     * Pins that the loader compares no hash in a slug-mode collection, which has none to compare.
     */
    public function testRenderSingleContentOpensASlugModePost()
    {
        $options_obj = Dj_App_Options::getInstance();

        try {
            $options_data = $options_obj->toArray();
            $slug_options_data = $options_data;
            $slug_options_data['plugins']['djebel_static_content']['blog']['use_content_slugs'] = 1;
            $options_obj->setData($slug_options_data);

            $html = $this->renderPostByHash('introduction');
        } finally {
            $options_obj->setData($options_data);
        }

        $this->assertStringContainsString('Text of the post with no hash.', $html);
    }

    /**
     * Calls one plugin method over the committed posts, with the cache off and the scan listeners
     * in place only for the call. The web path filter stays, for tearDown to take off.
     * @param string $method_name
     * @param array $call_args
     * @return mixed what the method returned
     */
    public function callOverCommittedPosts($method_name, $call_args)
    {
        Dj_App_Hooks::addFilter('app.core.request.web_path', ['Djebel_Plugin_Static_Content_Test', 'filterRootWebPath']);
        Dj_App_Hooks::addFilter('app.plugin.static_content.scan_dirs', ['Djebel_Plugin_Static_Content_Test', 'filterScanDirs']);
        Dj_App_Hooks::addFilter('app.plugins.markdown.parse_front_matter', ['Djebel_Plugin_Static_Content_Test', 'filterParseFrontMatter']);

        $req_obj = Dj_App_Request::getInstance();

        try {
            $saved_cache_param = $req_obj->get('dj_cache');
            $req_obj->set('dj_cache', '0');

            $result = call_user_func_array([$this->plugin_obj, $method_name], $call_args);
        } finally {
            $req_obj->set('dj_cache', $saved_cache_param);
            $is_scan_removed = Dj_App_Hooks::removeFilter('app.plugin.static_content.scan_dirs', ['Djebel_Plugin_Static_Content_Test', 'filterScanDirs']);
            $is_parser_removed = Dj_App_Hooks::removeFilter('app.plugins.markdown.parse_front_matter', ['Djebel_Plugin_Static_Content_Test', 'filterParseFrontMatter']);
        }

        $this->assertTrue($is_scan_removed, 'the scan listener came off');
        $this->assertTrue($is_parser_removed, 'the front matter listener came off');

        return $result;
    }

    /**
     * Pins that only a page asking for one hash is checked: the collection scan asks for none, so
     * a post with no hash stays listed under its slug, and so does one whose hash field is a list.
     * The digits, dashes and underscores a file name starts with stay out of that slug.
     */
    public function testGetContentDataListsAPostCarryingNoHash()
    {
        $data_params = [ 'content_id' => 'blog', ];
        $call_args = [ $data_params, ];
        $content_data = $this->callOverCommittedPosts('getContentData', $call_args);

        $this->assertArrayHasKey('abc123def456', $content_data);
        $this->assertArrayHasKey('introduction', $content_data);
        $this->assertArrayHasKey('array-hash', $content_data);
        $this->assertArrayHasKey('numbered-post', $content_data);
    }

    /**
     * Pins that only a post with a hash gets a link, since a post without one has no page to open.
     */
    public function testGetContentDataGivesAPostWithNoHashNoUrl()
    {
        $data_params = [ 'content_id' => 'blog', ];
        $call_args = [ $data_params, ];
        $content_data = $this->callOverCommittedPosts('getContentData', $call_args);

        $this->assertEmpty($content_data['introduction']['url']);
        $this->assertStringContainsString('-dj-abc123def456', $content_data['abc123def456']['url']);
    }

    /**
     * Pins that a slug-mode collection, whose posts carry no hash by design, keeps its links.
     */
    public function testGetContentDataKeepsTheUrlInSlugMode()
    {
        $options_obj = Dj_App_Options::getInstance();

        try {
            $options_data = $options_obj->toArray();
            $slug_options_data = $options_data;
            $slug_options_data['plugins']['djebel_static_content']['blog']['use_content_slugs'] = 1;
            $options_obj->setData($slug_options_data);

            $data_params = [ 'content_id' => 'blog', ];
            $call_args = [ $data_params, ];
            $content_data = $this->callOverCommittedPosts('getContentData', $call_args);
        } finally {
            $options_obj->setData($options_data);
        }

        $this->assertNotEmpty($content_data['introduction']['url']);
    }

    /**
     * Pins the line the scan leaves for a post with no hash, which is how its author finds out why
     * it has no link. Only what the scan appends is read back.
     */
    public function testGetContentDataLogsAPostWithNoHash()
    {
        $log_file = Dj_App_Log::file();
        $log_size_before = is_file($log_file) ? filesize($log_file) : 0;

        $data_params = [ 'content_id' => 'blog', ];
        $call_args = [ $data_params, ];
        $this->assertNotEmpty($this->callOverCommittedPosts('getContentData', $call_args));

        $read_res = Dj_App_File_Util::readPartially($log_file, 8192, $log_size_before);

        $this->assertStringContainsString('introduction.md', $read_res->output);
        $this->assertStringNotContainsString('front_matter_hash.md', $read_res->output);
    }

    /**
     * Pins that the listing shows a post with no hash by its title alone and still links the rest.
     */
    public function testRenderContentPrintsNoLinkForAPostWithNoHash()
    {
        $render_params = [ 'content_id' => 'blog', 'results_per_page' => 50, ];
        $call_args = [ $render_params, ];
        $html = $this->callOverCommittedPosts('renderContent', $call_args);

        $this->assertStringContainsString('-dj-abc123def456', $html);
        $this->assertStringContainsString('introduction', $html);
        $this->assertStringNotContainsString('-dj-introduction', $html);
    }

    /**
     * Pins the whole point of the method: a link and an image written from the site root
     * land inside the site when it runs under a sub-path.
     */
    public function testProcessSiteLinksAddsWebPathUnderSubPath()
    {
        Dj_App_Hooks::addFilter('app.core.request.web_path', ['Djebel_Plugin_Static_Content_Test', 'filterSubPathWebPath']);

        $content = "Write to [us](/contact).\n\n![Logo](/dj-content/files/images/logo.png)";
        $result = $this->plugin_obj->processSiteLinks($content);

        $expected = "Write to [us](/projects/my-site/contact).\n\n![Logo](/projects/my-site/dj-content/files/images/logo.png)";
        $this->assertEquals($expected, $result);
    }

    /**
     * Pins that a live site at the domain root gets its content back byte for byte, so
     * the method costs it nothing and changes nothing.
     */
    public function testProcessSiteLinksLeavesRootSiteUnchanged()
    {
        Dj_App_Hooks::addFilter('app.core.request.web_path', ['Djebel_Plugin_Static_Content_Test', 'filterRootWebPath']);

        $content = 'Write to [us](/contact).';
        $result = $this->plugin_obj->processSiteLinks($content);

        $this->assertEquals($content, $result);
    }

    /**
     * Pins that a protocol-relative link, which points off the site, keeps its double
     * slash instead of turning into a path under the site.
     */
    public function testProcessSiteLinksKeepsProtocolRelativeLinks()
    {
        Dj_App_Hooks::addFilter('app.core.request.web_path', ['Djebel_Plugin_Static_Content_Test', 'filterSubPathWebPath']);

        $content = 'A [library](//cdn.example.com/lib) and the [contact page](/contact).';
        $result = $this->plugin_obj->processSiteLinks($content);

        $expected = 'A [library](//cdn.example.com/lib) and the [contact page](/projects/my-site/contact).';
        $this->assertEquals($expected, $result);
    }

    /**
     * Pins that only site-root links change: absolute URLs, page-relative links and links
     * already written with the web path magic var come back as they were.
     */
    public function testProcessSiteLinksSkipsOtherLinks()
    {
        Dj_App_Hooks::addFilter('app.core.request.web_path', ['Djebel_Plugin_Static_Content_Test', 'filterSubPathWebPath']);

        $content = '[a](https://example.com/x) [b](page) [c](__SITE_WEB_PATH__/about) [d](#top)';
        $result = $this->plugin_obj->processSiteLinks($content);

        $this->assertEquals($content, $result);
    }

    /**
     * Pins that Cyrillic link text survives the rewrite whole, as the Bulgarian site's
     * closing line has it.
     */
    public function testProcessSiteLinksKeepsMultiByteText()
    {
        Dj_App_Hooks::addFilter('app.core.request.web_path', ['Djebel_Plugin_Static_Content_Test', 'filterSubPathWebPath']);

        $content = 'Ако имаш нужда от помощ, [пиши ми](/contact).';
        $result = $this->plugin_obj->processSiteLinks($content);

        $expected = 'Ако имаш нужда от помощ, [пиши ми](/projects/my-site/contact).';
        $this->assertEquals($expected, $result);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    /**
     * Pins that a featured image written as a site path loads under a sub-path, the same
     * way a link in the post does.
     */
    public function testGenerateFeaturedImageUrlAddsWebPathUnderSubPath()
    {
        Dj_App_Hooks::addFilter('app.core.request.web_path', ['Djebel_Plugin_Static_Content_Test', 'filterSubPathWebPath']);

        $url = $this->plugin_obj->generateFeaturedImageUrl('/dj-content/files/images/my-post.webp');

        $this->assertEquals('/projects/my-site/dj-content/files/images/my-post.webp', $url);
    }

    /**
     * Pins that a site at the domain root gets the path back as written.
     */
    public function testGenerateFeaturedImageUrlLeavesRootSitePath()
    {
        Dj_App_Hooks::addFilter('app.core.request.web_path', ['Djebel_Plugin_Static_Content_Test', 'filterRootWebPath']);

        $url = $this->plugin_obj->generateFeaturedImageUrl('/dj-content/files/images/my-post.webp');

        $this->assertEquals('/dj-content/files/images/my-post.webp', $url);
    }

    /**
     * Pins that an image hosted elsewhere keeps its URL, including the protocol-relative
     * form, even when the site runs under a sub-path.
     */
    public function testGenerateFeaturedImageUrlKeepsOtherHosts()
    {
        Dj_App_Hooks::addFilter('app.core.request.web_path', ['Djebel_Plugin_Static_Content_Test', 'filterSubPathWebPath']);

        $https_url = $this->plugin_obj->generateFeaturedImageUrl('https://cdn.example.com/my-post.webp');
        $protocol_relative_url = $this->plugin_obj->generateFeaturedImageUrl('//cdn.example.com/my-post.webp');

        $this->assertEquals('https://cdn.example.com/my-post.webp', $https_url);
        $this->assertEquals('//cdn.example.com/my-post.webp', $protocol_relative_url);
    }

    /**
     * Pins that a missing value and one written in [brackets], which front matter parsing
     * turns into an array, both mean no image rather than an error.
     */
    public function testGenerateFeaturedImageUrlSkipsEmptyAndArrayValues()
    {
        Dj_App_Hooks::addFilter('app.core.request.web_path', ['Djebel_Plugin_Static_Content_Test', 'filterSubPathWebPath']);

        $empty_url = $this->plugin_obj->generateFeaturedImageUrl('');
        $array_url = $this->plugin_obj->generateFeaturedImageUrl([ '/a.webp', '/b.webp', ]);

        $this->assertEmpty($empty_url);
        $this->assertEmpty($array_url);
    }
}
