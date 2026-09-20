<?php
namespace Personaizer\Content;

/**
 * Rendered WordPress HTML → readable markdown. Headings, paragraphs, lists, links, emphasis and tables survive;
 * everything else (scripts, styles, forms, images, layout wrappers) is dropped. The result is what a page IS to a
 * reader, which is what the persona should learn — stripping every tag (what 2.x did) lost the structure that
 * tells "Delivery" from "Returns" on a policy page.
 *
 * Pure PHP (DOMDocument), no WordPress: tested on its own.
 */
final class Markdown {

    public static function from_html( $html ) {
        $html = trim( (string) $html );
        if ( $html === '' ) return '';

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors( true );
        // The meta tag pins UTF-8; the wrapper keeps DOMDocument from inventing html/body around fragments in odd ways.
        $dom->loadHTML( '<?xml encoding="UTF-8"><div id="pz-root">' . $html . '</div>', LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        $root = $dom->getElementById( 'pz-root' );
        $text = $root ? self::block( $root, '' ) : '';
        $text = preg_replace( "/[ \t]+\n/", "\n", $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );
        return trim( $text );
    }

    private const SKIP = array( 'script', 'style', 'noscript', 'template', 'svg', 'iframe', 'form', 'button', 'input', 'select', 'textarea', 'nav', 'head' );

    /** Render a block-level node's children, returning text with block boundaries as blank lines. */
    private static function block( \DOMNode $node, $indent ) {
        $out = '';
        foreach ( $node->childNodes as $child ) {
            $out .= self::node( $child, $indent );
        }
        return $out;
    }

    private static function node( \DOMNode $node, $indent ) {
        if ( $node instanceof \DOMText ) {
            return self::inline_text( $node->nodeValue );
        }
        if ( ! $node instanceof \DOMElement ) return '';

        $tag = strtolower( $node->tagName );
        if ( in_array( $tag, self::SKIP, true ) ) return '';

        switch ( $tag ) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $level = (int) $tag[1];
                return "\n\n" . str_repeat( '#', $level ) . ' ' . self::collapse( self::inline( $node ) ) . "\n\n";
            case 'p':
                return "\n\n" . self::collapse( self::inline( $node ) ) . "\n\n";
            case 'br':
                return "\n";
            case 'hr':
                return "\n\n---\n\n";
            case 'ul': case 'ol':
                return "\n\n" . self::list( $node, $tag === 'ol', $indent ) . "\n\n";
            case 'li':
                // Reached only for a stray <li> outside a list.
                return "\n- " . self::collapse( self::inline( $node ) ) . "\n";
            case 'blockquote':
                $inner = trim( self::block( $node, $indent ) );
                return "\n\n" . preg_replace( '/^/m', '> ', $inner ) . "\n\n";
            case 'pre':
                return "\n\n```\n" . trim( $node->textContent ) . "\n```\n\n";
            case 'table':
                return "\n\n" . self::table( $node ) . "\n\n";
            case 'img': case 'picture': case 'figure':
                // Images travel in the record's image library, not in the text. A figure's caption still counts.
                $caption = '';
                foreach ( $node->getElementsByTagName( 'figcaption' ) as $fc ) $caption .= self::inline( $fc );
                return $caption !== '' ? "\n\n" . self::collapse( $caption ) . "\n\n" : '';
            case 'a': case 'strong': case 'b': case 'em': case 'i': case 'span': case 'code': case 'small': case 'sup': case 'sub': case 'mark': case 'abbr': case 'time':
                return self::inline( $node );
            default:
                // div, section, article, header, footer, main, aside, details, summary, dl/dt/dd…: a block boundary.
                return "\n\n" . self::block( $node, $indent ) . "\n\n";
        }
    }

    /** Inline content of a node as one line of markdown (links, emphasis, code kept). */
    private static function inline( \DOMNode $node ) {
        return self::inline_nodes( $node->childNodes );
    }

    /** @param iterable<\DOMNode> $nodes */
    private static function inline_nodes( $nodes ) {
        $out = '';
        foreach ( $nodes as $child ) {
            if ( $child instanceof \DOMText ) {
                $out .= self::inline_text( $child->nodeValue );
                continue;
            }
            if ( ! $child instanceof \DOMElement ) continue;
            $tag = strtolower( $child->tagName );
            if ( in_array( $tag, self::SKIP, true ) ) continue;
            switch ( $tag ) {
                case 'a':
                    $href = trim( $child->getAttribute( 'href' ) );
                    $text = self::collapse( self::inline( $child ) );
                    $out .= $text === '' ? '' : ( preg_match( '#^https?://#i', $href ) ? "[{$text}]({$href})" : $text );
                    break;
                case 'strong': case 'b':
                    $text = self::collapse( self::inline( $child ) );
                    $out .= $text === '' ? '' : "**{$text}**";
                    break;
                case 'em': case 'i':
                    $text = self::collapse( self::inline( $child ) );
                    $out .= $text === '' ? '' : "*{$text}*";
                    break;
                case 'code':
                    $out .= '`' . trim( $child->textContent ) . '`';
                    break;
                case 'br':
                    $out .= "\n";
                    break;
                case 'img': case 'picture': case 'svg':
                    break;
                default:
                    // A block inside an inline context (a div in a p, a list in a td): flatten it.
                    $out .= ' ' . self::inline( $child ) . ' ';
            }
        }
        return $out;
    }

    private static function list( \DOMElement $list, $ordered, $indent ) {
        $lines = array();
        $n     = 0;
        foreach ( $list->childNodes as $item ) {
            if ( ! $item instanceof \DOMElement || strtolower( $item->tagName ) !== 'li' ) continue;
            $n++;
            $marker = $ordered ? "{$n}. " : '- ';
            $own    = array();
            $nested = '';
            foreach ( $item->childNodes as $part ) {
                if ( $part instanceof \DOMElement && in_array( strtolower( $part->tagName ), array( 'ul', 'ol' ), true ) ) {
                    $nested .= "\n" . self::list( $part, strtolower( $part->tagName ) === 'ol', $indent . '  ' );
                } else {
                    $own[] = $part;   // text and inline (or a <p>, flattened) — the item's own line
                }
            }
            $lines[] = $indent . $marker . self::collapse( self::inline_nodes( $own ) ) . $nested;
        }
        return implode( "\n", $lines );
    }

    private static function table( \DOMElement $table ) {
        $rows = array();
        foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
            $cells = array();
            foreach ( $tr->childNodes as $cell ) {
                if ( ! $cell instanceof \DOMElement ) continue;
                $t = strtolower( $cell->tagName );
                if ( $t !== 'td' && $t !== 'th' ) continue;
                $cells[] = str_replace( '|', '\\|', self::collapse( self::inline( $cell ) ) );
            }
            if ( $cells ) $rows[] = $cells;
        }
        if ( empty( $rows ) ) return '';
        $width = max( array_map( 'count', $rows ) );
        $lines = array();
        foreach ( $rows as $i => $cells ) {
            $cells   = array_pad( $cells, $width, '' );
            $lines[] = '| ' . implode( ' | ', $cells ) . ' |';
            if ( $i === 0 ) $lines[] = '|' . str_repeat( ' --- |', $width );
        }
        return implode( "\n", $lines );
    }

    private static function inline_text( $text ) {
        // Whitespace inside text nodes is HTML whitespace: collapse it, keep a single space at the edges.
        return preg_replace( '/\s+/u', ' ', (string) $text );
    }

    private static function collapse( $text ) {
        return trim( preg_replace( '/[ \t]+/', ' ', preg_replace( '/\s*\n\s*/', "\n", (string) $text ) ) );
    }
}
