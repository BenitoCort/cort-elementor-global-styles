<?php

namespace Cort\ElementorGlobalStyles;

use RuntimeException;
use stdClass;

defined( 'ABSPATH' ) || exit;

final class Global_Reference_Manager {

	const WIDGETS = [ 'heading', 'text-editor', 'button', 'icon', 'icon-box', 'search', 'search-form', 'form', 'posts' ];
	const CONTAINERS = [ 'container', 'section', 'column' ];

	public function preview( array $document, array $kit, Elementor_Compatibility $elementor ): array {
		$tokens = $this->candidates( $kit['effective'] );
		$changes = [];
		$stats = [ 'existing' => 0, 'unsupported' => 0, 'different' => 0, 'duplicates' => 0 ];
		$seen = [];
		$walk = function ( array $items, int $depth, string $parent ) use ( &$walk, &$changes, &$stats, &$seen, $tokens, $elementor ): void {
			if ( $depth > 32 || count( $seen ) > Design_Analyzer::MAX_ELEMENTS ) {
				throw new RuntimeException( 'La jerarquía de Elementor supera el límite de conversión.' );
			}
			foreach ( $items as $position => $node ) {
				if ( count( $seen ) >= Design_Analyzer::MAX_ELEMENTS || ! is_array( $node ) || ! Style::token_id( $node['id'] ?? null ) || ! is_array( $node['settings'] ?? null ) || ! is_array( $node['elements'] ?? null ) ) {
					throw new RuntimeException( 'Hay un elemento con estructura incompatible. Abre y vuelve a guardar el documento en Elementor.' );
				}
				$id = $node['id'];
				if ( isset( $seen[ $id ] ) ) {
					throw new RuntimeException( 'El documento tiene identificadores de elemento repetidos.' );
				}
				$seen[ $id ] = true;
				$type = $node['widgetType'] ?? $node['elType'] ?? '';
				$region = Design_Analyzer::region( $node, $depth, $parent, $position );
				if ( is_string( $type ) && in_array( $type, array_merge( self::WIDGETS, self::CONTAINERS ), true ) ) {
					$this->scan( $node, $type, $region, $tokens, $elementor, $changes, $stats );
				}
				$walk( $node['elements'], $depth + 1, $region );
			}
		};
		$walk( $document['elements'], 0, '' );

		$root = json_decode( $document['raw']['value'], false, 128 );
		$results = $this->patch( $root, $changes, false );
		$after = $changes ? [ 'exists' => true, 'value' => $this->encode( $results['data'] ) ] : $document['raw'];
		return [
			'kind' => 'document', 'kit_id' => $kit['id'], 'target' => $document['id'], 'title' => $document['title'],
			'before' => $document['raw'], 'after' => $after, 'changes' => $results['changes'],
			'tokens' => $this->fingerprint( $kit ),
			'metadata' => $document['metadata'],
			'notes' => [
				sprintf( '%d controles ya vinculados o dinámicos, %d sin coincidencia exacta, %d sin control global verificable, %d coincidencias ambiguas.', $stats['existing'], $stats['different'], $stats['unsupported'], $stats['duplicates'] ),
				'La conversión conserva los valores locales como respaldo y agrega la referencia nativa de Elementor.',
			],
		];
	}

	public function fingerprint( array $kit ): string {
		return Style::digest( $this->candidates( $kit['effective'] ) );
	}

	private function candidates( array $settings ): array {
		$tokens = Design_Analyzer::tokens( $settings );
		$result = [ 'colors' => [], 'typography' => [] ];
		foreach ( [
			'colors' => [ Design_Proposal::COLOR_SYSTEM, Design_Proposal::CUSTOM_COLORS ],
			'typography' => [ Design_Proposal::FONT_SYSTEM, Design_Proposal::CUSTOM_FONTS ],
		] as $kind => [ $system, $custom ] ) {
			foreach ( $system as $id => $role ) {
				if ( array_key_exists( $id, $tokens[ $kind ] ) ) {
					$result[ $kind ][ $role ][ $id ] = $tokens[ $kind ][ $id ];
				}
			}
			foreach ( $custom as $role => $title ) {
				$rows = (array) ( $settings[ 'custom_' . $kind ] ?? [] );
				$valid_names = [ strtolower( remove_accents( Design_Proposal::custom_title( $title ) ) ), strtolower( remove_accents( $title ) ) ];
				foreach ( $rows as $row ) {
					$id = $row['_id'] ?? '';
					$name = strtolower( remove_accents( trim( (string) ( $row['title'] ?? '' ) ) ) );
					if ( Style::token_id( $id ) && isset( $tokens[ $kind ][ $id ] ) && in_array( $name, $valid_names, true ) ) {
						$result[ $kind ][ $role ][ $id ] = $tokens[ $kind ][ $id ];
					}
				}
			}
		}
		return $result;
	}

