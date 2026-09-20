<?php
use Personaizer\Content\Fingerprint;
use Personaizer\Content\Markdown;
use Personaizer\Site\Languages;
use PHPUnit\Framework\TestCase;

final class ContentTest extends TestCase {

    public function test_markdown_keeps_structure_and_drops_chrome(): void {
        $html = '<div class="wp-block"><h2>Delivery</h2><p>We ship <strong>daily</strong>, see <a href="https://shop.example.com/faq">the FAQ</a>.</p>'
              . '<ul><li>Tbilisi: <em>same day</em></li><li>Regions: 2 days<ul><li>Islands: 4</li></ul></li></ul>'
              . '<script>alert(1)</script><style>.x{}</style><img src="https://x/y.jpg" alt="ignored">'
              . '<table><tr><th>Zone</th><th>Price</th></tr><tr><td>A</td><td>5 GEL</td></tr></table>'
              . '<dl><dt>Thickness</dt><dd>3 mm</dd><dt>Size</dt><dd>1220 x 2440</dd></dl>'
              . '<p>It&#8217;s &amp; done</p></div>';
        $expected = "## Delivery\n\nWe ship **daily**, see [the FAQ](https://shop.example.com/faq).\n\n"
                  . "- Tbilisi: *same day*\n- Regions: 2 days\n  - Islands: 4\n\n"
                  . "| Zone | Price |\n| --- | --- |\n| A | 5 GEL |\n\n"
                  . "- Thickness: 3 mm\n- Size: 1220 x 2440\n\nIt’s & done";
        $this->assertSame( $expected, Markdown::from_html( $html ) );
    }

    public function test_markdown_of_nothing_is_nothing(): void {
        $this->assertSame( '', Markdown::from_html( '' ) );
        $this->assertSame( '', Markdown::from_html( '<div><script>x()</script></div>' ) );
    }

    public function test_fingerprint_ignores_key_order_but_not_list_order(): void {
        $a = array( 'id' => 'wc-product-1', 'title' => 'A', 'images' => array( 'x', 'y' ), 'attributes' => array( 'sku' => '1', 'color' => array( 'Blue' ) ) );
        $b = array( 'attributes' => array( 'color' => array( 'Blue' ), 'sku' => '1' ), 'images' => array( 'x', 'y' ), 'title' => 'A', 'id' => 'wc-product-1' );
        $c = array( 'id' => 'wc-product-1', 'title' => 'A', 'images' => array( 'y', 'x' ), 'attributes' => array( 'sku' => '1', 'color' => array( 'Blue' ) ) );
        $this->assertSame( Fingerprint::of( $a ), Fingerprint::of( $b ) );
        $this->assertNotSame( Fingerprint::of( $a ), Fingerprint::of( $c ) );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', Fingerprint::of( $a ) );
    }

    public function test_languages_normalise_to_iso639_primary_first(): void {
        $this->assertSame( array( 'ka', 'en', 'ru' ), Languages::normalize( array( 'ka-GE', 'en_US', 'ru', 'KA', '', 'zz9' ) ) );
        $this->assertSame( array( 'zh', 'pt' ), Languages::normalize( array( 'zh-Hans-CN', 'pt_BR' ) ) );
        $this->assertSame( array(), Languages::normalize( array( '', '-' ) ) );
    }

    public function test_alt_text_that_is_only_the_filename_is_no_description(): void {
        $alt = array( \Personaizer\Content\PostPayload::class, 'alt_or_nothing' );
        $this->assertSame( '', $alt( 'shape_3.png', 'https://x.com/wp-content/uploads/2026/06/shape_3.png' ) );
        $this->assertSame( '', $alt( 'shape_3', 'https://x.com/wp-content/uploads/2026/06/shape_3.png' ) );
        $this->assertSame( '', $alt( 'LED', 'https://x.com/wp-content/uploads/2026/06/LED-1024x513.png' ) );
        $this->assertSame( 'კვების ბლოკი', $alt( 'კვების ბლოკი', 'https://x.com/wp-content/uploads/2026/06/psu-1024x513.png' ) );
        $this->assertSame( 'Our team in Tbilisi', $alt( ' Our team in Tbilisi ', 'https://x.com/team.jpg' ) );
        $this->assertSame( '', $alt( '', 'https://x.com/team.jpg' ) );
    }
}
