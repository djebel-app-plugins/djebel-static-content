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
