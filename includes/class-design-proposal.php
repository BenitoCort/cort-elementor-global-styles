<?php

namespace Cort\ElementorGlobalStyles;

defined( 'ABSPATH' ) || exit;

final class Design_Proposal {

	const COLOR_SYSTEM = [
		'primary' => 'primary', 'secondary' => 'heading', 'text' => 'text', 'accent' => 'link',
	];
	const FONT_SYSTEM = [
		'primary' => 'h1', 'secondary' => 'h2', 'text' => 'body', 'accent' => 'button',
	];
	const CUSTOM_COLORS = [
		'primary_hover' => 'Hover de acción',
		'on_primary' => 'Texto sobre azul',
		'surface' => 'Superficie blanca',
		'hero_surface' => 'Fondo de portada',
		'section_surface' => 'Fondo suave',
		'border' => 'Borde neutro',
		'border_hover' => 'Borde hover',
		'muted' => 'Texto secundario',
		'placeholder' => 'Texto de campo vacío',
		'icon_surface' => 'Fondo de icono',
		'icon_ink' => 'Color de icono',
		'icon_stroke' => 'Flechas',
	];
	const CUSTOM_FONTS = [
		'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6',
		'small' => 'Texto pequeño', 'link' => 'Enlace',
		'field' => 'Campo', 'label' => 'Etiqueta',
		'search_button' => 'Botón de búsqueda', 'article_link' => 'Título de artículo',
	];

	public static function custom_title( string $title ): string {
		return 'Cort · ' . $title;
	}

	public static function custom_id( string $kind, string $role ): string {
		return substr( hash( 'sha256', 'cort-egs/' . $kind . '/' . $role ), 0, 7 );
	}

	public function build( array $report ): array {
		$proposal = [ 'colors' => [], 'fonts' => [], 'theme' => [], 'notes' => $report['notes'] ];
		$palette = $this->palette( $report['colors'] );
		$profiles = $this->profiles( $report['typography'] );

		foreach ( self::COLOR_SYSTEM as $slot => $role ) {
			if ( isset( $palette[ $role ] ) ) {
				$proposal['colors'][ $slot ] = $this->row( ucfirst( $slot ), $role, [ 'color' => $palette[ $role ] ], [ 'color' => 'color' ], 'Extraído', true );
			}
		}
		foreach ( self::CUSTOM_COLORS as $role => $title ) {
			if ( isset( $palette[ $role ] ) ) {
				$proposal['colors'][ $role ] = $this->row( self::custom_title( $title ), $role, [ 'color' => $palette[ $role ] ], [ 'color' => 'color' ], 'Extraído' );
			}
		}

		foreach ( self::FONT_SYSTEM as $slot => $role ) {
			if ( isset( $profiles[ $role ] ) ) {
				$proposal['fonts'][ $slot ] = $this->row( ucfirst( $slot ), $role, $profiles[ $role ]['values'], Style::type_fields(), $profiles[ $role ]['source'], true );
			}
		}
		foreach ( self::CUSTOM_FONTS as $role => $title ) {
			if ( isset( $profiles[ $role ] ) ) {
				$proposal['fonts'][ $role ] = $this->row( self::custom_title( $title ), $role, $profiles[ $role ]['values'], Style::type_fields(), $profiles[ $role ]['source'] );
			}
		}

		foreach ( [ 'body', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'link' ] as $role ) {
			$prefix = $role === 'link' ? 'link_normal' : $role;
			$color_role = $role === 'body' ? 'text' : ( $role === 'link' ? 'link' : 'heading' );
			$values = [];
			$types = [];
			$this->set_typography( $values, $types, $prefix . '_typography_', $profiles[ $role ]['values'] ?? [] );
			$this->set( $values, $types, $prefix . '_color', 'color', $palette[ $color_role ] ?? null );
			if ( $values ) {
				$label = [ 'body' => 'Texto general', 'link' => 'Enlaces' ][ $role ] ?? strtoupper( $role );
				$proposal['theme'][ $role ] = $this->row( $label, 'Typography', $values, $types, $profiles[ $role ]['source'] ?? 'Extraído' );
			}
		}

		if ( isset( $palette['primary_hover'], $palette['link'] ) ) {
			$proposal['theme']['link_hover'] = $this->row( 'Enlace al pasar el cursor', 'Typography', [ 'link_hover_color' => $palette['primary_hover'] ], [ 'link_hover_color' => 'color' ], 'Inferido a partir del botón' );
		}
		$this->buttons( $report, $profiles, $proposal );
		$this->fields( $report, $profiles, $palette, $proposal );
		$this->images( $report, $proposal );
		$this->layout( $report, $palette, $proposal );

		if ( isset( $palette['on_primary'], $palette['surface'] ) && $palette['on_primary'] === $palette['surface'] ) {
			$proposal['notes'][] = 'El blanco del botón y el blanco de superficie mantienen nombres e identificadores independientes por su función.';
		}
		if ( isset( $palette['link'], $palette['icon_ink'] ) && $palette['link'] === $palette['icon_ink'] ) {
			$proposal['notes'][] = 'Los enlaces y los iconos mantienen tokens separados aunque tengan el mismo azul.';
		}
		$proposal['notes'][] = 'Los estados o variantes que no aparecen en la referencia quedan en sus controles locales. Las variaciones de borde pueden conservarse sin vincularlas.';
		return $proposal;
	}

