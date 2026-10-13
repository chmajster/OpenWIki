<?php
declare(strict_types=1);
namespace OpenWiki\Wiki;

/** Text quote selectors are stored outside document sources; display decorations are never persisted. */
final class CommentAnchor
{
    public static function text(string $html): string
    {
        $fragment = (new \Masterminds\HTML5())->loadHTMLFragment($html);
        $walk = static function (\DOMNode $node) use (&$walk): string {
            if ($node instanceof \DOMText) return $node->data;
            if ($node instanceof \DOMElement && in_array(strtolower($node->tagName), ['script', 'style'], true)) return '';
            $result = '';
            foreach ($node->childNodes as $child) $result .= $walk($child);
            return $result . ($node instanceof \DOMElement && in_array(strtolower($node->tagName), ['p','div','h1','h2','h3','h4','h5','h6','li','tr','pre','summary','blockquote','br'], true) ? "\n" : '');
        };
        return self::normalize($walk($fragment));
    }

    public static function normalize(string $text): string { return trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $text) ?? ''); }

    public static function create(string $text, array $input): array
    {
        $quote = $input['quote'] ?? null; $start = filter_var($input['start'] ?? null, FILTER_VALIDATE_INT);
        if (!is_string($quote) || $quote === '' || mb_strlen($quote) > 2000 || $start === false || $start < 0 || self::normalize($quote) !== $quote
            || ($input['hash'] ?? '') !== hash('sha256', $text) || mb_substr($text, $start, mb_strlen($quote)) !== $quote) {
            throw new \InvalidArgumentException('Zaznaczenie nie odpowiada wskazanej rewizji. Odśwież dokument i zaznacz fragment ponownie.');
        }
        return ['version' => 1, 'quote' => $quote, 'prefix' => mb_substr($text, max(0, $start - 64), min(64, $start)),
            'suffix' => mb_substr($text, $start + mb_strlen($quote), 64), 'start' => $start, 'hash' => hash('sha256', $text), 'occurrences' => count(self::positions($text, $quote))];
    }

    public static function locate(array $anchor, string $text): ?array
    {
        $quote = $anchor['quote']; $length = mb_strlen($quote);
        if (hash('sha256', $text) === $anchor['hash'] && mb_substr($text, $anchor['start'], $length) === $quote) return ['start' => $anchor['start'], 'end' => $anchor['start'] + $length];
        $positions = self::positions($text, $quote); $matches = [];
        foreach ($positions as $start) {
            $prefix = mb_substr($text, max(0, $start - mb_strlen($anchor['prefix'])), min($start, mb_strlen($anchor['prefix'])));
            $suffix = mb_substr($text, $start + $length, mb_strlen($anchor['suffix']));
            $before = $anchor['prefix'] !== '' && $prefix === $anchor['prefix'];
            $after = $anchor['suffix'] !== '' && $suffix === $anchor['suffix'];
            if (($before && $after) || ($anchor['occurrences'] === 1 && count($positions) === 1 && ($before || $after))) $matches[] = $start;
        }
        return count($matches) === 1 ? ['start' => $matches[0], 'end' => $matches[0] + $length] : null;
    }

    private static function positions(string $text, string $quote): array
    {
        $result = []; $offset = 0;
        while (($position = mb_strpos($text, $quote, $offset)) !== false) { $result[] = $position; $offset = $position + mb_strlen($quote); }
        return $result;
    }
}
