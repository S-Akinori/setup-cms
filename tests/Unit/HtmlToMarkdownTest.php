<?php

namespace Tests\Unit;

use App\Services\WordPress\HtmlToMarkdown;
use PHPUnit\Framework\TestCase;

class HtmlToMarkdownTest extends TestCase
{
    public function test_it_converts_common_html_and_removes_active_content(): void
    {
        $markdown = (new HtmlToMarkdown)->convert(
            '<h2>題名</h2><p><a href="https://example.com">リンク</a><br><strong>強調</strong></p><script>bad()</script>'
        );

        $this->assertStringContainsString('## 題名', $markdown);
        $this->assertStringContainsString('[リンク](https://example.com)', $markdown);
        $this->assertStringContainsString('**強調**', $markdown);
        $this->assertStringNotContainsString('bad()', $markdown);
    }
}