	private function palette( array $colors ): array {
		$candidates = [];
		foreach ( $colors as $entry ) {
			foreach ( $entry['uses'] as $use ) {
				$role = $use['role'];
				if ( $role === 'other' || $role === 'shadow' || ( $role === 'section_surface' && $entry['value'] === '#FFFFFF' ) ) {
					continue;
				}
				$candidates[ $role ][ $entry['value'] ] = ( $candidates[ $role ][ $entry['value'] ] ?? 0 ) + $use['count'];
			}
		}
		$out = [];
		foreach ( $candidates as $role => $values ) {
			arsort( $values );
			$out[ $role ] = key( $values );
		}
		return $out;
	}

	private function profiles( array $typography ): array {
		$out = [];
		foreach ( $typography as $profile ) {
			$role = $profile['role'];
			if ( ! isset( $out[ $role ] ) && $role !== 'other' && $profile['values'] ) {
				$out[ $role ] = [ 'values' => $profile['values'], 'source' => 'Extraído' ];
			}
		}
		foreach ( [ 'h4' => 17, 'h5' => 16, 'h6' => 14 ] as $role => $size ) {
			if ( isset( $out[ $role ] ) ) {
				continue;
			}
			$base = $out['h3']['values'] ?? $out['h2']['values'] ?? $out['body']['values'] ?? [];
			if ( ( $base['font_size']['unit'] ?? '' ) !== 'px' || ! isset( $base['font_family'] ) ) {
				continue;
			}
			foreach ( Style::DEVICES as $suffix ) {
				if ( $suffix !== '' ) {
					unset( $base[ 'font_size' . $suffix ], $base[ 'line_height' . $suffix ], $base[ 'letter_spacing' . $suffix ] );
				}
			}
			$base['font_size']['size'] = min( $size, $base['font_size']['size'] );
			$out[ $role ] = [ 'values' => $base, 'source' => 'Inferido de la jerarquía observada' ];
		}
		if ( ! isset( $out['label'] ) ) {
			$base = $out['small']['values'] ?? $out['field']['values'] ?? $out['body']['values'] ?? [];
			if ( $base ) {
				$base['font_weight'] = $out['button']['values']['font_weight'] ?? $base['font_weight'] ?? '600';
				$out['label'] = [ 'values' => $base, 'source' => 'Inferido del texto pequeño y los controles' ];
			}
		}
		return $out;
	}

	private function leading( array $components, array $widgets ): array {
		foreach ( $widgets as $widget ) {
			foreach ( $components as $component ) {
				if ( $widget === $component['widget'] ) {
					return $component['styles'];
				}
			}
		}
		return [];
	}

