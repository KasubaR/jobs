<?php

namespace App\Collectors\Html;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;

/** A parsed HTML page that can be queried with CSS selectors. Reads only; never fetches. */
class HtmlPage
{
    private DOMDocument $dom;

    private DOMXPath $xpath;

    private CssSelectorConverter $css;

    public function __construct(string $html)
    {
        $previous = libxml_use_internal_errors(true);
        $this->dom = new DOMDocument;
        // The encoding hint stops libxml from reading UTF-8 as Latin-1.
        $this->dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->xpath = new DOMXPath($this->dom);
        $this->css = new CssSelectorConverter;
    }

    /** @return list<DOMNode> */
    public function select(string $selector, ?DOMNode $within = null): array
    {
        $nodes = $this->xpath->query($this->css->toXPath($selector), $within);

        return $nodes === false ? [] : iterator_to_array($nodes, false);
    }

    public function first(string $selector, ?DOMNode $within = null): ?DOMNode
    {
        return $this->select($selector, $within)[0] ?? null;
    }

    public function text(?DOMNode $node): string
    {
        return $node === null ? '' : trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }

    public function html(DOMNode $node): string
    {
        return trim($this->dom->saveHTML($node));
    }
}
