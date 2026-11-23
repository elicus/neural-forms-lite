<?php
namespace NeuralForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * ISO 3166-1 country list (1.3.0) for the Address field's country dropdown.
 *
 * Entries store the country NAME (readable in the inbox, emails, CSV and
 * AI without a lookup); the field's `settings.default_country` stores the
 * ISO code. Filter `neural_forms_countries` ( [ 'GB' => 'United Kingdom', … ] )
 * to rename, add or remove countries.
 */
class Countries {

	private const LIST = array(
		'AF' => 'Afghanistan', 'AX' => 'Åland Islands', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AS' => 'American Samoa',
		'AD' => 'Andorra', 'AO' => 'Angola', 'AI' => 'Anguilla', 'AQ' => 'Antarctica', 'AG' => 'Antigua and Barbuda',
		'AR' => 'Argentina', 'AM' => 'Armenia', 'AW' => 'Aruba', 'AU' => 'Australia', 'AT' => 'Austria',
		'AZ' => 'Azerbaijan', 'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados',
		'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BM' => 'Bermuda',
		'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BQ' => 'Bonaire, Sint Eustatius and Saba', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana',
		'BV' => 'Bouvet Island', 'BR' => 'Brazil', 'IO' => 'British Indian Ocean Territory', 'BN' => 'Brunei', 'BG' => 'Bulgaria',
		'BF' => 'Burkina Faso', 'BI' => 'Burundi', 'CV' => 'Cabo Verde', 'KH' => 'Cambodia', 'CM' => 'Cameroon',
		'CA' => 'Canada', 'KY' => 'Cayman Islands', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile',
		'CN' => 'China', 'CX' => 'Christmas Island', 'CC' => 'Cocos (Keeling) Islands', 'CO' => 'Colombia', 'KM' => 'Comoros',
		'CG' => 'Congo', 'CD' => 'Congo (Democratic Republic)', 'CK' => 'Cook Islands', 'CR' => 'Costa Rica', 'CI' => "Côte d'Ivoire",
		'HR' => 'Croatia', 'CU' => 'Cuba', 'CW' => 'Curaçao', 'CY' => 'Cyprus', 'CZ' => 'Czechia',
		'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador',
		'EG' => 'Egypt', 'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia',
		'SZ' => 'Eswatini', 'ET' => 'Ethiopia', 'FK' => 'Falkland Islands', 'FO' => 'Faroe Islands', 'FJ' => 'Fiji',
		'FI' => 'Finland', 'FR' => 'France', 'GF' => 'French Guiana', 'PF' => 'French Polynesia', 'TF' => 'French Southern Territories',
		'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana',
		'GI' => 'Gibraltar', 'GR' => 'Greece', 'GL' => 'Greenland', 'GD' => 'Grenada', 'GP' => 'Guadeloupe',
		'GU' => 'Guam', 'GT' => 'Guatemala', 'GG' => 'Guernsey', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau',
		'GY' => 'Guyana', 'HT' => 'Haiti', 'HM' => 'Heard Island and McDonald Islands', 'VA' => 'Holy See', 'HN' => 'Honduras',
		'HK' => 'Hong Kong', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia',
		'IR' => 'Iran', 'IQ' => 'Iraq', 'IE' => 'Ireland', 'IM' => 'Isle of Man', 'IL' => 'Israel',
		'IT' => 'Italy', 'JM' => 'Jamaica', 'JP' => 'Japan', 'JE' => 'Jersey', 'JO' => 'Jordan',
		'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati', 'KP' => 'Korea (North)', 'KR' => 'Korea (South)',
		'XK' => 'Kosovo', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => 'Laos', 'LV' => 'Latvia',
		'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein',
		'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MO' => 'Macao', 'MG' => 'Madagascar', 'MW' => 'Malawi',
		'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands',
		'MQ' => 'Martinique', 'MR' => 'Mauritania', 'MU' => 'Mauritius', 'YT' => 'Mayotte', 'MX' => 'Mexico',
		'FM' => 'Micronesia', 'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro',
		'MS' => 'Montserrat', 'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia',
		'NR' => 'Nauru', 'NP' => 'Nepal', 'NL' => 'Netherlands', 'NC' => 'New Caledonia', 'NZ' => 'New Zealand',
		'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'NU' => 'Niue', 'NF' => 'Norfolk Island',
		'MK' => 'North Macedonia', 'MP' => 'Northern Mariana Islands', 'NO' => 'Norway', 'OM' => 'Oman', 'PK' => 'Pakistan',
		'PW' => 'Palau', 'PS' => 'Palestine', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay',
		'PE' => 'Peru', 'PH' => 'Philippines', 'PN' => 'Pitcairn', 'PL' => 'Poland', 'PT' => 'Portugal',
		'PR' => 'Puerto Rico', 'QA' => 'Qatar', 'RE' => 'Réunion', 'RO' => 'Romania', 'RU' => 'Russia',
		'RW' => 'Rwanda', 'BL' => 'Saint Barthélemy', 'SH' => 'Saint Helena, Ascension and Tristan da Cunha', 'KN' => 'Saint Kitts and Nevis', 'LC' => 'Saint Lucia',
		'MF' => 'Saint Martin (French part)', 'PM' => 'Saint Pierre and Miquelon', 'VC' => 'Saint Vincent and the Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino',
		'ST' => 'Sao Tome and Principe', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles',
		'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SX' => 'Sint Maarten (Dutch part)', 'SK' => 'Slovakia', 'SI' => 'Slovenia',
		'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa', 'GS' => 'South Georgia and the South Sandwich Islands', 'SS' => 'South Sudan',
		'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname', 'SJ' => 'Svalbard and Jan Mayen',
		'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria', 'TW' => 'Taiwan', 'TJ' => 'Tajikistan',
		'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo', 'TK' => 'Tokelau',
		'TO' => 'Tonga', 'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Türkiye', 'TM' => 'Turkmenistan',
		'TC' => 'Turks and Caicos Islands', 'TV' => 'Tuvalu', 'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates',
		'GB' => 'United Kingdom', 'US' => 'United States', 'UM' => 'United States Minor Outlying Islands', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan',
		'VU' => 'Vanuatu', 'VE' => 'Venezuela', 'VN' => 'Vietnam', 'VG' => 'Virgin Islands (British)', 'VI' => 'Virgin Islands (U.S.)',
		'WF' => 'Wallis and Futuna', 'EH' => 'Western Sahara', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
	);

