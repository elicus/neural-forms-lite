<?php
namespace NeuralForms\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Tiny arithmetic expression language for Calculated fields (1.2.0, part B).
 *
 *   {field_id}            value of a numeric field / product line total
 *   numbers               12  3.5
 *   + - * / ( )
 *   round(x, decimals)  min(a, b, …)  max(a, b, …)  if(cond, a, b)
 *   comparisons inside if(): < <= > >= == !=
 *
 * No variables other than field references, no strings, no eval. The
 * grammar is parsed with a shunting-yard pass into RPN once (parse())
 * and then evaluated with a value map (evaluate()). form-submit.js
 * carries the same grammar; tests/fixtures/pricing.json keeps them equal.
 */
final class Expression {

	const MAX_LENGTH = 500;
	const MAX_TOKENS = 200;

	private static $precedence = array(
		'==' => 1, '!=' => 1, '<' => 2, '<=' => 2, '>' => 2, '>=' => 2,
		'+'  => 3, '-' => 3, '*' => 4, '/' => 4, 'neg' => 5,
	);

	/**
	 * Parse to RPN tokens. Throws \InvalidArgumentException with a plain
	 * English message on any problem (FormValidator surfaces it).
	 *
	 * @return array{rpn: array, refs: string[]}
	 */
	public static function parse( string $expr ): array {
		$expr = trim( $expr );
		if ( $expr === '' ) {
			throw new \InvalidArgumentException( 'The expression is empty.' );
		}
		if ( strlen( $expr ) > self::MAX_LENGTH ) {
			throw new \InvalidArgumentException( 'The expression is too long.' );
		}
		$tokens = self::tokenize( $expr );
		if ( count( $tokens ) > self::MAX_TOKENS ) {
			throw new \InvalidArgumentException( 'The expression has too many parts.' );
		}

		$output = array();
		$ops    = array();
		$refs   = array();
		$prev   = null; // for unary minus detection
		$argc   = array(); // function arity stack

		foreach ( $tokens as $t ) {
			switch ( $t['type'] ) {
				case 'num':
					$output[] = $t;
					break;
				case 'ref':
					$output[]           = $t;
					$refs[ $t['value'] ] = true;
					break;
				case 'func':
					$ops[]  = $t;
					$argc[] = 1;
					break;
				case 'comma':
					while ( $ops && end( $ops )['type'] !== 'lparen' ) {
						$output[] = array_pop( $ops );
					}
					if ( ! $ops ) {
						throw new \InvalidArgumentException( 'A comma is outside a function call.' );
					}
					$argc[ count( $argc ) - 1 ]++;
					break;
				case 'op':
					$op = $t['value'];
					if ( $op === '-' && ( null === $prev || in_array( $prev['type'], array( 'op', 'lparen', 'comma' ), true ) ) ) {
						$op = 'neg';
					}
					if ( $op === '+' && ( null === $prev || in_array( $prev['type'], array( 'op', 'lparen', 'comma' ), true ) ) ) {
						break; // unary plus is a no-op
					}
					$p = self::$precedence[ $op ];
					while ( $ops ) {
						$top = end( $ops );
						if ( $top['type'] !== 'op' ) {
							break;
						}
						$tp = self::$precedence[ $top['value'] ];
						if ( $tp > $p || ( $tp === $p && $op !== 'neg' ) ) {
							$output[] = array_pop( $ops );
							continue;
						}
						break;
					}
					$ops[] = array( 'type' => 'op', 'value' => $op );
					break;
				case 'lparen':
					$ops[] = $t;
					break;
				case 'rparen':
					while ( $ops && end( $ops )['type'] !== 'lparen' ) {
						$output[] = array_pop( $ops );
					}
					if ( ! $ops ) {
						throw new \InvalidArgumentException( 'There is a closing bracket without an opening one.' );
					}
					array_pop( $ops ); // the lparen
					if ( $ops && end( $ops )['type'] === 'func' ) {
						$f          = array_pop( $ops );
						$n          = array_pop( $argc );
						$f['argc']  = $n;
						self::check_arity( $f['value'], $n );
						$output[]   = $f;
					}
					break;
			}
			$prev = $t;
		}
		while ( $ops ) {
			$top = array_pop( $ops );
			if ( $top['type'] === 'lparen' || $top['type'] === 'func' ) {
				throw new \InvalidArgumentException( 'A bracket was not closed.' );
			}
			$output[] = $top;
		}
		if ( ! $output ) {
			throw new \InvalidArgumentException( 'The expression is empty.' );
		}
		// Sanity: a well-formed RPN leaves exactly one value.
		self::evaluate_rpn( $output, static function () { return 1.0; }, true );
		return array( 'rpn' => $output, 'refs' => array_keys( $refs ) );
	}

