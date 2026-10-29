<?php
/**
 * Buy button (1.2.0 part B): a compact buy card for ONE Priced Item of a
 * form that takes payments. Rendered by the Gutenberg block
 * `neural-forms/buy-button`, the Pro Divi module and the shortcode
 * `[neural-forms-buy form="1"]`. Submits to `POST /forms/{id}/buy`, which
 * stores an entry (product + email + name) and sends the visitor to the
 * hosted checkout — same engine, same authoritative totals as the form.
 */

namespace NeuralForms\Payments;

use NeuralForms\Core\Capabilities;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Pricing\Money;
use NeuralForms\Pricing\PriceEngine;

final class BuyButton {

	/** Attribute defaults shared by the block, the shortcode and the Divi module. */
	public static function defaults(): array {
		return array(
			'formId'        => 0,
			'fieldId'       => '',
			'choice'        => '',
			'quantity'      => 1,
			'allowQuantity' => true,
			'collectName'   => true,
			'label'         => '',
			'showPrice'     => true,
			'showTitle'     => true,
			'title'         => '',
			'description'   => '',
			'buttonColor'   => '',
			'buttonText'    => '',
			'width'         => 'auto',
			'align'         => 'left',
		);
	}

	/**
	 * Resolve what the card sells: the product field, the chosen option and
	 * the unit price. Returns null (with a reason) when it cannot be sold.
	 *
	 * @return array { ok, reason, form, field, option, unit_minor, currency, qty_min, qty_max, title }
	 */
	public static function resolve( array $attrs ) {
		$a       = array_merge( self::defaults(), $attrs );
		$form_id = (int) $a['formId'];
		$form    = $form_id > 0 ? FormRepository::find( $form_id ) : null;
		if ( ! $form || ( $form['status'] ?? 'active' ) !== 'active' ) {
			return array( 'ok' => false, 'reason' => 'no_form' );
		}
		if ( ! Capabilities::can( Capabilities::PAYMENTS ) || ! PaymentService::enabled_for( $form ) ) {
			return array( 'ok' => false, 'reason' => 'no_payments', 'form' => $form );
		}
		$fields  = SchemaHelper::flatten_fields( is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : array() );
		$product = null;
		foreach ( $fields as $f ) {
			if ( ( $f['type'] ?? '' ) !== 'product' ) {
				continue;
			}
			if ( $a['fieldId'] === '' || (string) ( $f['id'] ?? '' ) === (string) $a['fieldId'] ) {
				$product = $f;
				break;
			}
		}
		if ( ! $product ) {
			return array( 'ok' => false, 'reason' => 'no_product', 'form' => $form );
		}
		$pricing  = PriceEngine::product_pricing( $product );
		$currency = Money::normalize_currency( (string) ( PriceEngine::settings( $form )['currency'] ?? 'USD' ) );
		$option   = null;
		$unit     = null;
		$title    = (string) ( $product['label'] ?? '' );
		if ( $pricing['mode'] === 'options' ) {
			$options = is_array( $product['options'] ?? null ) ? $product['options'] : array();
			foreach ( $options as $o ) {
				if ( $a['choice'] === '' || (string) ( $o['value'] ?? '' ) === (string) $a['choice'] ) {
					$option = $o;
					break;
				}
			}
			if ( ! $option ) {
				return array( 'ok' => false, 'reason' => 'no_option', 'form' => $form, 'field' => $product );
			}
			$unit  = Money::to_minor( (string) ( $option['price'] ?? '0' ), $currency );
			$title = (string) ( $option['label'] ?? $option['value'] ?? $title );
		} elseif ( $pricing['mode'] === 'fixed' ) {
			$unit = Money::to_minor( (string) ( $pricing['price'] ?? '0' ), $currency );
		} else {
			return array( 'ok' => false, 'reason' => 'input_mode', 'form' => $form, 'field' => $product );
		}
		if ( null === $unit || $unit <= 0 ) {
			return array( 'ok' => false, 'reason' => 'no_price', 'form' => $form, 'field' => $product );
		}
		$q       = is_array( $pricing['quantity'] ?? null ) ? $pricing['quantity'] : array();
		$qty_on  = ! empty( $q['enabled'] );
		$qty_min = $qty_on ? max( 1, (int) ( $q['min'] ?? 1 ) ) : 1;
		$qty_max = $qty_on ? max( $qty_min, (int) ( $q['max'] ?? 10 ) ) : 1;
		return array(
			'ok'         => true,
			'form'       => $form,
			'field'      => $product,
			'option'     => $option,
			'unit_minor' => (int) $unit,
			'currency'   => $currency,
			'qty_min'    => $qty_min,
			'qty_max'    => $qty_max,
			'qty_on'     => $qty_on && ! empty( $a['allowQuantity'] ),
			'title'      => $a['title'] !== '' ? (string) $a['title'] : $title,
		);
	}

