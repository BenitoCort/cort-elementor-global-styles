<?php

namespace Cort\ElementorGlobalStyles;

defined( 'ABSPATH' ) || exit;

final class Style {

	const TYPE_FIELDS = [
		'font_family' => 'font',
		'font_size' => 'slider',
		'font_weight' => 'weight',
		'line_height' => 'slider',
		'letter_spacing' => 'slider',
		'word_spacing' => 'slider',
		'text_transform' => 'transform',
		'font_style' => 'font_style',
		'text_decoration' => 'decoration',
	];
	const DEVICES = [ '', '_tablet', '_mobile', '_widescreen', '_laptop', '_tablet_extra', '_mobile_extra' ];
	const UNITS = [ 'px', '%', 'em', 'rem', 'vw', 'vh', 'vmin', 'vmax', 'ch', 'lh' ];

	public static function color( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/iD', $value, $m ) ) {
			$hex = strtoupper( $m[1] );
			if ( strlen( $hex ) < 5 ) {
				$hex = preg_replace( '/./', '$0$0', $hex );
			}
			return '#' . ( strlen( $hex ) === 8 && substr( $hex, -2 ) === 'FF' ? substr( $hex, 0, 6 ) : $hex );
		}
		if ( strtolower( $value ) === 'transparent' ) {
			return 'transparent';
		}
		if ( ! preg_match( '/^(rgb|rgba)\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*(0(?:\.\d+)?|1(?:\.0+)?|\.\d+))?\s*\)$/iD', $value, $m ) ) {
			return null;
		}
		$r = (int) $m[2];
		$g = (int) $m[3];
		$b = (int) $m[4];
		if ( max( $r, $g, $b ) > 255 || ( strtolower( $m[1] ) === 'rgba' ) !== isset( $m[5] ) ) {
			return null;
		}
		$alpha = isset( $m[5] ) ? (float) $m[5] : 1.0;
		return $alpha === 1.0 ? sprintf( '#%02X%02X%02X', $r, $g, $b ) : "rgba($r, $g, $b, $alpha)";
	}

	private static function number( $value ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) || ! preg_match( '/^-?(?:\d+(?:\.\d+)?|\.\d+)$/D', (string) $value ) ) {
			return null;
		}
		$number = (float) $value;
		if ( abs( $number ) > 20000 ) {
			return null;
		}
		return floor( $number ) === $number ? (int) $number : $number;
	}

	private static function dimensions( $value, array $keys, bool $negative ): ?array {
		if ( ! is_array( $value ) || ! in_array( $value['unit'] ?? '', self::UNITS, true ) ) {
			return null;
		}
		$result = [ 'unit' => $value['unit'] ];
		foreach ( $keys as $key ) {
			$number = self::number( $value[ $key ] ?? null );
			if ( $number === null || ( ! $negative && $number < 0 ) ) {
				return null;
			}
			$result[ $key ] = (string) $number;
		}
		$result['isLinked'] = count( array_unique( array_values( array_intersect_key( $result, array_flip( $keys ) ) ) ) ) === 1;
		return $result;
	}

	public static function value( string $type, $value, bool $negative = false ) {
		switch ( $type ) {
			case 'color':
				return self::color( $value );
			case 'font':
				if ( ! is_string( $value ) ) {
					return null;
				}
				$value = trim( $value );
				return preg_match( "/^[\\p{L}\\p{N} .,'_-]{1,100}$/uD", $value ) ? $value : null;
			case 'weight':
				return is_scalar( $value ) && in_array( (string) $value, [ '100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold' ], true ) ? (string) $value : null;
			case 'slider':
				if ( ! is_array( $value ) || ! in_array( $value['unit'] ?? '', self::UNITS, true ) ) {
					return null;
				}
				$number = self::number( $value['size'] ?? null );
				return $number !== null && ( $negative || $number >= 0 ) ? [ 'unit' => $value['unit'], 'size' => $number, 'sizes' => [] ] : null;
			case 'dimensions':
				return self::dimensions( $value, [ 'top', 'right', 'bottom', 'left' ], $negative );
			case 'gaps':
				return self::dimensions( $value, [ 'row', 'column' ], false );
			case 'border':
				return in_array( $value, [ 'none', 'solid', 'double', 'dotted', 'dashed', 'groove', 'ridge' ], true ) ? $value : null;
			case 'transform':
				return in_array( $value, [ 'none', 'uppercase', 'lowercase', 'capitalize', 'normal' ], true ) ? $value : null;
			case 'font_style':
				return in_array( $value, [ 'normal', 'italic', 'oblique' ], true ) ? $value : null;
			case 'decoration':
				return in_array( $value, [ 'none', 'underline', 'overline', 'line-through' ], true ) ? $value : null;
			case 'shadow':
				if ( ! is_array( $value ) ) {
					return null;
				}
				$shadow = [];
				foreach ( [ 'horizontal', 'vertical', 'blur', 'spread' ] as $key ) {
					$shadow[ $key ] = self::number( $value[ $key ] ?? null );
					if ( $shadow[ $key ] === null || ( $key === 'blur' && $shadow[ $key ] < 0 ) ) {
						return null;
					}
				}
				$shadow['color'] = self::color( $value['color'] ?? null );
				return $shadow['color'] === null ? null : $shadow;
			case 'typography_switch':
				return $value === 'custom' ? $value : null;
			case 'classic':
				return $value === 'classic' ? $value : null;
			case 'toggle':
				return in_array( $value, [ 'yes', '' ], true ) ? $value : null;
			case 'position':
				return in_array( $value, [ ' ', 'inset' ], true ) ? $value : null;
			case 'layout':
				return in_array( $value, [ 'default', 'elementor_header_footer', 'elementor_canvas' ], true ) ? $value : null;
		}
		return null;
	}

	public static function form_value( string $type, $input, string $key ) {
		if ( ! is_string( $input ) || strlen( $input ) > 180 ) {
			return null;
		}
		$input = trim( $input );
		$parts = preg_split( '/\s+/', $input );
		if ( in_array( $type, [ 'slider', 'dimensions', 'gaps' ], true ) ) {
			$fields = [
				'slider' => [ 'size', 'unit' ],
				'dimensions' => [ 'top', 'right', 'bottom', 'left', 'unit' ],
				'gaps' => [ 'row', 'column', 'unit' ],
			][ $type ];
			$input = count( $parts ) === count( $fields ) ? array_combine( $fields, $parts ) : null;
		} elseif ( $type === 'shadow' ) {
			$parts = preg_split( '/\s+/', $input, 5 );
			$input = count( $parts ) === 5 ? array_combine( [ 'horizontal', 'vertical', 'blur', 'spread', 'color' ], $parts ) : null;
		} elseif ( $type === 'position' && $input === '' ) {
			$input = ' ';
		}
		return self::value( $type, $input, strpos( $key, 'letter_spacing' ) !== false || strpos( $key, 'word_spacing' ) !== false );
	}

	public static function display( $value ): string {
		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) ? (string) $value : '';
		}
		if ( isset( $value['size'], $value['unit'] ) ) {
			return $value['size'] . ' ' . $value['unit'];
		}
		if ( isset( $value['top'], $value['right'], $value['bottom'], $value['left'], $value['unit'] ) ) {
			return implode( ' ', [ $value['top'], $value['right'], $value['bottom'], $value['left'], $value['unit'] ] );
		}
		if ( isset( $value['row'], $value['column'], $value['unit'] ) ) {
			return $value['row'] . ' ' . $value['column'] . ' ' . $value['unit'];
		}
		if ( isset( $value['horizontal'], $value['vertical'], $value['blur'], $value['spread'], $value['color'] ) ) {
			return implode( ' ', [ $value['horizontal'], $value['vertical'], $value['blur'], $value['spread'], $value['color'] ] );
		}
		return '';
	}

	public static function type_fields(): array {
		$fields = [];
		foreach ( self::TYPE_FIELDS as $field => $type ) {
			$devices = $type === 'slider' ? self::DEVICES : [ '' ];
			foreach ( $devices as $device ) {
				$fields[ $field . $device ] = $type;
			}
		}
		return $fields;
	}

	public static function typography( array $settings, string $prefix ): array {
		$values = [];
		foreach ( self::type_fields() as $field => $type ) {
			$value = self::value( $type, $settings[ $prefix . $field ] ?? null, strpos( $field, 'spacing' ) !== false );
			if ( $value !== null ) {
				$values[ $field ] = $value;
			}
		}
		return $values;
	}

	public static function complete_typography( array $values ): bool {
		return isset( $values['font_family'], $values['font_size'], $values['font_weight'], $values['line_height'] );
	}

	public static function token_id( $value ): bool {
		return is_string( $value ) && preg_match( '/^[a-zA-Z0-9_-]{1,40}$/D', $value ) === 1;
	}

	public static function global_ref( $value ): ?array {
		if ( is_string( $value ) && preg_match( '#^globals/(colors|typography)\?id=([a-zA-Z0-9_-]{1,40})$#D', $value, $m ) ) {
			return [ 'kind' => $m[1], 'id' => $m[2] ];
		}
		return null;
	}

	public static function digest( $value ): string {
		return hash( 'sha256', serialize( $value ) );
	}

	public static function equivalent( array $a, array $b ): bool {
		ksort( $a );
		ksort( $b );
		return $a === $b;
	}
}
