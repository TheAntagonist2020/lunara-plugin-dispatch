<?php
/**
 * Runtime contract for the model-written Journal deck (Dispatch 3.2.8).
 *
 * The prompt asks for <!-- LUNARA_DECK: ... --> directly after each <h3>.
 * Dispatch must lift it out of the body, hand it to Journal Foundation as the
 * deck, and fall back to the body excerpt (the pre-3.2.8 behavior) whenever
 * the model omits it or simply repeats the headline or the opening sentence.
 * Run: php tests/dispatch-deck-tease-runtime.php
 */

$root = dirname( __DIR__ );
$failures = array();

define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
define( 'LUNARA_DISPATCH_VERSION', '3.2.8' );

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code = '', $message = '' ) {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function esc_url_raw( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_kses_post( $value ) { return (string) $value; }
function current_time( $type, $gmt = false ) { unset( $gmt ); return 'mysql' === $type ? '2026-09-02 12:00:00' : 0; }
function get_bloginfo( $field ) { return 'charset' === $field ? 'UTF-8' : ''; }
function get_option( $key, $default = false ) { unset( $key ); return $default; }

function deck_assert( $condition, $message ) {
    global $failures;
    if ( ! $condition ) {
        $failures[] = $message;
    }
}

require_once $root . '/includes/class-journal-ingest-bridge.php';
require_once $root . '/includes/class-post-builder.php';
require_once $root . '/includes/class-prompts.php';

$builder = new Lunara_Dispatch_Post_Builder();

// --- 1. The current <hr> format: the deck comment is lifted out of each body.
$first_deck  = 'Starfleet has always been the franchise\'s conscience. This pitch wants it to be the suspect, and that changes what a Star Trek movie is even for.';
$second_deck = 'The mask is the marketing. What Marvel is refusing to show says more about its confidence than anything it has revealed.';
$html = '<h3>Star Trek Wants Starfleet To Be The Problem</h3>' . "\n"
    . '<!-- LUNARA_DECK: ' . $first_deck . ' -->' . "\n"
    . '<p>Goldstein and Daley are not pitching a continuity puzzle. They are pitching a power story about the institution itself.</p>' . "\n"
    . '<p>That is the sharpest part of the pitch, and it is why the project sounds like a provocation rather than a reboot. The comparison points were <em>Training Day</em> and <em>Crimson Tide</em>.</p>' . "\n"
    . '<hr>' . "\n"
    . '<h3>The New Doomsday Trailer Still Will Not Show The Face</h3>' . "\n"
    . '<!--LUNARA_DECK:' . $second_deck . '-->' . "\n"
    . '<p>Trailer two. Mask stays on. That restraint is the smartest thing the campaign has done.</p>' . "\n"
    . '<p>Four months out, the studio is betting that withholding beats revealing, and the numbers so far agree.</p>';

$sections = $builder->extract_sections_with_body( $html );
deck_assert( 2 === count( $sections ), 'Two <hr> sections must be split.' );
deck_assert( isset( $sections[0]['deck'] ) && $first_deck === $sections[0]['deck'], 'The first deck must be lifted verbatim from its comment.' );
deck_assert( isset( $sections[1]['deck'] ) && $second_deck === $sections[1]['deck'], 'A deck comment without inner spaces must still be lifted.' );
deck_assert( 'Star Trek Wants Starfleet To Be The Problem' === $sections[0]['title'], 'The <h3> must still become the title.' );
foreach ( $sections as $index => $section ) {
    deck_assert( false === stripos( $section['body'], 'LUNARA_DECK' ), 'Section ' . $index . ' body must not retain the deck comment.' );
    deck_assert( 0 === strpos( $section['body'], '<p>' ), 'Section ' . $index . ' body must begin with its first paragraph after the deck is removed.' );
    deck_assert( 2 === substr_count( $section['body'], '<p>' ), 'Section ' . $index . ' must keep both body paragraphs.' );
}

// --- 2. A section with no deck comment yields an empty deck and an untouched body.
$plain = $builder->extract_sections_with_body( '<h3>Plain Entry Headline Here</h3><p>First paragraph of a plain entry.</p><p>Second paragraph of a plain entry.</p><hr>' );
deck_assert( 1 === count( $plain ) && '' === $plain[0]['deck'], 'An entry without a deck comment must report an empty deck.' );
deck_assert( '<p>First paragraph of a plain entry.</p><p>Second paragraph of a plain entry.</p>' === $plain[0]['body'], 'A body without a deck comment must be returned unchanged.' );

// --- 3. The legacy <h2> format is handled the same way.
$legacy = $builder->extract_sections_with_body( '<h2>Legacy Headline</h2><!-- LUNARA_DECK: A legacy-format deck that still needs to be lifted out of the body. --><p>Legacy body paragraph one.</p><p>Legacy body paragraph two.</p>' );
deck_assert( 1 === count( $legacy ) && 'A legacy-format deck that still needs to be lifted out of the body.' === $legacy[0]['deck'], 'Legacy <h2> sections must also surface the deck.' );
deck_assert( false === stripos( $legacy[0]['body'], 'LUNARA_DECK' ), 'Legacy <h2> bodies must not retain the deck comment.' );

// --- 4. The bridge carries a real deck through as the deck and keeps the excerpt as the body excerpt.
$context = array( 'run_id' => 'run-deck', 'provider' => 'openai', 'model' => 'gpt-test', 'section' => 'Signal', 'item_type' => 'signal', 'items' => array() );
$title   = $sections[0]['title'];
$body    = $sections[0]['body'];
$payload = Lunara_Dispatch_Journal_Ingest_Bridge::build_payload( $title, $body, $context, 0, $sections[0]['deck'] );
deck_assert( $first_deck === $payload['deck'], 'A valid model deck must be passed through as the payload deck.' );
deck_assert( $first_deck === $payload['acf']['journal_deck'], 'A valid model deck must be written to journal_deck.' );
deck_assert( 0 === strpos( $payload['excerpt'], 'Goldstein and Daley are not pitching' ), 'The WordPress excerpt must still come from the body.' );
deck_assert( $payload['deck'] !== $payload['excerpt'], 'The deck must no longer be the body excerpt when the model wrote one.' );
deck_assert( 0 === strpos( $payload['seo_description'], 'Goldstein and Daley' ), 'The SEO description must still come from the body.' );

// --- 5. Fallbacks reproduce the pre-3.2.8 behavior exactly: deck equals the 260-character body excerpt.
$legacy_payload = Lunara_Dispatch_Journal_Ingest_Bridge::build_payload( $title, $body, $context, 0 );
deck_assert( $legacy_payload['deck'] === $legacy_payload['excerpt'], 'With no deck argument the deck must equal the body excerpt.' );
deck_assert( $legacy_payload['acf']['journal_deck'] === $legacy_payload['excerpt'], 'With no deck argument journal_deck must equal the body excerpt.' );

$cases = array(
    'empty'          => '',
    'whitespace'     => "  \n ",
    'too short'      => 'Too short.',
    'too long'       => str_repeat( 'A deck that never ends and keeps going past any reasonable hero length. ', 7 ),
    'repeats title'  => 'Star Trek Wants Starfleet To Be The Problem',
    'repeats title, punctuation differs' => 'Star Trek wants Starfleet to be the problem!',
    'repeats opener' => 'Goldstein and Daley are not pitching a continuity puzzle. They are pitching a power story about the institution itself.',
    'paraphrase that begins with the opener' => 'Goldstein and Daley are not pitching a continuity puzzle, and that is the whole point of this one.',
    'opener with different punctuation and case' => 'GOLDSTEIN AND DALEY ARE NOT PITCHING -- a continuity puzzle, apparently.',
);
foreach ( $cases as $label => $bad_deck ) {
    $fallback = Lunara_Dispatch_Journal_Ingest_Bridge::build_payload( $title, $body, $context, 0, $bad_deck );
    deck_assert( $fallback['deck'] === $fallback['excerpt'], 'A deck that is ' . $label . ' must fall back to the body excerpt.' );
    deck_assert( $fallback['acf']['journal_deck'] === $fallback['excerpt'], 'journal_deck must follow the fallback when the deck is ' . $label . '.' );
}

// --- 5b. Sharing a couple of opening words is not a repeat; the tease survives.
$near = Lunara_Dispatch_Journal_Ingest_Bridge::build_payload( $title, $body, $context, 0, 'Goldstein and Daley want a Federation you cannot trust, and that is the most interesting thing anyone has said about Star Trek in a decade.' );
deck_assert( 0 === strpos( $near['deck'], 'Goldstein and Daley want a Federation' ), 'A deck that shares only the first few words with the body must survive as a deck.' );

// --- 6. A deck that arrives with markup or entities is flattened to plain text.
$marked = Lunara_Dispatch_Journal_Ingest_Bridge::build_payload( $title, $body, $context, 0, '<em>Training Day</em> in space &amp; a Federation that finally looks guilty of something worth a movie.' );
deck_assert( 'Training Day in space & a Federation that finally looks guilty of something worth a movie.' === $marked['deck'], 'Markup and entities in a deck must be flattened to plain text.' );

// --- 7. Both fallback prompts ask for the deck, so a run without the Control Plane still produces one.
$system = Lunara_Dispatch_Prompts::legacy_system_prompt();
$user   = Lunara_Dispatch_Prompts::legacy_user_directive_prompt();
deck_assert( false !== strpos( $system, '<!-- LUNARA_DECK: ... -->' ), 'The fallback system prompt must ask for the deck comment.' );
deck_assert( false !== strpos( $system, 'must not repeat the <h3>' ), 'The fallback system prompt must forbid repeating the headline in the deck.' );
deck_assert( false !== strpos( $system, 'first sentence of the body' ), 'The fallback system prompt must forbid repeating the opening sentence in the deck.' );
deck_assert( false !== strpos( $user, 'LUNARA_DECK' ), 'The fallback user directive must ask for the deck comment.' );
deck_assert( strpos( $system, 'LUNARA_DECK' ) > strpos( $system, 'Start every entry with its own original <h3> headline' ), 'The deck instruction must follow the headline instruction.' );

if ( ! empty( $failures ) ) {
    fwrite( STDERR, "Dispatch deck tease runtime FAILED:\n - " . implode( "\n - ", $failures ) . "\n" );
    exit( 1 );
}
echo "Dispatch deck tease runtime passed: decks are lifted from the model output, carried to journal_deck, and fall back to the body excerpt when missing or repetitive.\n";