	private function scan( array $node, string $type, string $region, array $tokens, Elementor_Compatibility $elementor, array &$changes, array &$stats ): void {
		$s = $node['settings'];
		$locals = [];
		foreach ( $s as $key => $value ) {
			if ( is_string( $key ) && ! preg_match( '/_(tablet|mobile|laptop|widescreen|mobile_extra|tablet_extra)$/D', $key ) && ( $color = Style::color( $value ) ) !== null ) {
				$role = Design_Analyzer::color_role( $type, $key, $s, $region );
				$locals[ $key ] = [ 'kind' => 'colors', 'role' => $role, 'value' => $color ];
			}
		}
		foreach ( Design_Analyzer::typography_groups( $s ) as $prefix ) {
			$key = $prefix . 'typography';
			$values = Style::typography( $s, $prefix );
			$keys = $this->local_signature( $s, $key, 'typography' );
			if ( ( $s[ $key ] ?? '' ) !== 'custom' || ! Style::complete_typography( $values ) || count( $values ) + 1 !== count( $keys ) ) {
				continue;
			}
			$locals[ $key ] = [
				'kind' => 'typography', 'role' => Design_Analyzer::type_role( $type, $prefix, $s, $values, $region ), 'value' => $values,
			];
		}

		foreach ( $locals as $key => $style ) {
			$kind = $style['kind'];
			$role = $style['role'];
			if ( ! isset( $tokens[ $kind ][ $role ] ) ) {
				continue;
			}
			$signature = $this->local_signature( $s, $key, $kind );
			$dynamic = array_filter( array_intersect_key( (array) ( $s['__dynamic__'] ?? [] ), $signature ) );
			$globals = array_filter( array_intersect_key( (array) ( $s['__globals__'] ?? [] ), $signature ) );
			if ( $dynamic || $globals ) {
				++$stats['existing'];
				continue;
			}
			$native_candidates = array_filter( $tokens[ $kind ][ $role ], static function ( $value ) use ( $style, $kind ): bool {
				return $kind === 'colors' ? $value === $style['value'] : Style::equivalent( $value, $style['value'] );
			} );
			if ( ! $native_candidates ) {
				++$stats['different'];
				continue;
			}
			if ( count( $native_candidates ) > 1 ) {
				++$stats['duplicates'];
				continue;
			}
			if ( ! $elementor->native_binding( $node, $key, $kind ) ) {
				++$stats['unsupported'];
				continue;
			}
			if ( count( $changes ) >= 3000 ) {
				throw new RuntimeException( 'Este documento tiene más de 3,000 coincidencias. Divídelo antes de convertir sus estilos.' );
			}
			$id = key( $native_candidates );
			$changes[] = [
				'element_id' => $node['id'], 'widget' => $type, 'control' => $key, 'kind' => $kind, 'role' => $role,
				'local' => $signature, 'reference' => 'globals/' . $kind . '?id=' . $id,
				'label' => $type . ' · ' . $key,
			];
		}
	}

	private function local_signature( array $s, string $key, string $kind ): array {
		if ( $kind === 'colors' ) {
			return array_intersect_key( $s, [ $key => true ] );
		}
		$prefix = substr( $key, 0, -strlen( 'typography' ) );
		return array_filter( $s, static function ( $k ) use ( $prefix ): bool {
			return is_string( $k ) && strpos( $k, $prefix ) === 0;
		}, ARRAY_FILTER_USE_KEY );
	}