	private function buttons( array $report, array $profiles, array &$proposal ): void {
		$s = $this->leading( $report['components'], [ 'button' ] );
		$search = $this->leading( $report['components'], [ 'search' ] );
		if ( ! $s && ! $search ) {
			return;
		}
		$values = [];
		$types = [];
		$this->set_typography( $values, $types, 'button_typography_', $profiles['button']['values'] ?? $profiles['search_button']['values'] ?? [] );
		foreach ( [
			'button_text_color' => [ 'color', $s['button_text_color'] ?? $search['submit_text_color_normal'] ?? null ],
			'button_hover_text_color' => [ 'color', $s['hover_color'] ?? $search['submit_text_color_hover'] ?? null ],
			'button_background_color' => [ 'color', $s['background_color'] ?? $search['submit_background_normal_color'] ?? null ],
			'button_hover_background_color' => [ 'color', $s['button_background_hover_color'] ?? $search['submit_background_hover_color'] ?? null ],
			'button_border_border' => [ 'border', $s['border_border'] ?? $search['submit_border_normal_border'] ?? null ],
			'button_border_color' => [ 'color', $s['border_color'] ?? $search['submit_border_normal_color'] ?? null ],
			'button_border_width' => [ 'dimensions', $s['border_width'] ?? $search['submit_border_normal_width'] ?? null ],
			'button_hover_border_border' => [ 'border', $s['border_hover_border'] ?? $search['submit_border_hover_border'] ?? null ],
			'button_hover_border_color' => [ 'color', $s['border_hover_color'] ?? $search['submit_border_hover_color'] ?? null ],
			'button_hover_border_width' => [ 'dimensions', $s['border_hover_width'] ?? $search['submit_border_hover_width'] ?? null ],
			'button_border_radius' => [ 'dimensions', $s['border_radius'] ?? $search['submit_border_radius'] ?? null ],
			'button_hover_border_radius' => [ 'dimensions', $s['border_hover_radius'] ?? null ],
		] as $key => [ $type, $value ] ) {
			$this->set( $values, $types, $key, $type, $value );
		}
		foreach ( [ 'button_background', 'button_hover_background' ] as $key ) {
			if ( isset( $values[ $key . '_color' ] ) ) {
				$this->set( $values, $types, $key . '_background', 'classic', 'classic' );
			}
		}
		foreach ( Style::DEVICES as $device ) {
			$this->set( $values, $types, 'button_padding' . $device, 'dimensions', $s[ 'text_padding' . $device ] ?? ( $s ? null : ( $search[ 'submit_padding' . $device ] ?? null ) ) );
		}
		$this->set_shadow( $values, $types, 'button_box_shadow', $s['button_box_shadow_box_shadow'] ?? $s['box_shadow_box_shadow'] ?? null );
		$this->set_shadow( $values, $types, 'button_hover_box_shadow', $s['button_box_shadow_hover_box_shadow'] ?? $s['box_shadow_hover_box_shadow'] ?? null );
		if ( $values ) {
			$proposal['theme']['buttons'] = $this->row( 'Botón principal', 'Buttons', $values, $types, 'Extraído' );
		}
		$proposal['notes'][] = 'El botón de soporte define el estilo principal cuando existe. El botón del buscador puede conservar su tamaño y espaciado propios.';
	}

	private function fields( array $report, array $profiles, array $palette, array &$proposal ): void {
		$s = $this->leading( $report['components'], [ 'form', 'search', 'search-form' ] );
		if ( ! $s ) {
			return;
		}
		$values = [];
		$types = [];
		$normal_border = $palette['field_border'] ?? null;
		$adjust_border = isset( $palette['field_surface'], $palette['border'] ) && $normal_border === $palette['field_surface'] && isset( $s['search_field_border_normal_border'] );
		$this->set_typography( $values, $types, 'form_field_typography_', $profiles['field']['values'] ?? [] );
		foreach ( [
			'form_field_text_color' => [ 'color', $palette['field_text'] ?? null ],
			'form_field_background_color' => [ 'color', $palette['field_surface'] ?? null ],
			'form_field_border_border' => [ 'border', $s['field_border_border'] ?? $s['search_field_border_normal_border'] ?? ( isset( $s['field_border_width'] ) ? 'solid' : null ) ],
			'form_field_border_width' => [ 'dimensions', $s['field_border_width'] ?? $s['search_field_border_normal_width'] ?? null ],
			'form_field_border_color' => [ 'color', $adjust_border ? $palette['border'] : $normal_border ],
			'form_field_border_radius' => [ 'dimensions', $s['field_border_radius'] ?? $s['search_field_border_radius'] ?? $s['border_radius'] ?? null ],
			'form_field_focus_text_color' => [ 'color', $s['search_field_input_text_color_focus'] ?? null ],
			'form_field_focus_background_color' => [ 'color', $s['search_field_background_focus_color'] ?? null ],
			'form_field_focus_border_border' => [ 'border', $s['search_field_border_focus_border'] ?? null ],
			'form_field_focus_border_width' => [ 'dimensions', $s['search_field_border_focus_width'] ?? null ],
			'form_field_focus_border_color' => [ 'color', $palette['field_focus'] ?? null ],
		] as $key => [ $type, $value ] ) {
			$this->set( $values, $types, $key, $type, $value );
		}
		foreach ( Style::DEVICES as $device ) {
			$this->set( $values, $types, 'form_field_padding' . $device, 'dimensions', $s[ 'field_padding' . $device ] ?? $s[ 'search_field_padding' . $device ] ?? null );
		}
		if ( $values ) {
			$source = $adjust_border ? 'Extraído, borde neutro inferido para campos sin marco' : 'Extraído';
			$proposal['theme']['fields'] = $this->row( 'Campos y foco', 'Form Fields', $values, $types, $source );
		}
		if ( isset( $palette['placeholder'] ) ) {
			$proposal['theme']['placeholder'] = $this->row( 'Texto de campo vacío', 'Form Fields', [ 'form_field_placeholder_color' => $palette['placeholder'] ], [ 'form_field_placeholder_color' => 'color' ], 'Extraído, sujeto a control nativo' );
		}
		$values = [];
		$types = [];
		$this->set_typography( $values, $types, 'form_label_typography_', $profiles['label']['values'] ?? [] );
		$this->set( $values, $types, 'form_label_color', 'color', $palette['label'] ?? $palette['heading'] ?? null );
		if ( $values ) {
			$proposal['theme']['labels'] = $this->row( 'Etiquetas', 'Form Fields', $values, $types, $profiles['label']['source'] ?? 'Extraído' );
		}
	}

