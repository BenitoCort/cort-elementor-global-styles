<?php

namespace Cort\ElementorGlobalStyles;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Elementor_Compatibility {

	const MINIMUM = '3.24.0';
	const KIT_META = '_elementor_page_settings';
	const DATA_META = '_elementor_data';

	public function status(): array {
		$core = defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '';
		$pro = defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : '';
		$manager = $this->manager();
		return [
			'core' => $core,
			'pro' => $pro,
			'kit_id' => $manager && is_callable( [ $manager, 'get_active_id' ] ) ? (int) $manager->get_active_id() : 0,
			'version_supported' => $core !== '' && version_compare( $core, self::MINIMUM, '>=' ) && version_compare( $core, '5.0.0', '<' ),
		];
	}

	private function instance() {
		return class_exists( '\Elementor\Plugin' ) && is_callable( [ '\Elementor\Plugin', 'instance' ] ) && did_action( 'elementor/loaded' ) ? \Elementor\Plugin::instance() : null;
	}

	private function manager() {
		$plugin = $this->instance();
		return $plugin->kits_manager ?? null;
	}

	public function kit(): array {
		$status = $this->status();
		if ( ! $status['version_supported'] ) {
			throw new RuntimeException( 'Activa Elementor 3.24–4.x para acceder a los ajustes del Kit. La carga y el análisis de JSON siguen disponibles.' );
		}
		$manager = $this->manager();
		$id = $status['kit_id'];
		if ( ! $id || ! is_callable( [ $manager, 'get_active_kit' ] ) || ! is_callable( [ $manager, 'is_kit' ] ) || ! $manager->is_kit( $id )
			|| ! current_user_can( 'edit_post', $id ) || get_post_status( $id ) === 'trash' ) {
			throw new RuntimeException( 'No se encontró un Kit activo editable. Abre Site Settings en Elementor y verifica el Kit activo.' );
		}
		if ( ! defined( '\Elementor\Core\Settings\Page\Manager::META_KEY' ) || \Elementor\Core\Settings\Page\Manager::META_KEY !== self::KIT_META ) {
			throw new RuntimeException( 'Esta versión usa una clave de configuración del Kit que el plugin no reconoce.' );
		}
		$kit = $manager->get_active_kit();
		if ( ! is_callable( [ $kit, 'get_main_id' ] ) || (int) $kit->get_main_id() !== $id || ! is_callable( [ $kit, 'get_stack' ] ) || ! is_callable( [ $kit, 'get_settings' ] ) ) {
			throw new RuntimeException( 'Las APIs de lectura de Site Settings no están disponibles.' );
		}
		$stack = $kit->get_stack();
		$controls = ( $stack['controls'] ?? [] ) + ( $stack['style_controls'] ?? [] );
		$effective = $kit->get_settings();
		$stored = $this->raw_meta( $id, self::KIT_META );
		if ( ! is_array( $effective ) || ( $stored['exists'] && ! is_array( $stored['value'] ) ) ) {
			throw new RuntimeException( 'La configuración guardada del Kit tiene un formato incompatible.' );
		}
		return [
			'id' => $id, 'name' => get_the_title( $id ), 'controls' => $controls,
			'state' => $stored, 'effective' => $effective,
		];
	}

