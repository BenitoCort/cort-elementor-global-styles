<?php

namespace Cort\ElementorGlobalStyles;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Design_Analyzer {

	const MAX_BYTES = 8388608;
	const MAX_ELEMENTS = 12000;

	public function from_json( string $json, string $filename ): array {
		if ( $json === '' || strlen( $json ) > self::MAX_BYTES ) {
			throw new RuntimeException( 'El JSON está vacío o supera el límite de 8 MiB.' );
		}
		$document = json_decode( preg_replace( '/^\xEF\xBB\xBF/', '', $json ), true, 128 );
		if ( ! is_array( $document ) || json_last_error() !== JSON_ERROR_NONE || ! isset( $document['version'], $document['content'] ) || ! is_array( $document['content'] ) || ! is_string( $document['version'] ) || ! preg_match( '/^0\.\d+$/D', $document['version'] ) ) {
			throw new RuntimeException( 'El archivo debe ser un JSON de plantilla exportado por Elementor, con version y content.' );
		}
		$this->inspect_upload( $document );
		$embedded = [];
		foreach ( [ 'system_colors', 'custom_colors', 'system_typography', 'custom_typography' ] as $key ) {
			$rows = $document['page_settings'][ $key ] ?? $document['site_settings'][ $key ] ?? [];
			if ( is_array( $rows ) ) {
				$embedded[ $key ] = $rows;
			}
		}
		return $this->analyze(
			$document['content'],
			is_array( $document['page_settings'] ?? null ) ? $document['page_settings'] : [],
			$embedded,
			[
				'kind' => 'elementor_json',
				'title' => $this->label( $document['title'] ?? '', 180 ),
				'filename' => $this->label( $filename, 180 ),
				'version' => $document['version'],
				'sha256' => hash( 'sha256', $json ),
			]
		);
	}

