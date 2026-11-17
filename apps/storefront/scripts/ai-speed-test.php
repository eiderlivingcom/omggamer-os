<?php
/**
 * AI Studio speed test: times one short-description request through OmniRoute with different settings.
 * Run: wp eval-file ai-speed-test.php <post_id>. Prints timings only (never the key).
 */
$pid = isset( $args[0] ) ? absint( $args[0] ) : 3484;
$s   = OMG_PS::settings();
$key = OMG_PS::api_key();
$base = $s['base_url'] ? rtrim( $s['base_url'], '/' ) : OMG_PS::OMNIROUTE;
$os  = OMG_PS_Sources::os( $pid );
$ctx = wp_json_encode( OMG_PS::context( $pid, 'digital', array(), array( 'os' => is_wp_error( $os ) ? array() : $os ) ) );
$msg = array(
	array( 'role' => 'system', 'content' => 'You write product copy. Reply with one JSON object only.' ),
	array( 'role' => 'user', 'content' => "PRODUCT DATA:\n" . $ctx . "\n\nReturn {\"short_description\": \"<p>20 to 40 words</p>\"}" ),
);
$runs = array(
	'free-stack default'          => array( 'model' => 'free-stack' ),
	'free-stack reasoning low'    => array( 'model' => 'free-stack', 'reasoning_effort' => 'low' ),
	'gemini-3.8-flash direct'     => array( 'model' => 'gemini/gemini-3.8-flash' ),
	'gemini-3.8-flash reason none' => array( 'model' => 'gemini/gemini-3.8-flash', 'reasoning_effort' => 'none' ),
);
echo 'prompt bytes: ' . strlen( $ctx ) . PHP_EOL;
foreach ( $runs as $label => $extra ) {
	$body = array_merge( array( 'messages' => $msg, 'max_tokens' => 800, 'stream' => true ), $extra );
	$t0   = microtime( true );
	$res  = wp_remote_post( $base . '/chat/completions', array( 'timeout' => 150, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
	$t    = microtime( true ) - $t0;
	if ( is_wp_error( $res ) ) {
		printf( "%-30s %6.1fs  ERROR %s\n", $label, $t, $res->get_error_message() );
		continue;
	}
	$raw  = wp_remote_retrieve_body( $res );
	$text = '';
	$model = '';
	foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
		if ( 0 === strpos( $line, 'data:' ) ) {
			$d = json_decode( trim( substr( $line, 5 ) ), true );
			$text .= $d['choices'][0]['delta']['content'] ?? '';
			$model = $d['model'] ?? $model;
		}
	}
	printf( "%-30s %6.1fs  HTTP %d  model=%s  chars=%d\n", $label, $t, wp_remote_retrieve_response_code( $res ), $model, strlen( $text ) );
	if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
		echo '   ' . substr( wp_strip_all_tags( $raw ), 0, 200 ) . PHP_EOL;
	}
}
