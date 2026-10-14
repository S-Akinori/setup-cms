<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Parser\MarkdownParser;

class NoRawHtml implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $environment = new Environment;
        $environment->addExtension(new CommonMarkCoreExtension);
        $document = (new MarkdownParser($environment))->parse((string) $value);

        foreach ($document->iterator() as $node) {
            if ($node instanceof HtmlBlock || $node instanceof HtmlInline) {
                $fail('本文では生HTMLを使用できません。Markdown記法を使用してください。');

                return;
            }
        }
    }
}
