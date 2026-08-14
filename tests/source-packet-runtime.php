<?php
/**
 * Runtime contract for no-AI source-packet drafts.
 * Run: php tests/source-packet-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );

function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function get_bloginfo() { return 'UTF-8'; }
function wp_trim_words( $text, $count, $more = null ) {
	$words = preg_split( '/\s+/', trim( (string) $text ) );
	return count( $words ) > $count ? implode( ' ', array_slice( $words, 0, $count ) ) . $more : (string) $text;
}
function esc_url_raw( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
function esc_url( $value ) { return esc_url_raw( $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }

require_once $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-source-packet-builder.php';
require_once $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-post-builder.php';

$items = array(
	array(
		'title' => 'A Director Takes the Studio Fight Public',
		'url' => 'https://example.com/story-one',
		'source_label' => 'Example Trade',
		'published_at' => '2026-08-14 10:00:00',
		'description' => 'The filmmaker challenged the studio account and reframed the dispute around ownership, leverage, and who gets to define success.',
	),
	array(
		'title' => 'The Sequel Deal Has Become the Story',
		'url' => 'https://example.com/story-two',
		'source_label' => 'Example Daily',
		'published_at' => '',
		'description' => '',
	),
);

$html = Lunara_Dispatch_Source_Packet_Builder::build_html( $items );
if ( 2 !== substr_count( $html, '<h2>' ) || 2 !== substr_count( $html, Lunara_Dispatch_Source_Packet_Builder::MARKER ) ) {
	fwrite( STDERR, "Source packet did not preserve one marked section per item.\n" );
	exit( 1 );
}
if ( 8 !== substr_count( $html, '<!-- wp:paragraph -->' ) ) {
	fwrite( STDERR, "Source packets must contain exactly four editable paragraph blocks each.\n" );
	exit( 1 );
}
foreach ( array( 'https://example.com/story-one', 'https://example.com/story-two' ) as $url ) {
	if ( false === strpos( $html, $url ) ) {
		fwrite( STDERR, "Source packet lost original provenance URL.\n" );
		exit( 1 );
	}
}

$post_builder = new Lunara_Dispatch_Post_Builder();
$sections = $post_builder->extract_h2_sections_with_body( $html );
$validator = new ReflectionMethod( Lunara_Dispatch_Post_Builder::class, 'source_packet_section_failure' );
$validator->setAccessible( true );
if ( 2 !== count( $sections ) ) {
	fwrite( STDERR, "Post builder could not split source packets.\n" );
	exit( 1 );
}
foreach ( $sections as $section ) {
	if ( '' !== $validator->invoke( $post_builder, $section['title'], $section['body'] ) ) {
		fwrite( STDERR, "Valid source packet failed the narrow fallback contract.\n" );
		exit( 1 );
	}
}

$broken = str_replace( Lunara_Dispatch_Source_Packet_Builder::MARKER, '', $sections[0]['body'] );
if ( 'source packet marker missing' !== $validator->invoke( $post_builder, $sections[0]['title'], $broken ) ) {
	fwrite( STDERR, "Unmarked content could bypass the editorial quality gate.\n" );
	exit( 1 );
}

echo "Source packet runtime passed.\n";