	private function images( array $report, array &$proposal ): void {
		$s = $this->leading( $report['components'], [ 'image' ] );
		$values = [];
		$types = [];
		foreach ( [
			'image_border_radius' => 'dimensions', 'image_border_border' => 'border',
			'image_border_width' => 'dimensions', 'image_border_color' => 'color',
		] as $key => $type ) {
			$this->set( $values, $types, $key, $type, $s[ $key ] ?? null );
		}
		$this->set_shadow( $values, $types, 'image_box_shadow', $s['image_box_shadow_box_shadow'] ?? null );
		if ( $values ) {
			$proposal['theme']['images'] = $this->row( 'Imágenes', 'Images', $values, $types, 'Extraído, revisar el alcance antes de aplicar' );
		} else {
			$proposal['notes'][] = 'La referencia carece de imágenes con borde, radio o sombra explícitos. Se conserva el estilo de imágenes del sitio.';
		}
	}

	private function layout( array $report, array $palette, array &$proposal ): void {
		foreach ( $report['spacing'] as $spacing ) {
			if ( $spacing['control'] === 'boxed_width' || $spacing['control'] === 'layout_width' ) {
				$proposal['theme']['width'] = $this->row( 'Ancho del contenido', 'Layout', [ 'container_width' => $spacing['value'] ], [ 'container_width' => 'slider' ], 'Extraído de contenedores encuadrados' );
				break;
			}
		}
		foreach ( $report['spacing'] as $spacing ) {
			$v = $spacing['value'];
			if ( in_array( $spacing['control'], [ 'flex_gap', 'grid_gaps' ], true ) && isset( $v['row'], $v['column'] ) && ( (float) $v['row'] > 0 || (float) $v['column'] > 0 ) ) {
				$proposal['theme']['gaps'] = $this->row( 'Separación entre elementos', 'Layout', [ 'space_between_widgets' => $v ], [ 'space_between_widgets' => 'gaps' ], 'Inferido de la separación local' );
				break;
			}
		}
		$proposal['theme']['page_layout'] = $this->row( 'Diseño predeterminado de página', 'Layout', [ 'default_page_template' => $report['page_layout'] ?? 'default' ], [ 'default_page_template' => 'layout' ], $report['page_layout'] ? 'Extraído' : 'Sin evidencia, se propone conservar el tema' );
		if ( isset( $palette['surface'] ) ) {
			$proposal['theme']['background'] = $this->row( 'Fondo del sitio', 'Background', [
				'body_background_background' => 'classic', 'body_background_color' => $palette['surface'],
			], [ 'body_background_background' => 'classic', 'body_background_color' => 'color' ], 'Inferido de la superficie principal' );
		}
	}

	private function set_typography( array &$values, array &$types, string $prefix, array $fields ): void {
		if ( ! $fields ) {
			return;
		}
		$this->set( $values, $types, $prefix . 'typography', 'typography_switch', 'custom' );
		$definitions = Style::type_fields();
		foreach ( $fields as $key => $value ) {
			$this->set( $values, $types, $prefix . $key, $definitions[ $key ], $value );
		}
	}

	private function set_shadow( array &$values, array &$types, string $prefix, $shadow ): void {
		if ( $shadow !== null ) {
			$this->set( $values, $types, $prefix . '_box_shadow_type', 'toggle', 'yes' );
			$this->set( $values, $types, $prefix . '_box_shadow', 'shadow', $shadow );
		}
	}

	private function set( array &$values, array &$types, string $key, string $type, $value ): void {
		if ( $value !== null ) {
			$values[ $key ] = $value;
			$types[ $key ] = $type;
		}
	}

	private function row( string $title, string $role, array $values, array $types, string $source, bool $system = false ): array {
		return [
			'title' => $title, 'role' => $role, 'values' => $values,
			'types' => array_intersect_key( $types, $values ), 'source' => $source, 'system' => $system,
		];
	}
}