	/** [ 'GB' => 'United Kingdom', … ], sorted by name, filterable. */
	public static function all(): array {
		$list = function_exists( 'apply_filters' ) ? apply_filters( 'neural_forms_countries', self::LIST ) : self::LIST;
		$out  = array();
		foreach ( is_array( $list ) ? $list : self::LIST as $code => $name ) {
			$code = strtoupper( (string) $code );
			if ( preg_match( '/^[A-Z]{2}$/', $code ) && is_string( $name ) && trim( $name ) !== '' ) {
				$out[ $code ] = trim( $name );
			}
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		return $out;
	}

	/** True when $name is one of the listed country names (case-insensitive). */
	public static function is_known_name( string $name ): bool {
		return self::canonical_name( $name ) !== '';
	}

	/** The listed spelling of $name, or '' when it isn't a listed country. */
	public static function canonical_name( string $name ): string {
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $name ) ) : strtolower( trim( $name ) );
		if ( $needle === '' ) {
			return '';
		}
		foreach ( self::all() as $n ) {
			if ( ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $n ) : strtolower( $n ) ) === $needle ) {
				return $n;
			}
		}
		return '';
	}

	/**
	 * Name for a stored default: an ISO code ("GB"), or a name typed before
	 * the dropdown existed ("united kingdom"). '' when unknown.
	 */
	public static function resolve_default( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( $value === '' ) {
			return '';
		}
		$all = self::all();
		if ( preg_match( '/^[A-Za-z]{2}$/', $value ) && isset( $all[ strtoupper( $value ) ] ) ) {
			return $all[ strtoupper( $value ) ];
		}
		return self::canonical_name( $value );
	}
}
