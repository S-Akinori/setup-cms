<?php

namespace App\Services\WordPress;

use DOMDocument;
use DOMElement;
use DOMNode;

class HtmlToMarkdown
{
    /**
     * @param  null|callable(string, string): (?string)  $imageResolver
     */
    public function convert(string $html, ?callable $imageResolver = null): string
    {
        $html = preg_replace('/<!--\s*\/?wp:.*?-->/su', '', $html) ?? $html;
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="wordpress-import-root">'.$html.'</div>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('wordpress-import-root');
        if (! $root) {
            return trim(strip_tags($html));
        }

        $markdown = $this->children($root, $imageResolver);
        $markdown = preg_replace("/[ \t]+\n/u", "\n", $markdown) ?? $markdown;
        $markdown = preg_replace("/\n{3,}/u", "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    private function children(DOMNode $node, ?callable $imageResolver, bool $inPre = false): string
    {
        $output = '';
        foreach ($node->childNodes as $child) {
            $output .= $this->node($child, $imageResolver, $inPre);
        }

        return $output;
    }

    private function node(DOMNode $node, ?callable $imageResolver, bool $inPre = false): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            if ($inPre) {
                return $node->nodeValue ?? '';
            }

            $text = preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $node->nodeValue ?? '')) ?? '';

            return $this->escape($text);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'noscript', 'iframe', 'object', 'embed', 'form'], true)) {
            return '';
        }

        $content = $this->children($node, $imageResolver, $inPre || $tag === 'pre');

        return match ($tag) {
            'h1' => '# '.trim($content)."\n\n",
            'h2' => '## '.trim($content)."\n\n",
            'h3' => '### '.trim($content)."\n\n",
            'h4' => '#### '.trim($content)."\n\n",
            'h5' => '##### '.trim($content)."\n\n",
            'h6' => '###### '.trim($content)."\n\n",
            'p', 'div', 'section', 'article', 'header', 'footer', 'figure', 'figcaption' => trim($content)."\n\n",
            'br' => "  \n",
            'strong', 'b' => trim($content) === '' ? '' : '**'.trim($content).'**',
            'em', 'i' => trim($content) === '' ? '' : '*'.trim($content).'*',
            'del', 's', 'strike' => trim($content) === '' ? '' : '~~'.trim($content).'~~',
            'a' => $this->link($node, $content),
            'img' => $this->image($node, $imageResolver),
            'ul' => $this->list($node, false, $imageResolver)."\n",
            'ol' => $this->list($node, true, $imageResolver)."\n",
            'li' => trim($content),
            'blockquote' => $this->blockquote($content),
            'pre' => $this->codeBlock($node->textContent),
            'code' => $inPre ? $content : $this->inlineCode($node->textContent),
            'hr' => "\n---\n\n",
            default => $content,
        };
    }

    private function link(DOMElement $node, string $content): string
    {
        $href = trim($node->getAttribute('href'));
        if ($href === '' || preg_match('/^(?:javascript|data):/i', $href)) {
            return $content;
        }

        return '['.trim($content).']('.str_replace(['(', ')'], ['%28', '%29'], $href).')';
    }

    private function image(DOMElement $node, ?callable $imageResolver): string
    {
        $src = trim($node->getAttribute('src'));
        if ($src === '' || preg_match('/^(?:javascript|data):/i', $src)) {
            return '';
        }

        $alt = trim($node->getAttribute('alt'));
        $resolved = $imageResolver ? $imageResolver($src, $alt) : null;
        $src = is_string($resolved) && $resolved !== '' ? $resolved : $src;

        return '!['.str_replace([']', "\n"], ['\\]', ' '], $alt).']('.str_replace(['(', ')'], ['%28', '%29'], $src).')';
    }

    private function list(DOMElement $node, bool $ordered, ?callable $imageResolver): string
    {
        $lines = [];
        $number = 1;
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement || strtolower($child->tagName) !== 'li') {
                continue;
            }

            $main = '';
            $nested = '';
            foreach ($child->childNodes as $part) {
                if ($part instanceof DOMElement && in_array(strtolower($part->tagName), ['ul', 'ol'], true)) {
                    $nested .= $this->node($part, $imageResolver);
                } else {
                    $main .= $this->node($part, $imageResolver);
                }
            }

            $prefix = $ordered ? ($number++).'. ' : '- ';
            $line = $prefix.trim($main);
            if (trim($nested) !== '') {
                $line .= "\n".preg_replace('/^/m', '  ', trim($nested));
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function blockquote(string $content): string
    {
        return preg_replace('/^/m', '> ', trim($content))."\n\n";
    }

    private function codeBlock(string $content): string
    {
        $fence = str_contains($content, '```') ? '````' : '```';

        return $fence."\n".rtrim($content)."\n".$fence."\n\n";
    }

    private function inlineCode(string $content): string
    {
        $fence = str_contains($content, '`') ? '``' : '`';

        return $fence.trim($content).$fence;
    }

    private function escape(string $text): string
    {
        return preg_replace('/([\\\\*_\[\]<>])/u', '\\\\$1', $text) ?? $text;
    }
}
