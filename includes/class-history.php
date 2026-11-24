<?php

namespace Cort\ElementorGlobalStyles;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class History {

	const TYPE = 'cort_egs_backup';
	const LOCK = 'cort_egs_write_lock';

	public static function register(): void {
		register_post_type( self::TYPE, [
			'label' => 'Respaldos de Cort Global Styles',
			'public' => false, 'publicly_queryable' => false, 'show_ui' => false,
			'show_in_rest' => false, 'can_export' => false, 'exclude_from_search' => true,
			'rewrite' => false, 'query_var' => false, 'supports' => [ 'title' ],
		] );
	}

	public function protect( callable $action ) {
		$lock = [ 'owner' => wp_generate_uuid4(), 'at' => time() ];
		$current = get_option( self::LOCK );
		// Recover abandoned operations from expired requests.
		if ( is_array( $current ) && (int) ( $current['at'] ?? 0 ) < time() - 1800 && $current === get_option( self::LOCK ) ) {
			delete_option( self::LOCK );
		}
		if ( ! add_option( self::LOCK, $lock, '', false ) ) {
			throw new RuntimeException( 'Hay otra operación de Cort Global Styles en curso. Vuelve a intentar cuando termine.' );
		}
		try {
			return $action();
		} finally {
			if ( $lock === get_option( self::LOCK ) ) {
				delete_option( self::LOCK );
			}
		}
	}

	public function create( array $plan, array $versions ): int {
		$id = wp_insert_post( [
			'post_type' => self::TYPE, 'post_status' => 'private',
			'post_title' => $plan['title'], 'post_author' => get_current_user_id(),
		], true );
		if ( ! $id || is_wp_error( $id ) ) {
			throw new RuntimeException( 'No se pudo crear el respaldo. Los estilos no se han aplicado.' );
		}
		$record = [
			'plan_id' => $plan['id'], 'kind' => $plan['kind'], 'kit_id' => $plan['kit_id'],
			'target' => $plan['target'], 'title' => $plan['title'], 'before' => $plan['before'],
			'changes' => $plan['changes'], 'collection_defaults' => $plan['collection_defaults'] ?? [], 'metadata' => $plan['metadata'] ?? [],
			'at' => time(), 'versions' => $versions, 'actor' => get_current_user_id(),
		];
		if ( ! add_post_meta( $id, '_cort_egs_record', wp_slash( $record ), true ) ) {
			throw new RuntimeException( 'No se pudo guardar el contenido del respaldo. Los estilos no se han aplicado.' );
		}
		$this->status( $id, 'prepared', [ 'Respaldo creado antes de aplicar el plan.' ] );
		return (int) $id;
	}

	public function status( int $id, string $state, array $messages ): void {
		$saved = add_post_meta( $id, '_cort_egs_event', wp_slash( [
			'state' => $state, 'notes' => array_slice( $messages, 0, 200 ), 'at' => time(), 'actor' => get_current_user_id(),
		] ) );
		$states = get_post_meta( $id, '_cort_egs_event', false );
		if ( ! $saved || ! $states || end( $states )['state'] !== $state ) {
			throw new RuntimeException( 'WordPress no pudo registrar el estado del respaldo #' . $id . '.' );
		}
	}

	public function get( int $id ): array {
		if ( ! $id || ! current_user_can( 'manage_options' ) || get_post_type( $id ) !== self::TYPE ) {
			throw new RuntimeException( 'El respaldo solicitado no existe.' );
		}
		$record = get_post_meta( $id, '_cort_egs_record', true );
		if ( ! is_array( $record ) || ! isset( $record['changes'], $record['before'], $record['kind'] ) ) {
			throw new RuntimeException( 'El respaldo no tiene datos verificables.' );
		}
		$record['id'] = $id;
		$record['events'] = get_post_meta( $id, '_cort_egs_event', false );
		$record['state'] = $record['events'] ? end( $record['events'] )['state'] : '';
		return $record;
	}

	public function page( int $page = 1 ): array {
		$query = new \WP_Query( [
			'post_type' => self::TYPE, 'post_status' => 'private', 'fields' => 'ids',
			'posts_per_page' => 12, 'paged' => $page, 'orderby' => 'ID', 'order' => 'DESC',
		] );
		$items = [];
		foreach ( $query->posts as $id ) {
			$events = get_post_meta( $id, '_cort_egs_event', false );
			if ( ! $events ) {
				continue;
			}
			$items[] = [ 'id' => (int) $id, 'title' => get_the_title( $id ), 'last' => end( $events ), 'events' => $events ];
		}
		return [ 'items' => $items, 'pages' => (int) $query->max_num_pages ];
	}

	public function activity( string $action, string $state, string $message ): void {
		$data = [
			'action' => $action, 'state' => $state, 'message' => $message,
			'at' => time(), 'actor' => get_current_user_id(),
		];
		update_option( 'cort_egs_last_activity', $data, false );
		if ( $action === 'apply' && $state === 'success' ) {
			update_option( 'cort_egs_last_applied', $data, false );
		}
	}
}
