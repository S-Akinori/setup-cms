<?php

namespace App\Services\WordPress;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Generator;
use RuntimeException;
use XMLReader;

class WxrReader
{
    private const WRAPPER = <<<'XML'
<rss xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:wfw="http://wellformedweb.org/CommentAPI/"
     xmlns:dc="http://purl.org/dc/elements/1.1/"
     xmlns:wp="http://wordpress.org/export/1.2/"><channel>%s</channel></rss>
XML;

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function items(string $path): Generator
    {
        if (! is_file($path)) {
            throw new RuntimeException("WXRファイルを読み込めません: {$path}");
        }

        [$readPath, $temporaryFile] = $this->normalizedPath($path);
        $reader = new XMLReader;
        if (! $reader->open($readPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            if (is_resource($temporaryFile)) {
                fclose($temporaryFile);
            }
            throw new RuntimeException('WXRファイルを開けませんでした。');
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    throw new RuntimeException('DOCTYPEを含むXMLは安全のため取り込めません。');
                }

                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'item') {
                    continue;
                }

                $xml = sprintf(self::WRAPPER, $reader->readOuterXml());
                yield $this->parseItem($xml);
            }
        } finally {
            $reader->close();
            if (is_resource($temporaryFile)) {
                fclose($temporaryFile);
            }
        }
    }

    /** @return list<string> */
    public function referencedImagePaths(string $path): array
    {
        $attachments = [];
        $thumbnailIds = [];
        $urls = [];

        foreach ($this->items($path) as $item) {
            if ($item['type'] === 'attachment' && $item['id'] !== '' && $item['attachment_url'] !== '') {
                $attachments[$item['id']] = $item['attachment_url'];

                continue;
            }
            if (! in_array($item['type'], ['post', 'page'], true)) {
                continue;
            }

            array_push($urls, ...$this->contentImageUrls($item['content']));
            $thumbnailId = $item['meta']['_thumbnail_id'] ?? null;
            if (is_string($thumbnailId) && $thumbnailId !== '') {
                $thumbnailIds[] = $thumbnailId;
            }
        }

        foreach (array_unique($thumbnailIds) as $thumbnailId) {
            if (isset($attachments[$thumbnailId])) {
                $urls[] = $attachments[$thumbnailId];
            }
        }

        return array_values(array_unique(array_filter(array_map(fn (string $url) => $this->uploadsPath($url), $urls))));
    }

    /**
     * WordPressのエクスポートファイルにXML宣言前の空行やUTF-8 BOMがある場合、
     * 元ファイルを変更せず一時ストリーム上で正規化する。
     *
     * @return array{string, resource|null}
     */
    private function normalizedPath(string $path): array
    {
        $source = fopen($path, 'rb');
        if ($source === false) {
            throw new RuntimeException("WXRファイルを読み込めません: {$path}");
        }

        $prefix = fread($source, 4096);
        if ($prefix === false) {
            fclose($source);

            throw new RuntimeException('WXRファイルの先頭を読み込めませんでした。');
        }

        $normalized = preg_replace('/\A(?:\xEF\xBB\xBF)?[ \t\r\n]*(?=<\?xml\b)/', '', $prefix);
        if (! is_string($normalized) || $normalized === $prefix) {
            fclose($source);

            return [$path, null];
        }

        $temporary = tmpfile();
        if ($temporary === false) {
            fclose($source);

            throw new RuntimeException('WXR解析用の一時ファイルを作成できませんでした。');
        }

        fwrite($temporary, $normalized);
        stream_copy_to_stream($source, $temporary);
        fclose($source);
        fflush($temporary);

        $metadata = stream_get_meta_data($temporary);
        $temporaryPath = $metadata['uri'] ?? null;
        if (! is_string($temporaryPath)) {
            fclose($temporary);

            throw new RuntimeException('WXR解析用の一時ファイルを参照できませんでした。');
        }

        return [$temporaryPath, $temporary];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseItem(string $xml): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('WXR内の記事XMLを解析できませんでした。');
        }

        $xpath = new DOMXPath($document);
        $item = $xpath->query('/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="item"]')->item(0);
        if (! $item instanceof DOMElement) {
            throw new RuntimeException('WXR内に記事要素がありません。');
        }

        $categories = [];
        foreach ($xpath->query('./*[local-name()="category"]', $item) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $categories[] = [
                    'domain' => $node->getAttribute('domain'),
                    'slug' => $node->getAttribute('nicename'),
                    'name' => trim($node->textContent),
                ];
            }
        }

        $meta = [];
        foreach ($xpath->query('./*[local-name()="postmeta"]', $item) ?: [] as $node) {
            $key = $this->value($xpath, './*[local-name()="meta_key"]', $node);
            if ($key !== '') {
                $meta[$key] = $this->value($xpath, './*[local-name()="meta_value"]', $node);
            }
        }

        $comments = [];
        foreach ($xpath->query('./*[local-name()="comment"]', $item) ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $comments[] = [
                'id' => $this->value($xpath, './*[local-name()="comment_id"]', $node),
                'parent_id' => $this->value($xpath, './*[local-name()="comment_parent"]', $node),
                'author_name' => $this->value($xpath, './*[local-name()="comment_author"]', $node),
                'author_email' => $this->value($xpath, './*[local-name()="comment_author_email"]', $node),
                'author_url' => $this->value($xpath, './*[local-name()="comment_author_url"]', $node),
                'author_ip' => $this->value($xpath, './*[local-name()="comment_author_IP"]', $node),
                'date' => $this->value($xpath, './*[local-name()="comment_date_gmt"]', $node)
                    ?: $this->value($xpath, './*[local-name()="comment_date"]', $node),
                'content' => $this->value($xpath, './*[local-name()="comment_content"]', $node),
                'approved' => $this->value($xpath, './*[local-name()="comment_approved"]', $node),
                'type' => $this->value($xpath, './*[local-name()="comment_type"]', $node),
            ];
        }

        return [
            'id' => $this->value($xpath, './*[local-name()="post_id"]', $item),
            'type' => $this->value($xpath, './*[local-name()="post_type"]', $item),
            'status' => $this->value($xpath, './*[local-name()="status"]', $item),
            'slug' => $this->value($xpath, './*[local-name()="post_name"]', $item),
            'title' => $this->value($xpath, './*[local-name()="title"]', $item),
            'content' => $this->value($xpath, './*[local-name()="encoded" and namespace-uri()="http://purl.org/rss/1.0/modules/content/"]', $item),
            'excerpt' => $this->value($xpath, './*[local-name()="encoded" and contains(namespace-uri(), "excerpt")]', $item),
            'date' => $this->value($xpath, './*[local-name()="post_date_gmt"]', $item)
                ?: $this->value($xpath, './*[local-name()="post_date"]', $item),
            'attachment_url' => $this->value($xpath, './*[local-name()="attachment_url"]', $item),
            'categories' => $categories,
            'meta' => $meta,
            'comment_status' => $this->value($xpath, './*[local-name()="comment_status"]', $item),
            'comments' => $comments,
        ];
    }

    private function value(DOMXPath $xpath, string $query, \DOMNode $context): string
    {
        $node = $xpath->query($query, $context)?->item(0);

        return $node ? trim($node->textContent) : '';
    }

    /** @return list<string> */
    private function contentImageUrls(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div id="content-root">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return [];
        }

        $urls = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            $source = trim($image->getAttribute('src'));
            if ($source !== '') {
                $urls[] = $source;
            }
        }

        return $urls;
    }

    private function uploadsPath(string $url): ?string
    {
        $path = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        $marker = '/wp-content/uploads/';
        $position = stripos($path, $marker);
        if ($position === false) {
            return null;
        }

        $relative = ltrim(rawurldecode(substr($path, $position + strlen($marker))), '/');

        return $relative !== '' && ! str_contains($relative, '..') ? $relative : null;
    }
}