	public function restore( array $document, array $backup ): array {
		$root = json_decode( $document['raw']['value'], false, 128 );
		$results = $this->patch( $root, $backup['changes'], true );
		return [
			'after' => $results['count'] ? [ 'exists' => true, 'value' => $this->encode( $results['data'] ) ] : $document['raw'],
			'notes' => $results['notes'], 'count' => $results['count'],
		];
	}

	private function patch( array $elements, array $changes, bool $restore ): array {
		$nodes = [];
		$register = static function ( array $nodes_to_check ) use ( &$register, &$nodes ): void {
			foreach ( $nodes_to_check as $node ) {
				if ( ! $node instanceof stdClass || ! Style::token_id( $node->id ?? null ) ) {
					continue;
				}
				$nodes[ $node->id ] = array_key_exists( $node->id, $nodes ) ? null : $node;
				$register( (array) ( $node->elements ?? [] ) );
			}
		};
		$register( $elements );
		$notes = [];
		$count = 0;
		$previous = [];
		$restored = [];
		foreach ( $changes as &$change ) {
			$id = $change['element_id'];
			$node = $nodes[ $id ] ?? null;
			$key = $change['control'];
			if ( ! $node || ( $node->widgetType ?? $node->elType ) !== $change['widget'] || ! ( ( $node->settings ?? null ) instanceof stdClass ) ) {
				$notes[] = $change['label'] . ' · El elemento ya no coincide.';
				continue;
			}
			$s = $node->settings;
			$globals = isset( $s->__globals__ ) && ( is_object( $s->__globals__ ) || is_array( $s->__globals__ ) ) ? (object) $s->__globals__ : new stdClass();
			if ( ! $restore ) {
				if ( isset( $s->__globals__ ) && ! is_array( $s->__globals__ ) && ! is_object( $s->__globals__ ) ) {
					throw new RuntimeException( 'El documento tiene referencias globales con un formato incompatible.' );
				}
				if ( ! array_key_exists( $id, $previous ) ) {
					$previous[ $id ] = [
						'type' => property_exists( $s, '__globals__' ) ? gettype( $s->__globals__ ) : 'missing',
						'exists' => property_exists( $s, '__globals__' ),
					];
				}
				$change['original_globals'] = $previous[ $id ];
				$change['previous'] = property_exists( $globals, $key ) ? [ 'exists' => true, 'value' => $globals->$key ] : [ 'exists' => false, 'value' => null ];
				$globals->$key = $change['reference'];
				$s->__globals__ = $globals;
				++$count;
				continue;
			}
			$current = property_exists( $globals, $key ) ? [ 'exists' => true, 'value' => $globals->$key ] : [ 'exists' => false, 'value' => null ];
			if ( $current === $change['previous'] ) {
				continue;
			}
			$locals = json_decode( wp_json_encode( $s ), true, 128 );
			$signature = $this->local_signature( $locals, $key, $change['kind'] );
			if ( $current['value'] !== $change['reference'] || $signature !== $change['local'] ) {
				$notes[] = $change['label'] . ' · La referencia o el estilo local cambió después de la conversión.';
				continue;
			}
			if ( $change['previous']['exists'] ) {
				$globals->$key = $change['previous']['value'];
			} else {
				unset( $globals->$key );
			}
			$s->__globals__ = $globals;
			$restored[ $id ] = $change['original_globals'];
			++$count;
		}
		unset( $change );
		foreach ( $restored as $id => $shape ) {
			$s = $nodes[ $id ]->settings;
			if ( get_object_vars( $s->__globals__ ) ) {
				continue;
			}
			if ( ! $shape['exists'] ) {
				unset( $s->__globals__ );
			} elseif ( $shape['type'] === 'array' ) {
				$s->__globals__ = [];
			} elseif ( $shape['type'] === 'NULL' ) {
				$s->__globals__ = null;
			}
		}
		return [ 'data' => $elements, 'count' => $count, 'notes' => $notes, 'changes' => $changes ];
	}

	private function encode( array $elements ): string {
		$json = wp_json_encode( $elements, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > Design_Analyzer::MAX_BYTES ) {
			throw new RuntimeException( 'No se pudo serializar la conversión o se excede el límite de datos.' );
		}
		return $json;
	}
}