	public function document( int $id ): array {
		$this->kit();
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, [ 'page', 'elementor_library' ], true ) || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) || ! current_user_can( 'edit_post', $id ) ) {
			throw new RuntimeException( 'Selecciona una página o plantilla de Elementor que puedas editar.' );
		}
		$plugin = $this->instance();
		if ( ! is_callable( [ $plugin->documents ?? null, 'get' ] ) || ! defined( '\Elementor\Core\Base\Document::ELEMENTOR_DATA_META_KEY' ) || \Elementor\Core\Base\Document::ELEMENTOR_DATA_META_KEY !== self::DATA_META ) {
			throw new RuntimeException( 'La API de documentos de Elementor no está disponible en esta instalación.' );
		}
		$doc = $plugin->documents->get( $id );
		if ( ! $doc || ! is_callable( [ $doc, 'is_built_with_elementor' ] ) || ! $doc->is_built_with_elementor()
			|| ! is_callable( [ $doc, 'get_name' ] ) || $doc->get_name() === 'kit' ) {
			throw new RuntimeException( 'Esta página o plantilla carece de un diseño guardado en Elementor.' );
		}
		$raw = $this->raw_meta( $id, self::DATA_META );
		$elements = is_string( $raw['value'] ) ? json_decode( $raw['value'], true, 128 ) : null;
		if ( ! is_array( $elements ) || ! $elements || strlen( $raw['value'] ) > Design_Analyzer::MAX_BYTES ) {
			throw new RuntimeException( 'El documento está vacío, tiene datos inválidos o excede 8 MiB.' );
		}
		$metadata = [];
		foreach ( [ self::KIT_META, '_elementor_edit_mode', '_elementor_template_type', '_elementor_version', '_elementor_pro_version', '_wp_page_template' ] as $key ) {
			$metadata[ $key ] = get_post_meta( $id, $key, true );
		}
		$settings = is_array( $metadata[ self::KIT_META ] ) ? $metadata[ self::KIT_META ] : [];
		$settings['template'] = $metadata['_wp_page_template'];
		return [
			'id' => $id, 'title' => get_the_title( $id ), 'raw' => $raw, 'elements' => $elements,
			'metadata' => $metadata, 'settings' => $settings,
		];
	}

	public function list_documents( string $search = '', int $limit = 80 ): array {
		$ids = get_posts( [
			'post_type' => [ 'page', 'elementor_library' ],
			'post_status' => [ 'publish', 'draft', 'private', 'pending', 'future' ],
			'numberposts' => $limit, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids',
			's' => $search, 'meta_key' => self::DATA_META,
			'no_found_rows' => true, 'suppress_filters' => false,
		] );
		$front = (int) get_option( 'page_on_front' );
		if ( $front && $search === '' && metadata_exists( 'post', $front, self::DATA_META ) ) {
			$ids = array_unique( array_merge( [ $front ], $ids ) );
		}
		$kit_id = $this->status()['kit_id'];
		$result = [];
		foreach ( $ids as $id ) {
			if ( (int) $id !== $kit_id && current_user_can( 'edit_post', $id ) ) {
				$result[ $id ] = get_the_title( $id ) ?: '(Sin título)';
			}
		}
		return $result;
	}

	public static function supported_control( array $controls, string $key, string $value_type, $value ): bool {
		$control = $controls[ $key ] ?? null;
		if ( $control === null && preg_match( '/^(.+?)_(tablet_extra|mobile_extra|widescreen|laptop|tablet|mobile)$/D', $key, $parts ) && isset( $controls[ $parts[1] ]['responsive'] ) ) {
			$control = $controls[ $parts[1] ];
		}
		$expected = [
			'color' => [ 'color' ], 'font' => [ 'font', 'select' ], 'slider' => [ 'slider' ],
			'dimensions' => [ 'dimensions' ], 'gaps' => [ 'gaps' ], 'shadow' => [ 'box_shadow' ],
			'weight' => [ 'select' ], 'transform' => [ 'select' ], 'font_style' => [ 'select' ],
			'decoration' => [ 'select' ], 'border' => [ 'select' ], 'layout' => [ 'select' ],
			'typography_switch' => [ 'popover_toggle' ], 'toggle' => [ 'switcher', 'popover_toggle' ],
			'position' => [ 'select' ], 'classic' => [ 'choose', 'select' ],
		];
		if ( ! $control || ! in_array( $control['type'] ?? '', $expected[ $value_type ] ?? [], true ) ) {
			return false;
		}
		if ( isset( $value['unit'] ) && ! empty( $control['size_units'] ) && ! in_array( $value['unit'], $control['size_units'], true ) ) {
			return false;
		}
		return ! is_string( $value ) || empty( $control['options'] ) || array_key_exists( $value, $control['options'] ) || $value_type === 'font';
	}

	public function native_binding( array $node, string $key, string $kind ): bool {
		$plugin = $this->instance();
		if ( ! is_callable( [ $plugin->elements_manager ?? null, 'create_element_instance' ] ) ) {
			return false;
		}
		$element = $plugin->elements_manager->create_element_instance( $node );
		if ( ! $element || ! is_callable( [ $element, 'get_stack' ] ) ) {
			return false;
		}
		$stack = $element->get_stack();
		$c = ( $stack['controls'] ?? [] ) + ( $stack['style_controls'] ?? [] );
		$control = $c[ $key ] ?? [];
		if ( ( $control['global']['active'] ?? true ) === false ) {
			return false;
		}
		if ( $kind === 'colors' ) {
			return ( $control['type'] ?? '' ) === 'color' && ! empty( $control['selectors'] );
		}
		$prefix = substr( $key, 0, -strlen( 'typography' ) );
		return ( $control['type'] ?? '' ) === 'popover_toggle'
			&& ( $control['groupType'] ?? '' ) === 'typography'
			&& ! empty( $c[ $prefix . 'font_size' ]['selectors'] );
	}

	public function raw_meta( int $id, string $key ): array {
		$values = get_post_meta( $id, $key, false );
		if ( count( $values ) > 1 ) {
			throw new RuntimeException( 'Se encontraron registros duplicados de metadatos. Se requiere revisar el documento en Elementor.' );
		}
		return [ 'exists' => count( $values ) === 1, 'value' => $values[0] ?? null ];
	}

	/**
	 * Elementor uses these native WordPress post-meta keys. A granular write avoids
	 * Document::save() rewriting document content and preserves unowned settings.
	 */
	public function write( int $id, string $key, array $before, array $after ): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $id ) || ! in_array( $key, [ self::KIT_META, self::DATA_META ], true ) ) {
			throw new RuntimeException( 'Permisos o clave de escritura inválidos.' );
		}
		if ( $key === self::KIT_META && $id !== $this->status()['kit_id'] ) {
			throw new RuntimeException( 'El Kit activo cambió. Genera otra vista previa.' );
		}
		if ( $before !== $this->raw_meta( $id, $key ) ) {
			throw new RuntimeException( 'El documento cambió desde la vista previa. Genera otra vista previa.' );
		}
		if ( $before === $after ) {
			return;
		}
		if ( ! $after['exists'] ) {
			delete_metadata( 'post', $id, $key, wp_slash( $before['value'] ) );
		} elseif ( ! $before['exists'] ) {
			add_post_meta( $id, $key, wp_slash( $after['value'] ), true );
		} else {
			update_post_meta( $id, $key, wp_slash( $after['value'] ), $before['value'] );
		}
		if ( $after !== $this->raw_meta( $id, $key ) ) {
			throw new RuntimeException( 'WordPress no confirmó la escritura. Revisa el respaldo antes de volver a intentar.' );
		}
	}

	public function is_locked_by_editor( int $id ): bool {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		return (bool) wp_check_post_lock( $id );
	}

	public function clear_css(): string {
		$plugin = $this->instance();
		if ( ! is_callable( [ $plugin->files_manager ?? null, 'clear_cache' ] ) ) {
			return 'No está disponible la función de caché. Usa Elementor → Tools → Clear Files & Data.';
		}
		try {
			$plugin->files_manager->clear_cache();
		} catch ( \Throwable $error ) {
			return 'Elementor notificó un error al limpiar su caché. Usa Elementor → Tools → Clear Files & Data.';
		}
		return 'Caché de Elementor invalidada. Elementor genera los archivos de nuevo al cargar las páginas.';
	}

	public function token_in_use( string $kind, string $id ): bool {
		$needle = $kind . '?id=' . $id . '"';
		$types = array_values( array_diff( get_post_types( [], 'names' ), [ 'revision' ] ) );
		$statuses = array_values( array_diff( get_post_stati(), [ 'inherit', 'auto-draft', 'trash' ] ) );
		$q = new \WP_Query( [
			'post_type' => $types, 'post_status' => $statuses, 'fields' => 'ids',
			'posts_per_page' => 1, 'no_found_rows' => true, 'ignore_sticky_posts' => true, 'suppress_filters' => true,
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				[ 'key' => self::DATA_META, 'value' => $needle, 'compare' => 'LIKE' ],
				[ 'key' => self::KIT_META, 'value' => $needle, 'compare' => 'LIKE' ],
			],
		] );
		return (bool) $q->posts;
	}
}
