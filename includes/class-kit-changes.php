<?php

namespace Cort\ElementorGlobalStyles;

defined( 'ABSPATH' ) || exit;

final class Kit_Changes {

	public function preview( array $kit, array $proposal, array $chosen ): array {
		$working = $kit['state']['value'] ?? [];
		$plan = [
			'kind' => 'kit', 'kit_id' => $kit['id'], 'target' => $kit['id'], 'title' => $kit['name'],
			'before' => $kit['state'], 'changes' => [], 'notes' => $proposal['notes'], 'collection_defaults' => [],
		];
		foreach ( [ 'colors', 'fonts' ] as $section ) {
			foreach ( $proposal[ $section ] as $key => $row ) {
				if ( isset( $chosen[ $section ][ $key ] ) ) {
					$this->token( $kit, $working, $plan, $section, $key, $row );
				}
			}
		}
		foreach ( $proposal['theme'] as $key => $row ) {
			if ( isset( $chosen['theme'][ $key ] ) ) {
				$this->theme( $kit, $working, $plan, $row );
			}
		}
		$plan['after'] = $plan['changes'] ? [ 'exists' => true, 'value' => $working ] : $plan['before'];
		return $plan;
	}

	private function token( array $kit, array &$working, array &$plan, string $section, string $key, array $row ): void {
		$kind = $section === 'colors' ? 'colors' : 'typography';
		$collection = ( $row['system'] ? 'system_' : 'custom_' ) . $kind;
		$control = $kit['controls'][ $collection ] ?? [];
		if ( ! isset( $control['fields'] ) || strpos( $control['type'] ?? '', 'repeater' ) === false ) {
			$plan['notes'][] = $row['title'] . ' · El Kit carece de un control nativo compatible.';
			return;
		}
		$id = $row['system'] ? $key : Design_Proposal::custom_id( $kind, $row['role'] );
		$rows = $working[ $collection ] ?? $kit['effective'][ $collection ] ?? [];
		if ( ! is_array( $rows ) || ! $this->valid_rows( $rows ) ) {
			$plan['notes'][] = $row['title'] . ' · La colección existente tiene identificadores inválidos o duplicados.';
			return;
		}
		$index = self::index( $rows, $id );
		$old = $index === null ? null : $rows[ $index ];
		$desired = $old ?? [ '_id' => $id, 'title' => $row['title'] ];
		if ( $row['system'] && $old === null ) {
			$plan['notes'][] = $row['title'] . ' · El token de sistema no existe en el Kit.';
			return;
		}
		if ( ! $row['system'] && $old !== null && ( $old['title'] ?? '' ) !== $row['title'] ) {
			$plan['notes'][] = $row['title'] . ' · Su identificador ya pertenece a un token con otro nombre. Revisa el Kit.';
			return;
		}
		if ( $kind === 'colors' ) {
			if ( ! self::field_supported( $control['fields'], 'color', 'color', $row['values']['color'] ) ) {
				$plan['notes'][] = $row['title'] . ' · El control de color no está disponible.';
				return;
			}
			$desired['color'] = $row['values']['color'];
		} else {
			$has_typography = self::field_supported( $control['fields'], 'typography_typography', 'typography_switch', 'custom' );
			if ( ! $has_typography ) {
				$plan['notes'][] = $row['title'] . ' · La tipografía nativa no está disponible.';
				return;
			}
			$desired['typography_typography'] = 'custom';
			foreach ( Style::type_fields() as $field => $type ) {
				$native = 'typography_' . $field;
				$value = $row['values'][ $field ] ?? null;
				if ( $value === null ) {
					unset( $desired[ $native ] );
				} elseif ( self::field_supported( $control['fields'], $native, $type, $value ) ) {
					$desired[ $native ] = $value;
				} else {
					$plan['notes'][] = $row['title'] . ' · Se conserva el control incompatible ' . $native . '.';
				}
			}
			if ( ! Style::typography( $desired, 'typography_' ) ) {
				return;
			}
		}

		if ( ! $row['system'] && $old === null ) {
			$normalized = static function ( string $name ): string {
				return strtolower( remove_accents( trim( $name ) ) );
			};
			$names = [ $normalized( $row['title'] ), $normalized( str_replace( 'Cort · ', '', $row['title'] ) ) ];
			foreach ( $rows as $other ) {
				if ( ! in_array( $normalized( (string) ( $other['title'] ?? '' ) ), $names, true ) ) {
					continue;
				}
				$equal = $kind === 'colors'
					? Style::color( $other['color'] ?? null ) === $desired['color']
					: Style::equivalent( Style::typography( $other, 'typography_' ), Style::typography( $desired, 'typography_' ) );
				$plan['notes'][] = $row['title'] . ( $equal
					? ' · El Kit ya tiene un token con ese nombre y estilo. Se conserva el existente.'
					: ' · El Kit ya tiene un token con ese nombre y otro valor. Se conserva el existente.' );
				return;
			}
		}

		if ( $old === $desired ) {
			$plan['notes'][] = $row['title'] . ' · Sin cambios.';
			return;
		}
		if ( $index === null ) {
			$rows[] = $desired;
		} else {
			$rows[ $index ] = $desired;
		}
		$working[ $collection ] = array_values( $rows );
		$plan['collection_defaults'][ $collection ] = $kit['effective'][ $collection ] ?? [];
		$plan['changes'][] = [
			'collection' => $collection, 'id' => $id, 'label' => $row['title'],
			'previous' => $old, 'new' => $desired,
		];
	}

