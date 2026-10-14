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
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("WXRファイルを読み込めません: {$path}");
        }

        $reader = new XMLReader;
        if (! $reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
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
        }
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
        ];
    }

    private function value(DOMXPath $xpath, string $query, \DOMNode $context): string
    {
        $node = $xpath->query($query, $context)?->item(0);

        return $node ? trim($node->textContent) : '';
    }
}
