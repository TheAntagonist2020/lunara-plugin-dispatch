<?php
/**
 * Runtime contract: the Dispatch fallback prompt agrees with the Journal voice
 * the Control Plane compiles, and typographic punctuation is folded to ASCII
 * before sections are split into drafts.
 *
 * Run: php tests/journal-voice-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );

function jv_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
function get_option( $key, $default = false ) { return $default; }
function wp_strip_all_tags( $value ) { return trim( strip_tags( (string) $value ) ); }

require_once $root . '/includes/class-prompts.php';
require_once $root . '/includes/class-post-builder.php';

/* Fallback system prompt: only runs when Foundation is absent, but it must not contradict the compiled voice. */
$system = Lunara_Dispatch_Prompts::legacy_system_prompt();
foreach ( array( 'First person is allowed', 'talk, not essay', 'engagement question', 'X Turns Y Into Z', '"as we know"', '"the takeaway is simple"', 'LANDING AND CLOSE:' ) as $needle ) {
	jv_assert( false !== strpos( $system, $needle ), "Fallback system prompt is missing: {$needle}" );
}
jv_assert( false === stripos( $system, 'Do not force a question' ), 'Fallback system prompt still carries the anti-question rule.' );
jv_assert( false === stripos( $system, 'A question is allowed only when' ), 'Fallback system prompt still treats the close question as optional.' );

$directive = Lunara_Dispatch_Prompts::legacy_user_directive_prompt();
jv_assert( false !== strpos( $directive, 'engagement question' ), 'Fallback user directive must require the per-entry engagement question.' );
jv_assert( false !== strpos( $directive, 'First person is allowed' ), 'Fallback user directive must permit first person.' );
jv_assert( false === stripos( $directive, 'A question is optional' ), 'Fallback user directive still treats the close question as optional.' );
jv_assert( substr( rtrim( $directive ), -16 ) === 'Input News Data:', 'Fallback user directive must still end at the news-data boundary.' );

/* Typographic punctuation folds to the ASCII forms the Journal publishes. */
$input  = "<h3>Diesel\xE2\x80\x99s \xE2\x80\x9CDirector\xE2\x80\xA6 Hmmm\xE2\x80\xA6\xE2\x80\x9D post</h3><p>It\xE2\x80\x99s a tease \xE2\x80\x94 or a threat \xE2\x80\x93 either way&nbsp;&rsquo;fine&rsquo; &mdash; Almod\xC3\xB3var stays.</p>";
$output = Lunara_Dispatch_Post_Builder::normalize_typographic_punctuation( $input );
jv_assert( '<h3>Diesel\'s "Director... Hmmm..." post</h3><p>It\'s a tease -- or a threat - either way \'fine\' -- Almod' . "\xC3\xB3" . 'var stays.</p>' === $output, 'Punctuation normalization produced: ' . $output );
jv_assert( preg_match( '/^[\x09\x0A\x0D\x20-\x7E]*$/', str_replace( "\xC3\xB3", 'o', $output ) ) === 1, 'Everything except the accented name must be ASCII after normalization.' );
jv_assert( 'plain ascii stays' === Lunara_Dispatch_Post_Builder::normalize_typographic_punctuation( 'plain ascii stays' ), 'ASCII input must pass through untouched.' );

/* The split path runs the normalizer before it derives titles. */
$builder_source = file_get_contents( $root . '/includes/class-post-builder.php' );
$split_position = strpos( $builder_source, 'public function split_into_individual_posts(' );
$normalize_position = strpos( $builder_source, 'self::normalize_typographic_punctuation( $html )', $split_position );
$extract_position = strpos( $builder_source, '$this->extract_sections_with_body( $html )', $split_position );
jv_assert( false !== $normalize_position && false !== $extract_position && $normalize_position < $extract_position, 'split_into_individual_posts must normalize punctuation before extracting sections.' );

echo "Journal voice runtime passed.\n";
