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

    public function test_it_keeps_classic_editor_line_breaks_as_readable_paragraphs(): void
    {
        $markdown = (new HtmlToMarkdown)->convert("最初の段落です。\n\n次の段落です。\n最後の段落です。");

        $this->assertSame("最初の段落です。\n\n次の段落です。\n\n最後の段落です。", $markdown);
    }

    public function test_it_separates_block_elements_from_surrounding_text(): void
    {
        $markdown = (new HtmlToMarkdown)->convert('見出し前の文章<h2>見出し</h2>見出し後の文章<ul><li>項目1</li><li>項目2</li></ul>続き');

        $this->assertSame(
            "見出し前の文章\n\n## 見出し\n\n見出し後の文章\n\n- 項目1\n- 項目2\n\n続き",
            $markdown,
        );
    }
}
