<?php
use Personaizer\Api\Contracts;
use PHPUnit\Framework\TestCase;

/**
 * The plugin's readers against the backend's own recorded exchanges. Every fixture is what the API really sent;
 * if a field the plugin relies on is renamed or moved on the other side, this is where it shows.
 */
final class ContractsTest extends TestCase {

    public function test_connect_start_and_token(): void {
        $start = pz_fixture( 'connect.start' );
        $this->assertSame( 200, $start['status'] );
        $this->assertSame( '<guid>', Contracts::start_response( $start['response'] )['connect_id'] );
        // What the plugin sends at start is the profile + inventory shape this repo builds (Site\Profile, Site\Streams).
        $this->assertSame( array( 'ka', 'en' ), $start['request']['site_profile']['audience_languages'] );
        $this->assertSame( array( 'stream_key', 'label', 'count', 'type' ), array_keys( $start['request']['inventory'][0] ) );

        $token = Contracts::token_response( pz_fixture( 'connect.token' )['response'] );
        $this->assertSame( '<ik_key>', $token['integration_key'] );
        $this->assertSame( '<identity_secret>', $token['identity_secret'] );
        $this->assertSame( '<guid>', $token['persona_id'] );

        $spent = pz_fixture( 'connect.token.invalid_grant' );
        $this->assertSame( 400, $spent['status'] );
        $this->assertSame( 'invalid_grant', Contracts::problem_code( $spent['response'] ) );
    }

    public function test_sync(): void {
        $sync = Contracts::sync_response( pz_fixture( 'sync' )['response'] );
        $this->assertSame( 'active', $sync['status'] );
        $this->assertSame( 'shop.example.com', $sync['brand']['name'] );
        $this->assertSame( 'Shop Assistant', $sync['persona']['name'] );
        $this->assertFalse( $sync['persona']['building'] );
        $this->assertSame( 'Test Plan', $sync['plan']['name'] );
        $this->assertSame( 2000.0, $sync['plan']['knowledge_units_limit'] );
        $this->assertTrue( Contracts::has_headroom( $sync ) );
        $this->assertSame( array( 'pages', 'products' ), array_keys( $sync['streams'] ) );
        $this->assertTrue( $sync['streams']['pages']['enabled'] );
        $this->assertSame( 'files', $sync['streams']['pages']['type'] );
        $this->assertSame( 'catalog', $sync['streams']['products']['type'] );
        $this->assertSame( '<guid>', $sync['streams']['products']['source_id'] );
        $this->assertNull( $sync['streams']['products']['last_reconcile'] );

        $frozen = Contracts::sync_response( pz_fixture( 'sync.disconnected' )['response'] );
        $this->assertSame( 'disconnected', $frozen['status'] );
        $this->assertFalse( $frozen['streams']['pages']['enabled'] );
    }

    public function test_items(): void {
        $catalog = pz_fixture( 'items.catalog' );
        $result  = Contracts::items_response( $catalog['response'] );
        $this->assertSame( array( 'wc-product-88' ), $result['written'] );
        $this->assertSame( array( 'wc-product-89' ), array_keys( $result['rejected'] ) );
        $this->assertSame( 'knowledge.source_type_mismatch', $result['rejected']['wc-product-89']['code'] );
        $this->assertFalse( $result['deletes_busy'] );
        // The request is the record shape Content\ProductPayload builds.
        $record = $catalog['request']['upserts'][0];
        foreach ( array( 'id', 'fingerprint', 'title', 'description', 'categories', 'currency', 'attributes', 'images', 'links', 'variants' ) as $field ) {
            $this->assertArrayHasKey( $field, $record );
        }

        $files = pz_fixture( 'items.files' );
        $result = Contracts::items_response( $files['response'] );
        $this->assertSame( array( 'wp-page-2' ), $result['written'] );
        $this->assertSame( array( 'wp-page-3' ), array_keys( $result['rejected'] ) );
        $this->assertSame( 0, $result['deleted'], 'wp-page-7 was never held' );
        $this->assertSame( array( 'id', 'fingerprint', 'title', 'content', 'links', 'images' ), array_keys( $files['request']['upserts'][0] ) );

        $deferred = Contracts::items_response( pz_fixture( 'items.deferred' )['response'] );
        $this->assertSame( array( 'wp-page-4' ), $deferred['deferred'] );
        $this->assertSame( array(), $deferred['written'] );
    }

    public function test_closed_is_the_one_refusal(): void {
        $closed = pz_fixture( 'items.closed' );
        $this->assertSame( 409, $closed['status'] );
        $this->assertSame( Contracts::CLOSED, Contracts::problem_code( $closed['response'] ) );
        $this->assertStringContainsString( 'posts', Contracts::problem_message( $closed['response'], 409 ) );
    }

    public function test_reconcile(): void {
        $r = pz_fixture( 'reconcile' );
        $this->assertSame( array( 'generation', 'items' ), array_keys( $r['request'] ) );
        $this->assertSame( array( 'id', 'fingerprint' ), array_keys( $r['request']['items'][0] ) );
        $result = Contracts::reconcile_response( $r['response'] );
        $this->assertSame( 12, $result['generation'] );
        $this->assertSame( array( 'wc-product-90' ), $result['missing'] );
        $this->assertSame( array(), $result['stale'] );
        $this->assertFalse( $result['busy'] );

        $stale = pz_fixture( 'reconcile.stale_generation' );
        $this->assertSame( 409, $stale['status'] );
        $this->assertSame( Contracts::STALE_GENERATION, Contracts::problem_code( $stale['response'] ) );
    }

    public function test_disconnect_answers_no_content(): void {
        $this->assertSame( 204, pz_fixture( 'disconnect' )['status'] );
    }

    public function test_problem_message_prefers_what_the_owner_can_act_on(): void {
        $this->assertSame( 'Your connection key was rejected — reconnect this site.', Contracts::problem_message( array( 'title' => 'Unauthorized' ), 401 ) );
        $this->assertSame( 'title is required.', Contracts::problem_message( array( 'errors' => array( array( 'id' => 'x', 'message' => 'title is required.' ) ) ), 422 ) );
        // A 402 carries a bare string[] in errors — never index a string with 'message'.
        $this->assertSame( 'Plan full.', Contracts::problem_message( array( 'errors' => array( 'Plan full.' ), 'detail' => 'Plan full.' ), 402 ) );
        $this->assertSame( 'The PERSONAIZER API returned HTTP 502.', Contracts::problem_message( '<html>bad gateway</html>', 502 ) );
    }
}