	private static function field_supported( array $fields, string $key, string $type, $value ): bool {
		if ( ! isset( $fields[ $key ] ) ) {
			$fields = array_column( $fields, null, 'name' );
		}
		return Elementor_Compatibility::supported_control( $fields, $key, $type, $value );
	}

	private function valid_rows( array $rows ): bool {
		$ids = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! Style::token_id( $row['_id'] ?? null ) || isset( $ids[ $row['_id'] ] ) ) {
				return false;
			}
			$ids[ $row['_id'] ] = true;
		}
		return true;
	}

	private function theme( array $kit, array &$working, array &$plan, array $row ): void {
		foreach ( $row['values'] as $key => $value ) {
			if ( ! Elementor_Compatibility::supported_control( $kit['controls'], $key, $row['types'][ $key ], $value ) ) {
				$plan['notes'][] = $row['title'] . ' · El control ' . $key . ' no está disponible en este Kit.';
				continue;
			}
			if ( ! empty( $working['__globals__'][ $key ] ) || ! empty( $working['__dynamic__'][ $key ] ) ) {
				$plan['notes'][] = $row['title'] . ' · Se conserva la referencia existente de ' . $key . '.';
				continue;
			}
			$previous = self::entry( $working, $key );
			if ( $previous === [ 'exists' => true, 'value' => $value ] ) {
				continue;
			}
			$plan['changes'][] = [
				'key' => $key, 'label' => $row['title'], 'previous' => $previous, 'new' => $value,
				'previous_display' => $previous['value'] ?? $kit['effective'][ $key ] ?? null,
			];
			$working[ $key ] = $value;
		}
	}

	public function restore( array $kit, array $backup, Elementor_Compatibility $elementor ): array {
		$working = $kit['state']['value'] ?? [];
		$omitted = [];
		$count = 0;
		$collections = [];
		foreach ( array_reverse( $backup['changes'] ) as $change ) {
			if ( isset( $change['key'] ) ) {
				$key = $change['key'];
				$current = self::entry( $working, $key );
				if ( $current === $change['previous'] ) {
					continue;
				}
				if ( $current !== [ 'exists' => true, 'value' => $change['new'] ] || ! empty( $working['__globals__'][ $key ] ) || ! empty( $working['__dynamic__'][ $key ] ) ) {
					$omitted[] = $change['label'] . ' · ' . $key . ' cambió después de aplicar el plan.';
					continue;
				}
				if ( $change['previous']['exists'] ) {
					$working[ $key ] = $change['previous']['value'];
				} else {
					unset( $working[ $key ] );
				}
				++$count;
				continue;
			}
			$collection = $change['collection'];
			$rows = $working[ $collection ] ?? [];
			$index = self::index( $rows, $change['id'] );
			$current = $index === null ? null : $rows[ $index ];
			if ( $current === $change['previous'] || ( $current === null && ! array_key_exists( $collection, $working ) ) ) {
				continue;
			}
			if ( $current !== $change['new'] ) {
				$omitted[] = $change['label'] . ' · El token cambió después de aplicar el plan.';
				continue;
			}
			if ( $change['previous'] === null ) {
				$kind = strpos( $collection, 'colors' ) !== false ? 'colors' : 'typography';
				if ( $elementor->token_in_use( $kind, $change['id'] ) ) {
					$omitted[] = $change['label'] . ' · Aún tiene referencias. Restaura primero los documentos que lo utilizan.';
					continue;
				}
				unset( $rows[ $index ] );
			} else {
				$rows[ $index ] = $change['previous'];
			}
			$working[ $collection ] = array_values( $rows );
			$collections[ $collection ] = true;
			++$count;
		}
		foreach ( array_keys( $collections ) as $collection ) {
			if ( ! isset( $backup['before']['value'][ $collection ] ) && $working[ $collection ] === array_values( $backup['collection_defaults'][ $collection ] ?? [] ) ) {
				unset( $working[ $collection ] );
			}
		}
		$exists = (bool) $working || $backup['before']['exists'];
		return [ 'after' => [ 'exists' => $exists, 'value' => $exists ? $working : null ], 'count' => $count, 'notes' => $omitted ];
	}

	public static function index( array $rows, string $id ): ?int {
		foreach ( $rows as $index => $row ) {
			if ( is_array( $row ) && ( $row['_id'] ?? '' ) === $id ) {
				return (int) $index;
			}
		}
		return null;
	}

	public static function entry( array $settings, string $key ): array {
		return [ 'exists' => array_key_exists( $key, $settings ), 'value' => $settings[ $key ] ?? null ];
	}
}