	private function inspect_upload( array $data ): void {
		$walk = static function ( $value ) use ( &$walk ): void {
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					$walk( $item );
				}
			} elseif ( is_string( $value ) && preg_match( '~<\?|<\s*/?\s*(?:script|iframe|object|embed|applet|svg|math|style)\b|<[^>]*\s+on[a-z]+\s*=|(?:javascript|vbscript)\s*:|data\s*:\s*(?:text/(?:html|javascript)|image/svg\+xml)~i', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
				throw new RuntimeException( 'El archivo contiene código o marcado activo. Exporta una copia de la plantilla sin ese contenido para analizarla.' );
			}
		};
		$walk( $data );
	}

	private function label( $label, int $length ): string {
		return is_string( $label ) ? wp_html_excerpt( sanitize_text_field( $label ), $length, '' ) : '';
	}

	public function analyze( array $elements, array $page, array $kit, array $source ): array {
		$report = [
			'source' => $source,
			'element_count' => 0,
			'element_types' => [],
			'colors' => [],
			'typography' => [],
			'spacing' => [],
			'borders' => [],
			'components' => [],
			'global_references' => [],
			'ignored_style_controls' => [],
			'page_layout' => Style::value( 'layout', $page['template'] ?? null ),
			'notes' => [],
		];
		$tokens = self::tokens( $kit );
		$this->walk( $elements, 0, '', $tokens, $report );
		if ( $report['element_count'] === 0 ) {
			throw new RuntimeException( 'El documento no contiene elementos de Elementor.' );
		}
		foreach ( [ 'colors', 'typography', 'spacing', 'borders', 'components', 'global_references' ] as $list ) {
			$report[ $list ] = array_values( $report[ $list ] );
			usort( $report[ $list ], static function ( array $a, array $b ): int {
				return $b['count'] <=> $a['count'];
			} );
		}
		if ( array_filter( $report['global_references'], static function ( array $v ): bool { return $v['status'] !== 'resolved'; } ) ) {
			$report['notes'][] = 'Las referencias sin valores disponibles se registran sin inferir sus valores a partir de otro Kit.';
		}
		if ( $report['ignored_style_controls'] ) {
			$report['notes'][] = 'Se omitieron valores no verificables o fuera del formato admitido, como unidades CSS personalizadas.';
		}
		return $report;
	}

	public static function tokens( array $settings ): array {
		$tokens = [ 'colors' => [], 'typography' => [] ];
		foreach ( [
			'system_colors' => 'colors', 'custom_colors' => 'colors',
			'system_typography' => 'typography', 'custom_typography' => 'typography',
		] as $collection => $kind ) {
			foreach ( (array) ( $settings[ $collection ] ?? [] ) as $row ) {
				if ( ! is_array( $row ) || ! Style::token_id( $row['_id'] ?? null ) ) {
					continue;
				}
				$value = $kind === 'colors' ? Style::color( $row['color'] ?? null ) : Style::typography( $row, 'typography_' );
				if ( $value !== null && $value !== [] ) {
					$tokens[ $kind ][ $row['_id'] ] = $value;
				}
			}
		}
		return $tokens;
	}

	/** Roles are defined by the widget and control. Matching on color alone is insufficient. */
	public static function color_role( string $type, string $key, array $s, string $region = '' ): string {
		$base = preg_replace( '/_(?:tablet_extra|mobile_extra|widescreen|laptop|tablet|mobile)$/D', '', $key );
		$roles = [
			'heading' => [ 'title_color' => empty( $s['link']['url'] ) ? 'heading' : 'link' ],
			'text-editor' => [ 'text_color' => $region === 'footer' ? 'muted' : 'text' ],
			'button' => [
				'background_color' => 'primary', 'button_background_hover_color' => 'primary_hover',
				'button_text_color' => 'on_primary', 'hover_color' => 'on_primary',
			],
			'search' => [
				'submit_background_normal_color' => 'primary', 'submit_background_hover_color' => 'primary_hover',
				'submit_text_color_normal' => 'on_primary', 'submit_text_color_hover' => 'on_primary',
				'placeholder_color' => 'placeholder',
				'search_field_input_text_color_normal' => 'field_text', 'search_field_input_text_color_focus' => 'field_text',
				'search_field_background_normal_color' => 'field_surface', 'search_field_background_focus_color' => 'field_surface',
				'search_field_border_normal_color' => 'field_border', 'search_field_border_focus_color' => 'field_focus',
				'search_field_icon_search_normal_color_normal' => 'search_icon',
			],
			'search-form' => [
				'input_text_color' => 'field_text', 'input_background_color' => 'field_surface',
				'input_border_color' => 'field_border', 'button_background_color' => 'primary',
			],
			'form' => [
				'field_text_color' => 'field_text', 'field_background_color' => 'field_surface',
				'field_border_color' => 'field_border', 'label_color' => 'label',
			],
			'icon' => [
				'primary_color' => ( $s['view'] ?? '' ) === 'stacked' ? 'icon_surface' : 'icon_stroke',
				'secondary_color' => 'icon_ink',
			],
			'icon-box' => [ 'title_color' => 'heading', 'description_color' => 'text' ],
			'posts' => [
				'classic_title_color' => 'link', 'classic_excerpt_color' => 'text',
				'cards_title_color' => 'link', 'cards_excerpt_color' => 'text',
			],
		];
		if ( in_array( $type, [ 'container', 'section', 'column' ], true ) ) {
			if ( $base === 'background_color' ) {
				return $region === 'hero' ? 'hero_surface' : ( $region === 'root' || $region === 'footer' ? 'section_surface' : 'surface' );
			}
			if ( $base === 'border_color' ) {
				return 'border';
			}
			if ( $base === 'border_hover_color' ) {
				return 'border_hover';
			}
		}
		return $roles[ $type ][ $base ] ?? 'other';
	}

	public static function type_role( string $type, string $prefix, array $s, array $values, string $region = '' ): string {
		$tag = $s['header_size'] ?? $s['title_size'] ?? '';
		if ( $type === 'heading' || ( $type === 'icon-box' && $prefix === 'title_typography_' ) ) {
			if ( is_string( $tag ) && preg_match( '/^h[1-6]$/D', $tag ) ) {
				return $tag;
			}
			return empty( $s['link']['url'] ) ? 'brand' : 'link';
		}
		if ( $type === 'button' ) {
			return 'button';
		}
		if ( in_array( $type, [ 'search', 'search-form', 'form' ], true ) ) {
			if ( strpos( $prefix, 'label' ) !== false ) {
				return 'label';
			}
			return preg_match( '/^(?:submit|button|previous|next)/', $prefix ) ? 'search_button' : 'field';
		}
		if ( $type === 'posts' && strpos( $prefix, 'title' ) !== false ) {
			return 'article_link';
		}
		$size = $values['font_size'] ?? [];
		if ( $type === 'text-editor' && $region === 'footer' ) {
			return 'small';
		}
		if ( in_array( $type, [ 'text-editor', 'icon-box', 'posts' ], true ) ) {
			return ( $size['unit'] ?? '' ) === 'px' && $size['size'] <= 14 ? 'small' : 'body';
		}
		return 'other';
	}

	public static function typography_groups( array $s ): array {
		$groups = [];
		foreach ( array_merge( array_keys( $s ), array_keys( (array) ( $s['__globals__'] ?? [] ) ) ) as $key ) {
			if ( is_string( $key ) && preg_match( '/^((?:[a-z0-9_]+_)?typography_)(?:typography|font_family)$/D', $key, $m ) ) {
				$groups[ $m[1] ] = $m[1];
			}
		}
		return array_values( $groups );
	}

	public static function region( array $node, int $depth, string $ancestor, $position ): string {
		$s = $node['settings'] ?? [];
		if ( ( $s['html_tag'] ?? '' ) === 'footer' ) {
			return 'footer';
		}
		if ( $depth === 0 ) {
			return $position === 0 ? 'hero' : 'root';
		}
		return $ancestor === 'footer' ? 'footer' : ( $ancestor === 'hero' ? 'hero_content' : 'content' );
	}

	private function walk( array $elements, int $depth, string $ancestor, array $tokens, array &$report ): void {
		if ( $depth > 32 || array_values( $elements ) !== $elements ) {
			throw new RuntimeException( 'La jerarquía del documento no es una lista válida o supera 32 niveles.' );
		}
		foreach ( $elements as $position => $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['elType'] ?? null ) || ! is_array( $node['settings'] ?? null ) || ! is_array( $node['elements'] ?? null ) ) {
				throw new RuntimeException( 'Hay un elemento de Elementor sin tipo, configuración o subelementos válidos.' );
			}
			if ( ++$report['element_count'] > self::MAX_ELEMENTS ) {
				throw new RuntimeException( 'El documento supera el límite de 12,000 elementos.' );
			}
			$type = $this->label( $node['widgetType'] ?? $node['elType'], 60 );
			$id = Style::token_id( $node['id'] ?? null ) ? $node['id'] : '';
			$s = $node['settings'];
			$region = self::region( $node, $depth, $ancestor, $position );
			$report['element_types'][ $type ] = 1 + ( $report['element_types'][ $type ] ?? 0 );
			$context = [ 'widget' => $type, 'element_id' => $id, 'region' => $region ];
			$refs = (array) ( $s['__globals__'] ?? [] );
			$dynamic = (array) ( $s['__dynamic__'] ?? [] );
			$styles = [];
			foreach ( array_unique( array_merge( array_keys( $s ), array_keys( $refs ) ) ) as $key ) {
				if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9_]{1,100}$/D', $key ) ) {
					continue;
				}
				$reference = Style::global_ref( $refs[ $key ] ?? null );
				$ref_value = $reference ? ( $tokens[ $reference['kind'] ][ $reference['id'] ] ?? null ) : null;
				if ( array_key_exists( $key, $refs ) && ( $refs[ $key ] !== '' ) ) {
					$this->tally( $report['global_references'], [
						'control' => $key, 'kind' => $reference['kind'] ?? 'unknown',
						'id' => $reference['id'] ?? '', 'status' => $ref_value === null ? 'unresolved' : 'resolved',
					], $context );
				}
				$value = $s[ $key ] ?? null;
				$format = $this->style_format( $key, $value );
				if ( $format === null || ! empty( $dynamic[ $key ] ) ) {
					continue;
				}
				$has_ref = $reference !== null || ! empty( $refs[ $key ] );
				$clean = $has_ref ? ( $format === 'color' ? Style::color( $ref_value ) : null ) : Style::value( $format, $value, strpos( $key, 'margin' ) !== false );
				if ( $clean === null ) {
					if ( ! $has_ref && $value !== null && $value !== '' ) {
						$report['ignored_style_controls'][ $key ] = 1 + ( $report['ignored_style_controls'][ $key ] ?? 0 );
					}
					continue;
				}
				$styles[ $key ] = $clean;
				$sample = $context + [ 'control' => $key, 'source' => $has_ref ? 'global' : 'local' ];
				if ( $format === 'color' || $format === 'shadow' ) {
					$role = $format === 'shadow' ? 'shadow' : self::color_role( $type, $key, $s, $region );
					$this->color( $report['colors'], $format === 'shadow' ? $clean['color'] : $clean, $sample + [ 'role' => $role ] );
				}
				if ( preg_match( '/padding|margin|gaps?|spacing|height|width/', $key ) && $format !== 'color' ) {
					$this->tally( $report['spacing'], [ 'control' => $key, 'value' => $clean ], $context );
				}
				if ( preg_match( '/border|shadow/', $key ) ) {
					$this->tally( $report['borders'], [ 'control' => $key, 'value' => $clean ], $context );
				}
			}
			foreach ( self::typography_groups( $s ) as $prefix ) {
				$starter = $prefix . 'typography';
				$ref = Style::global_ref( $refs[ $starter ] ?? null );
				if ( ! empty( $dynamic[ $starter ] ) || ( ! empty( $refs[ $starter ] ) && ( $ref['kind'] ?? '' ) !== 'typography' ) ) {
					continue;
				}
				$values = $ref ? ( $tokens['typography'][ $ref['id'] ] ?? [] ) : Style::typography( $s, $prefix );
				if ( ! is_array( $values ) || ! $values ) {
					continue;
				}
				$role = self::type_role( $type, $prefix, $s, $values, $region );
				$this->tally(
					$report['typography'], [ 'role' => $role, 'values' => $values ],
					$context + [ 'control' => $starter, 'source' => $ref ? 'global' : 'local' ]
				);
				foreach ( $values as $key => $value ) {
					$styles[ $prefix . $key ] = $value;
				}
			}
			if ( $styles && in_array( $type, [ 'heading', 'text-editor', 'button', 'search', 'search-form', 'form', 'image', 'icon', 'icon-box', 'container', 'section', 'column', 'posts' ], true ) ) {
				$this->tally(
					$report['components'],
					[ 'widget' => $type, 'region' => $region, 'tag' => $this->label( $s['header_size'] ?? $s['html_tag'] ?? '', 12 ), 'styles' => $styles ],
					[ 'element_id' => $id ]
				);
			}
			$this->walk( $node['elements'], $depth + 1, $region, $tokens, $report );
		}
	}

	private function style_format( string $key, $value ): ?string {
		if ( preg_match( '/(?:^|_)colou?r(?:_|$)/', $key ) ) {
			return 'color';
		}
		if ( strpos( $key, 'border' ) !== false && preg_match( '/(?:^border|_border|_border_type)(?:_(?:tablet|mobile))?$/D', $key ) && is_string( $value ) ) {
			return 'border';
		}
		if ( substr( $key, -16 ) === '_box_shadow_type' && is_string( $value ) ) {
			return 'toggle';
		}
		if ( substr( $key, -20 ) === '_box_shadow_position' ) {
			return 'position';
		}
		if ( is_array( $value ) && isset( $value['color'], $value['horizontal'] ) ) {
			return 'shadow';
		}
		if ( preg_match( '/(?:padding|margin|gap|radius|width|height|spacing)(?:_|$)/', $key ) && is_array( $value ) && strpos( $key, 'typography_' ) === false ) {
			return isset( $value['top'] ) ? 'dimensions' : ( isset( $value['row'] ) ? 'gaps' : 'slider' );
		}
		return null;
	}

	private function color( array &$all, string $color, array $context ): void {
		if ( ! isset( $all[ $color ] ) ) {
			$all[ $color ] = [ 'value' => $color, 'count' => 0, 'uses' => [] ];
		}
		++$all[ $color ]['count'];
		$key = implode( ':', [ $context['role'], $context['widget'], $context['control'], $context['source'] ] );
		if ( ! isset( $all[ $color ]['uses'][ $key ] ) ) {
			$all[ $color ]['uses'][ $key ] = [ 'count' => 0 ] + $context;
		}
		++$all[ $color ]['uses'][ $key ]['count'];
	}

	private function tally( array &$all, array $item, array $context ): void {
		$key = Style::digest( $item );
		if ( ! isset( $all[ $key ] ) ) {
			$all[ $key ] = $item + [ 'count' => 0, 'examples' => [] ];
		}
		++$all[ $key ]['count'];
		if ( count( $all[ $key ]['examples'] ) < 4 && ! in_array( $context, $all[ $key ]['examples'], true ) ) {
			$all[ $key ]['examples'][] = $context;
		}
	}
}