	/** HTML for the card. Empty string when nothing can be sold (admins get a notice). */
	public static function render( array $attrs, array $options = array() ): string {
		$a   = array_merge( self::defaults(), $attrs );
		$r   = self::resolve( $a );
		$esc = static function ( $s ) { return esc_html( (string) $s ); };
		if ( empty( $r['ok'] ) ) {
			if ( ! empty( $options['preview'] ) || current_user_can( 'edit_posts' ) ) {
				$msgs = array(
					'no_form'     => __( 'Buy button: choose a form that takes payments.', 'neural-forms-lite' ),
					'no_payments' => __( 'Buy button: this form does not take payments. Turn payments on under Form settings › Payments (Neural Forms Pro) and connect a provider under Integrations.', 'neural-forms-lite' ),
					'no_product'  => __( 'Buy button: the form has no Priced Item field.', 'neural-forms-lite' ),
					'no_option'   => __( 'Buy button: choose which option to sell.', 'neural-forms-lite' ),
					'input_mode'  => __( 'Buy button: this Priced Item lets the visitor enter an amount; use a fixed price or priced options.', 'neural-forms-lite' ),
					'no_price'    => __( 'Buy button: the selected item has no price.', 'neural-forms-lite' ),
				);
				return '<div class="neural-forms-buy neural-forms-buy--notice">' . $esc( $msgs[ $r['reason'] ] ?? $r['reason'] ) . '</div>';
			}
			return '';
		}
		if ( empty( $options['preview'] ) ) {
			if ( ! wp_script_is( 'neural-forms-buy', 'registered' ) && class_exists( '\\NeuralForms\\Frontend\\PublicForms' ) ) {
				\NeuralForms\Frontend\PublicForms::register_assets(); // rendered before wp_enqueue_scripts (widgets, REST, CLI)
			}
			wp_enqueue_style( 'neural-forms-form' );
			wp_enqueue_script( 'neural-forms-buy' );
		}
		$form     = $r['form'];
		$qty      = max( $r['qty_min'], min( $r['qty_max'], (int) $a['quantity'] ) );
		$total    = $r['unit_minor'] * $qty;
		$currency = $r['currency'];
		$label    = $a['label'] !== '' ? (string) $a['label'] : __( 'Buy now', 'neural-forms-lite' );
		$style    = '';
		if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', (string) $a['buttonColor'] ) ) {
			$style .= '--nf-buy-btn:' . $a['buttonColor'] . ';';
		}
		if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', (string) $a['buttonText'] ) ) {
			$style .= '--nf-buy-btn-text:' . $a['buttonText'] . ';';
		}
		$classes = 'neural-forms-buy neural-forms-buy--' . ( $a['width'] === 'full' ? 'full' : 'auto' ) . ' neural-forms-buy--align-' . ( in_array( $a['align'], array( 'left', 'center', 'right' ), true ) ? $a['align'] : 'left' );
		$uid     = 'nf-buy-' . (int) $form['id'] . '-' . substr( md5( wp_json_encode( $a ) ), 0, 6 );
		$h  = '<form class="' . esc_attr( $classes ) . '" id="' . esc_attr( $uid ) . '"' . ( $style !== '' ? ' style="' . esc_attr( $style ) . '"' : '' )
			. ' data-neural-forms-buy data-endpoint="' . esc_url( rest_url( 'neural-forms/v1/forms/' . (int) $form['id'] . '/buy' ) ) . '" data-unit="' . (int) $r['unit_minor'] . '" data-currency="' . esc_attr( $currency ) . '" data-exponent="' . (int) Money::exponent( $currency ) . '" data-label="' . esc_attr( $label ) . '" method="post" novalidate>';
		$h .= '<input type="hidden" name="field" value="' . esc_attr( (string) $r['field']['id'] ) . '">';
		$h .= '<input type="hidden" name="choice" value="' . esc_attr( (string) ( $r['option']['value'] ?? '' ) ) . '">';
		$h .= '<input type="text" name="neural_forms_website" class="neural-forms-buy__hp" tabindex="-1" autocomplete="off" aria-hidden="true">';
		if ( ! empty( $a['showTitle'] ) || ! empty( $a['showPrice'] ) ) {
			$h .= '<div class="neural-forms-buy__head">';
			if ( ! empty( $a['showTitle'] ) ) {
				$h .= '<div class="neural-forms-buy__title">' . $esc( $r['title'] ) . '</div>';
			}
			if ( ! empty( $a['showPrice'] ) ) {
				$h .= '<div class="neural-forms-buy__price" data-buy-price>' . $esc( Money::format( $r['unit_minor'], $currency ) ) . ( $r['qty_on'] ? '<small>' . $esc( __( 'each', 'neural-forms-lite' ) ) . '</small>' : '' ) . '</div>';
			}
			$h .= '</div>';
		}
		if ( (string) $a['description'] !== '' ) {
			$h .= '<p class="neural-forms-buy__desc">' . $esc( $a['description'] ) . '</p>';
		}
		$h .= '<div class="neural-forms-buy__fields">';
		if ( $r['qty_on'] ) {
			$h .= '<label class="neural-forms-buy__field neural-forms-buy__field--qty"><span>' . $esc( __( 'Quantity', 'neural-forms-lite' ) ) . '</span><input type="number" name="qty" inputmode="numeric" min="' . (int) $r['qty_min'] . '" max="' . (int) $r['qty_max'] . '" value="' . (int) $qty . '" required></label>';
		} else {
			$h .= '<input type="hidden" name="qty" value="' . (int) $qty . '">';
		}
		if ( ! empty( $a['collectName'] ) ) {
			$h .= '<label class="neural-forms-buy__field"><span>' . $esc( __( 'Your name', 'neural-forms-lite' ) ) . '</span><input type="text" name="full_name" autocomplete="name" required></label>';
		}
		$h .= '<label class="neural-forms-buy__field"><span>' . $esc( __( 'Email', 'neural-forms-lite' ) ) . '</span><input type="email" name="email" autocomplete="email" required></label>';
		$h .= '</div>';
		$h .= '<button type="submit" class="neural-forms-buy__btn"><span class="neural-forms-buy__btn-label">' . $esc( $label ) . '</span>' . ( ! empty( $a['showPrice'] ) ? ' <span class="neural-forms-buy__btn-total" data-buy-total>· ' . $esc( Money::format( $total, $currency ) ) . '</span>' : '' ) . '</button>';
		$h .= '<div class="neural-forms-buy__msg" role="status" aria-live="polite"></div>';
		$h .= '<p class="neural-forms-buy__secure">' . $esc( __( 'Secure checkout. No card details are entered on this site.', 'neural-forms-lite' ) ) . '</p>';
		$h .= '</form>';
		return $h;
	}

	/** [neural-forms-buy form="1" field="book" choice="" quantity="1" label="Buy now"] */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array( 'form' => 0, 'field' => '', 'choice' => '', 'quantity' => 1, 'label' => '', 'title' => '', 'description' => '', 'name' => '1', 'price' => '1', 'width' => 'auto', 'align' => 'left' ),
			is_array( $atts ) ? $atts : array(),
			'neural-forms-buy'
		);
		return self::render(
			array(
				'formId'      => (int) $atts['form'],
				'fieldId'     => (string) $atts['field'],
				'choice'      => (string) $atts['choice'],
				'quantity'    => (int) $atts['quantity'],
				'label'       => (string) $atts['label'],
				'title'       => (string) $atts['title'],
				'description' => (string) $atts['description'],
				'collectName' => $atts['name'] !== '0',
				'showPrice'   => $atts['price'] !== '0',
				'width'       => (string) $atts['width'],
				'align'       => (string) $atts['align'],
			)
		);
	}

	/** Forms + items the editors can offer (block inspector, Divi settings). */
	public static function catalogue(): array {
		$out = array();
		foreach ( FormRepository::list( array( 'status' => 'active', 'per_page' => 100, 'page' => 1, 'orderby' => 'name', 'order' => 'ASC' ) )['items'] ?? array() as $form ) {
			$fields   = SchemaHelper::flatten_fields( is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : array() );
			$products = array();
			foreach ( $fields as $f ) {
				if ( ( $f['type'] ?? '' ) !== 'product' ) {
					continue;
				}
				$p = PriceEngine::product_pricing( $f );
				if ( $p['mode'] === 'input' ) {
					continue;
				}
				$products[] = array(
					'id'       => (string) $f['id'],
					'label'    => (string) ( $f['label'] ?? $f['id'] ),
					'mode'     => $p['mode'],
					'price'    => (string) ( $p['price'] ?? '' ),
					'quantity' => ! empty( $p['quantity']['enabled'] ),
					'options'  => $p['mode'] === 'options' ? array_values( array_map( static function ( $o ) { return array( 'value' => (string) ( $o['value'] ?? '' ), 'label' => (string) ( $o['label'] ?? '' ), 'price' => (string) ( $o['price'] ?? '' ) ); }, is_array( $f['options'] ?? null ) ? $f['options'] : array() ) ) : array(),
				);
			}
			if ( ! $products ) {
				continue;
			}
			$out[] = array( 'id' => (int) $form['id'], 'name' => (string) $form['name'], 'payments' => PaymentService::enabled_for( $form ), 'currency' => (string) ( PriceEngine::settings( $form )['currency'] ?? 'USD' ), 'products' => $products );
		}
		return $out;
	}
}
