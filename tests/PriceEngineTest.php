<?php
/**
 * Part B (1.2.0): Money, Expression and PriceEngine — PHP side of the
 * shared pricing fixtures (tests/fixtures/pricing.json).
 *
 *   php tests/PriceEngineTest.php
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	// phpcs:disable WordPress.Security -- CLI-only test runner.
	if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }
	if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value ) { return $value; } }
	if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v ); } }
	$plugin_root = dirname( __DIR__ );
	foreach ( array( '/modules/forms/ConditionEngine.php', '/modules/forms/SchemaHelper.php', '/modules/pricing/Money.php', '/modules/pricing/Expression.php', '/modules/pricing/PriceEngine.php' ) as $f ) {
		require_once $plugin_root . $f;
	}
}

namespace NeuralForms\Tests {

	use NeuralForms\Pricing\Expression;
	use NeuralForms\Pricing\Money;
	use NeuralForms\Pricing\PriceEngine;

	$passed = 0;
	$failed = 0;
	function check( string $label, $actual, $expected, &$passed, &$failed ): void {
		if ( $actual === $expected ) {
			echo "\033[32mPASS\033[0m  $label\n";
			$passed++;
		} else {
			echo "\033[31mFAIL\033[0m  $label\n";
			echo "       expected: " . var_export( $expected, true ) . "\n";
			echo "       actual:   " . var_export( $actual, true ) . "\n";
			$failed++;
		}
	}

	// ── Money ─────────────────────────────────────────────────────────
	check( 'money: "1,250.50" USD → 125050', Money::to_minor( '1,250.50', 'USD' ), 125050, $passed, $failed );
	check( 'money: "12.345" USD rounds half-up → 1235', Money::to_minor( '12.345', 'USD' ), 1235, $passed, $failed );
	check( 'money: "12.344" USD → 1234', Money::to_minor( '12.344', 'USD' ), 1234, $passed, $failed );
	check( 'money: "1005" JPY → 1005', Money::to_minor( '1005', 'JPY' ), 1005, $passed, $failed );
	check( 'money: "1.2345" KWD (3 dp) → 1235', Money::to_minor( '1.2345', 'KWD' ), 1235, $passed, $failed );
	check( 'money: garbage → null', Money::to_minor( 'ten', 'USD' ), null, $passed, $failed );
	check( 'money: empty → null', Money::to_minor( '', 'USD' ), null, $passed, $failed );
	check( 'money: to_decimal', Money::to_decimal( 125050, 'USD' ), '1250.50', $passed, $failed );
	check( 'money: to_decimal negative', Money::to_decimal( -5, 'USD' ), '-0.05', $passed, $failed );
	check( 'money: format USD', Money::format( 125050, 'USD' ), '$1,250.50', $passed, $failed );
	check( 'money: format JPY', Money::format( 1005, 'JPY' ), '¥1,005', $passed, $failed );
	check( 'money: format unknown code', Money::format( 100, 'XYZ' ), 'XYZ 1.00', $passed, $failed );
	check( 'money: percent_of half-up (1005 × 12.5 % = 125.625 → 126)', Money::percent_of( 1005, '12.5' ), 126, $passed, $failed );
	check( 'money: percent_of 130000 × 10 %', Money::percent_of( 130000, '10' ), 13000, $passed, $failed );
	check( 'money: basis points "12.5" → 1250', Money::percent_to_basis_points( '12.5' ), 1250, $passed, $failed );
	check( 'money: normalize_currency', Money::normalize_currency( ' eur ' ), 'EUR', $passed, $failed );
	check( 'money: bad currency → USD', Money::normalize_currency( 'dollars' ), 'USD', $passed, $failed );

	// ── Expression ────────────────────────────────────────────────────
	$vars = array( 'a' => 3, 'b' => 4, 'zero' => 0 );
	$r    = static function ( $id ) use ( $vars ) { return $vars[ $id ] ?? null; };
	check( 'expr: precedence 1 + 2 * 3', Expression::compute( '1 + 2 * 3', $r ), 7.0, $passed, $failed );
	check( 'expr: parentheses', Expression::compute( '(1 + 2) * 3', $r ), 9.0, $passed, $failed );
	check( 'expr: refs', Expression::compute( '{a} * {b} - 2', $r ), 10.0, $passed, $failed );
	check( 'expr: unary minus', Expression::compute( '-{a} + 10', $r ), 7.0, $passed, $failed );
	check( 'expr: round 2dp', Expression::compute( 'round(10 / 3, 2)', $r ), 3.33, $passed, $failed );
	check( 'expr: round default 0dp', Expression::compute( 'round(2.5)', $r ), 3.0, $passed, $failed );
	check( 'expr: min / max varargs', array( Expression::compute( 'min({a}, {b}, 1)', $r ), Expression::compute( 'max({a}, {b}, 1)', $r ) ), array( 1.0, 4.0 ), $passed, $failed );
	check( 'expr: if true branch', Expression::compute( 'if({a} < {b}, 100, 200)', $r ), 100.0, $passed, $failed );
	check( 'expr: if false branch with ==', Expression::compute( 'if({a} == 4, 1, 0)', $r ), 0.0, $passed, $failed );
	check( 'expr: missing ref is 0', Expression::compute( '{nope} + 1', $r ), 1.0, $passed, $failed );
	check( 'expr: division by zero → null', Expression::compute( '{a} / {zero}', $r ), null, $passed, $failed );
	check( 'expr: refs collected', Expression::parse( '{a} + {b} * {a}' )['refs'], array( 'a', 'b' ), $passed, $failed );
	foreach ( array( '', '1 +', '(1 + 2', '1 + 2)', 'foo(1)', '{Bad Id}', '1 ** 2', 'if(1, 2)', '1 , 2', '3.4.5' ) as $bad ) {
		$threw = false;
		try { Expression::parse( $bad ); } catch ( \InvalidArgumentException $e ) { $threw = true; }
		check( 'expr: rejects "' . $bad . '"', $threw, true, $passed, $failed );
	}
	$threw = false;
	try { Expression::parse( str_repeat( '1+', 400 ) . '1' ); } catch ( \InvalidArgumentException $e ) { $threw = true; }
	check( 'expr: rejects over-long', $threw, true, $passed, $failed );

	// ── PriceEngine fixtures ──────────────────────────────────────────
	$fixtures = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/pricing.json' ), true );
	foreach ( $fixtures['cases'] as $case ) {
		$snap = PriceEngine::evaluate( $case['form'], $case['data'], $case['hidden'] );
		$calc = array();
		foreach ( $snap['calculated'] as $id => $c ) {
			$calc[ $id ] = $c['value'] + 0; // normalise float/int for comparison
		}
		$actual = array(
			'subtotal'   => $snap['subtotal'],
			'discount'   => $snap['discount']['amount'] ?? 0,
			'total'      => $snap['total'],
			'due_now'    => $snap['due_now'],
			'remaining'  => $snap['remaining'],
			'lines'      => count( $snap['line_items'] ),
			'calculated' => $calc,
		);
		$expected = $case['expected'];
		$exp_calc = array();
		foreach ( $expected['calculated'] as $id => $v ) {
			$exp_calc[ $id ] = $v + 0;
		}
		$expected['calculated'] = $exp_calc;
		// loose numeric compare for calculated (float vs int)
		$ok = $actual['subtotal'] === $expected['subtotal'] && $actual['discount'] === $expected['discount'] && $actual['total'] === $expected['total']
			&& $actual['due_now'] === $expected['due_now'] && $actual['remaining'] === $expected['remaining'] && $actual['lines'] === $expected['lines']
			&& count( $actual['calculated'] ) === count( $expected['calculated'] );
		foreach ( $expected['calculated'] as $id => $v ) {
			if ( ! isset( $actual['calculated'][ $id ] ) || abs( $actual['calculated'][ $id ] - $v ) > 1e-6 ) {
				$ok = false;
			}
		}
		check( 'pricing: ' . $case['name'], $ok ? true : $actual, true, $passed, $failed );
		check( 'pricing: no engine errors — ' . $case['name'], $snap['errors'], array(), $passed, $failed );
	}

	// Emit the client config per fixture for tests/PriceEngineTest.js (parity).
	$client = array();
	foreach ( $fixtures['cases'] as $case ) {
		$client[] = array( 'name' => $case['name'], 'config' => PriceEngine::client_config( $case['form'] ) );
	}
	file_put_contents( __DIR__ . '/fixtures/pricing.client.json', json_encode( $client, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
	check( 'client config fixture written', file_exists( __DIR__ . '/fixtures/pricing.client.json' ), true, $passed, $failed );

	// ── snapshot details on the draft example ─────────────────────────
	$c0   = $fixtures['cases'][0];
	$snap = PriceEngine::evaluate( $c0['form'], $c0['data'] );
	check( 'snapshot: line labels', array_map( static fn( $l ) => $l['choice_label'] ?: $l['label'], $snap['line_items'] ), array( 'Service', 'SEO', 'Copywriting' ), $passed, $failed );
	check( 'snapshot: calculated display is formatted currency', $snap['calculated']['total_calc']['display'], '$1,300.00', $passed, $failed );
	check( 'snapshot: deposit block', $snap['deposit'], array( 'type' => 'percent', 'value' => '30', 'amount' => 35100 ), $passed, $failed );
	check( 'snapshot: formula revision is 12 hex chars', (bool) preg_match( '/^[0-9a-f]{12}$/', $snap['formula_revision'] ), true, $passed, $failed );
	$changed = $c0['form'];
	$changed['form_schema']['fields'][0]['pricing']['price'] = '1001.00';
	check( 'snapshot: price change → new revision', PriceEngine::formula_revision( $changed ) !== $snap['formula_revision'], true, $passed, $failed );
	$cfg = PriceEngine::client_config( $c0['form'] );
	check( 'client config: products + discounts + deposit in minor units', array( $cfg['products'][0]['price'], $cfg['products'][1]['options']['seo'], $cfg['discounts'][0]['bp'], $cfg['deposit']['bp'] ), array( 100000, 15000, 1000, 3000 ), $passed, $failed );

	// ── cycle detection ───────────────────────────────────────────────
	$cyc = array(
		array( 'id' => 'x', 'type' => 'calculated', 'expression' => '{y} + 1' ),
		array( 'id' => 'y', 'type' => 'calculated', 'expression' => '{z} * 2' ),
		array( 'id' => 'z', 'type' => 'calculated', 'expression' => '{x}' ),
		array( 'id' => 'ok', 'type' => 'calculated', 'expression' => '1 + 1' ),
	);
	check( 'cycle: x → y → z → x detected', PriceEngine::find_cycle( $cyc ), array( 'x', 'y', 'z', 'x' ), $passed, $failed );
	check( 'cycle: none in a chain', PriceEngine::find_cycle( array( array( 'id' => 'a', 'type' => 'calculated', 'expression' => '{b}' ), array( 'id' => 'b', 'type' => 'calculated', 'expression' => '2' ) ) ), array(), $passed, $failed );
	check( 'order: dependency before dependant', array_column( PriceEngine::calculated_in_order( array( array( 'id' => 'a', 'type' => 'calculated', 'expression' => '{b}' ), array( 'id' => 'b', 'type' => 'calculated', 'expression' => '2' ) ) ), 'id' ), array( 'b', 'a' ), $passed, $failed );

	echo "\n$passed passed, $failed failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