	/**
	 * Evaluate parsed RPN. $resolve( $field_id ) → float|null (null = missing → 0).
	 * Division by zero → null (caller treats as "cannot compute").
	 *
	 * @param array    $rpn
	 * @param callable $resolve
	 * @return float|null
	 */
	public static function evaluate( array $rpn, callable $resolve ) {
		try {
			return self::evaluate_rpn( $rpn, $resolve, false );
		} catch ( \DivisionByZeroError $e ) {
			return null;
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/** Convenience: parse + evaluate in one go. */
	public static function compute( string $expr, callable $resolve ) {
		return self::evaluate( self::parse( $expr )['rpn'], $resolve );
	}

	// ── internals ────────────────────────────────────────────────────

	private static function tokenize( string $expr ): array {
		$tokens = array();
		$i      = 0;
		$len    = strlen( $expr );
		while ( $i < $len ) {
			$c = $expr[ $i ];
			if ( ctype_space( $c ) ) {
				$i++;
				continue;
			}
			if ( $c === '{' ) {
				$end = strpos( $expr, '}', $i );
				if ( false === $end ) {
					throw new \InvalidArgumentException( 'A field reference is missing its closing }.' );
				}
				$id = substr( $expr, $i + 1, $end - $i - 1 );
				if ( ! preg_match( '/^[a-z0-9_]+$/', $id ) ) {
					throw new \InvalidArgumentException( sprintf( 'Field reference "%s" is not a valid field id.', $id ) );
				}
				$tokens[] = array( 'type' => 'ref', 'value' => $id );
				$i        = $end + 1;
				continue;
			}
			if ( ctype_digit( $c ) || ( $c === '.' && $i + 1 < $len && ctype_digit( $expr[ $i + 1 ] ) ) ) {
				$j = $i;
				while ( $j < $len && ( ctype_digit( $expr[ $j ] ) || $expr[ $j ] === '.' ) ) {
					$j++;
				}
				$num = substr( $expr, $i, $j - $i );
				if ( substr_count( $num, '.' ) > 1 ) {
					throw new \InvalidArgumentException( sprintf( '"%s" is not a number.', $num ) );
				}
				$tokens[] = array( 'type' => 'num', 'value' => (float) $num );
				$i        = $j;
				continue;
			}
			if ( ctype_alpha( $c ) ) {
				$j = $i;
				while ( $j < $len && ctype_alpha( $expr[ $j ] ) ) {
					$j++;
				}
				$name = strtolower( substr( $expr, $i, $j - $i ) );
				if ( ! in_array( $name, array( 'round', 'min', 'max', 'if' ), true ) ) {
					throw new \InvalidArgumentException( sprintf( 'Unknown function "%s". Use round, min, max or if.', $name ) );
				}
				if ( $j >= $len || $expr[ $j ] !== '(' ) {
					throw new \InvalidArgumentException( sprintf( '"%s" must be followed by (.', $name ) );
				}
				$tokens[] = array( 'type' => 'func', 'value' => $name );
				$tokens[] = array( 'type' => 'lparen' );
				$i        = $j + 1;
				continue;
			}
			$two = substr( $expr, $i, 2 );
			if ( in_array( $two, array( '<=', '>=', '==', '!=' ), true ) ) {
				$tokens[] = array( 'type' => 'op', 'value' => $two );
				$i       += 2;
				continue;
			}
			if ( strpos( '+-*/<>', $c ) !== false ) {
				$tokens[] = array( 'type' => 'op', 'value' => $c );
				$i++;
				continue;
			}
			if ( $c === '(' ) {
				$tokens[] = array( 'type' => 'lparen' );
				$i++;
				continue;
			}
			if ( $c === ')' ) {
				$tokens[] = array( 'type' => 'rparen' );
				$i++;
				continue;
			}
			if ( $c === ',' ) {
				$tokens[] = array( 'type' => 'comma' );
				$i++;
				continue;
			}
			throw new \InvalidArgumentException( sprintf( 'Unexpected character "%s".', $c ) );
		}
		return $tokens;
	}

	private static function check_arity( string $fn, int $n ): void {
		if ( $fn === 'round' && ( $n < 1 || $n > 2 ) ) {
			throw new \InvalidArgumentException( 'round() takes a value and an optional number of decimals.' );
		}
		if ( ( $fn === 'min' || $fn === 'max' ) && $n < 1 ) {
			throw new \InvalidArgumentException( $fn . '() needs at least one value.' );
		}
		if ( $fn === 'if' && $n !== 3 ) {
			throw new \InvalidArgumentException( 'if() takes a condition, a value when true and a value when false.' );
		}
	}

	private static function evaluate_rpn( array $rpn, callable $resolve, bool $dry ) {
		$stack = array();
		$pop   = static function () use ( &$stack ) {
			if ( ! $stack ) {
				throw new \InvalidArgumentException( 'The expression is incomplete.' );
			}
			return array_pop( $stack );
		};
		foreach ( $rpn as $t ) {
			switch ( $t['type'] ) {
				case 'num':
					$stack[] = (float) $t['value'];
					break;
				case 'ref':
					$v       = $dry ? 1.0 : $resolve( $t['value'] );
					$stack[] = is_numeric( $v ) ? (float) $v : 0.0;
					break;
				case 'op':
					if ( $t['value'] === 'neg' ) {
						$stack[] = -$pop();
						break;
					}
					$b = $pop();
					$a = $pop();
					switch ( $t['value'] ) {
						case '+': $stack[] = $a + $b; break;
						case '-': $stack[] = $a - $b; break;
						case '*': $stack[] = $a * $b; break;
						case '/':
							if ( $dry ) { $stack[] = 1.0; break; }
							if ( $b == 0 ) { throw new \DivisionByZeroError( 'Division by zero' ); }
							$stack[] = $a / $b;
							break;
						case '<':  $stack[] = $a <  $b ? 1.0 : 0.0; break;
						case '<=': $stack[] = $a <= $b ? 1.0 : 0.0; break;
						case '>':  $stack[] = $a >  $b ? 1.0 : 0.0; break;
						case '>=': $stack[] = $a >= $b ? 1.0 : 0.0; break;
						case '==': $stack[] = abs( $a - $b ) < 1e-9 ? 1.0 : 0.0; break;
						case '!=': $stack[] = abs( $a - $b ) < 1e-9 ? 0.0 : 1.0; break;
					}
					break;
				case 'func':
					$n    = (int) ( $t['argc'] ?? 1 );
					$args = array();
					for ( $k = 0; $k < $n; $k++ ) {
						array_unshift( $args, $pop() );
					}
					switch ( $t['value'] ) {
						case 'round':
							$d       = isset( $args[1] ) ? (int) $args[1] : 0;
							$stack[] = round( $args[0], max( 0, min( 6, $d ) ) );
							break;
						case 'min': $stack[] = min( $args ); break;
						case 'max': $stack[] = max( $args ); break;
						case 'if':  $stack[] = $args[0] != 0 ? $args[1] : $args[2]; break;
					}
					break;
			}
		}
		if ( count( $stack ) !== 1 ) {
			throw new \InvalidArgumentException( 'The expression is incomplete.' );
		}
		return $stack[0];
	}
}
