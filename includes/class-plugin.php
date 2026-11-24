<?php

namespace Cort\ElementorGlobalStyles;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	const SLUG = 'cort-global-styles';
	const POST_ACTION = 'cort_egs_action';

	private $elementor;
	private $kits;
	private $references;
	private $history;

	public function __construct() {
		$this->elementor = new Elementor_Compatibility();
		$this->kits = new Kit_Changes();
		$this->references = new Global_Reference_Manager();
		$this->history = new History();
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 99 );
		add_action( 'admin_post_' . self::POST_ACTION, [ $this, 'dispatch' ] );
	}

	public function menu(): void {
		global $menu;
		$parent = 'options-general.php';
		foreach ( (array) $menu as $entry ) {
			if ( ( $entry[2] ?? '' ) === 'elementor' ) {
				$parent = 'elementor';
				break;
			}
		}
		add_submenu_page( $parent, 'Cort Global Styles', 'Cort Global Styles', 'manage_options', self::SLUG, [ $this, 'view' ] );
	}

	public static function url( array $args = [] ): string {
		$parent = defined( 'ELEMENTOR_VERSION' ) ? 'admin.php' : 'options-general.php';
		return add_query_arg( [ 'page' => self::SLUG ] + $args, admin_url( $parent ) );
	}

	private static function transient( string $key ): string {
		return 'cort_egs_' . $key . '_' . get_current_user_id();
	}

	private function save_draft( string $key, array $value ): void {
		if ( ! set_transient( self::transient( $key ), $value, 8 * HOUR_IN_SECONDS ) && get_transient( self::transient( $key ) ) !== $value ) {
			throw new RuntimeException( 'No se pudo guardar la vista previa. Intenta con un documento más pequeño.' );
		}
	}

	private function draft( string $key ): array {
		return (array) get_transient( self::transient( $key ) );
	}

	public function view(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Necesitas permisos de administración.' );
		}
		$notice = $this->draft( 'notice' );
		delete_transient( self::transient( 'notice' ) );
		$query = isset( $_GET['doc_search'] ) && is_string( $_GET['doc_search'] ) ? sanitize_text_field( wp_unslash( $_GET['doc_search'] ) ) : '';
		$history_page = isset( $_GET['history_page'] ) && is_scalar( $_GET['history_page'] ) ? max( 1, absint( $_GET['history_page'] ) ) : 1;
		$view = new Admin_View();
		$view->render(
			$this->elementor->status(), $this->draft( 'analysis' ), $this->draft( 'plan' ),
			$this->elementor->list_documents( $query ), $this->history->page( $history_page ),
			$notice, $query, $history_page
		);
	}

	public function dispatch(): void {
		if ( ! current_user_can( 'manage_options' ) || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			wp_die( 'Necesitas permisos de administración para realizar esta acción.', '', [ 'response' => 403 ] );
		}
		$task = $this->input( 'task' );
		$tasks = [ 'analyze', 'preview', 'backup', 'apply', 'css', 'link_preview', 'link_apply', 'restore', 'export' ];
		if ( ! in_array( $task, $tasks, true ) ) {
			wp_die( 'Acción desconocida.', '', [ 'response' => 400 ] );
		}
		check_admin_referer( self::POST_ACTION . '_' . $task );
		$success = true;
		try {
			$message = $this->perform( $task );
		} catch ( RuntimeException $error ) {
			$success = false;
			$message = $error->getMessage();
		} catch ( \Throwable $error ) {
			$success = false;
			$message = 'WordPress o Elementor rechazó la operación. Revisa el estado del Kit y los respaldos antes de reintentar.';
		}
		$this->history->activity( $task, $success ? 'success' : 'error', $message );
		set_transient( self::transient( 'notice' ), [ 'success' => $success, 'message' => $message ], 5 * MINUTE_IN_SECONDS );
		$anchor = $success ? ( $task === 'analyze' ? '#cort-analysis' : ( in_array( $task, [ 'preview', 'link_preview', 'backup' ], true ) ? '#cort-preview' : '#cort-reports' ) ) : '';
		wp_safe_redirect( self::url() . $anchor );
		exit;
	}

	private function input( string $name ): string {
		$value = $_POST[ $name ] ?? '';
		return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
	}

	private function posted_array( string $name ): array {
		return isset( $_POST[ $name ] ) && is_array( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : [];
	}

	private function perform( string $task ): string {
		switch ( $task ) {
			case 'analyze':
				return $this->analyze();
			case 'preview':
				return $this->preview_kit();
			case 'link_preview':
				$kit = $this->elementor->kit();
				$document = $this->elementor->document( absint( $this->input( 'document_id' ) ) );
				$plan = $this->references->preview( $document, $kit, $this->elementor );
				$this->set_plan( $plan );
				return sprintf( 'Vista previa creada con %d referencias en %d elementos.', count( $plan['changes'] ), count( array_unique( array_column( $plan['changes'], 'element_id' ) ) ) );
			case 'backup':
			case 'apply':
			case 'link_apply':
				return $this->protect_plan( $task );
			case 'restore':
				return $this->history->protect( function (): string {
					return $this->restore( absint( $this->input( 'backup_id' ) ) );
				} );
			case 'css':
				$this->elementor->kit();
				return $this->history->protect( [ $this->elementor, 'clear_css' ] );
			case 'export':
				$this->export();
		}
		return '';
	}

	private function analyze(): string {
		$analyzer = new Design_Analyzer();
		if ( $this->input( 'source' ) === 'json' ) {
			$upload = $_FILES['reference'] ?? [];
			$name = isset( $upload['name'] ) && is_string( $upload['name'] ) ? sanitize_file_name( wp_unslash( $upload['name'] ) ) : '';
			$path = $upload['tmp_name'] ?? '';
			$format = wp_check_filetype( $name, [ 'json' => 'application/json' ] );
			if ( ( $upload['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ( $format['ext'] ?? false ) !== 'json'
				|| ! is_string( $path ) || ! is_uploaded_file( $path ) || filesize( $path ) > Design_Analyzer::MAX_BYTES ) {
				throw new RuntimeException( 'Sube un archivo .json exportado de Elementor, de hasta 8 MiB y dentro del límite de tu servidor.' );
			}
			$content = file_get_contents( $path );
			if ( ! is_string( $content ) ) {
				throw new RuntimeException( 'No se pudo leer el archivo temporal.' );
			}
			$report = $analyzer->from_json( $content, $name );
		} else {
			$doc = $this->elementor->document( absint( $this->input( 'document_id' ) ) );
			$report = $analyzer->analyze( $doc['elements'], $doc['settings'], $this->elementor->kit()['effective'], [
				'kind' => 'wordpress', 'title' => sanitize_text_field( $doc['title'] ), 'id' => $doc['id'],
				'sha256' => hash( 'sha256', $doc['raw']['value'] ),
			] );
		}
		$proposal = ( new Design_Proposal() )->build( $report );
		$this->save_draft( 'analysis', [ 'id' => wp_generate_uuid4(), 'report' => $report, 'proposal' => $proposal ] );
		delete_transient( self::transient( 'plan' ) );
		return sprintf( 'Análisis completado. %d elementos, %d colores y %d perfiles tipográficos.', $report['element_count'], count( $report['colors'] ), count( $report['typography'] ) );
	}

	private function preview_kit(): string {
		$analysis = $this->draft( 'analysis' );
		if ( empty( $analysis['id'] ) || ! hash_equals( $analysis['id'], $this->input( 'analysis_id' ) ) ) {
			throw new RuntimeException( 'El análisis cambió o caducó. Vuelve a analizar la referencia.' );
		}
		$proposal = $analysis['proposal'];
		$chosen = $this->posted_array( 'choose' );
		$edits = $this->posted_array( 'values' );
		$selection = [];
		foreach ( [ 'colors', 'fonts', 'theme' ] as $group ) {
			foreach ( $proposal[ $group ] as $key => &$row ) {
				$enabled = ( $chosen[ $group ][ $key ] ?? '' ) === '1';
				if ( $enabled ) {
					$selection[ $group ][ $key ] = true;
				}
				foreach ( $row['types'] as $name => $type ) {
					if ( ! array_key_exists( $name, (array) ( $edits[ $group ][ $key ] ?? [] ) ) ) {
						continue;
					}
					$value = Style::form_value( $type, $edits[ $group ][ $key ][ $name ], $name );
					if ( $value === null && $enabled ) {
						throw new RuntimeException( 'Valor inválido para ' . $row['title'] . ' · ' . $name . '. Usa el formato del valor original.' );
					}
					if ( $value !== null ) {
						$row['values'][ $name ] = $value;
					}
				}
			}
			unset( $row );
		}
		$plan = $this->kits->preview( $this->elementor->kit(), $proposal, $selection );
		$plan['notes'][] = sprintf( 'Filas seleccionadas %d. Las demás filas permanecen en revisión.', array_sum( array_map( 'count', $selection ) ) );
		$this->save_draft( 'analysis', array_replace( $analysis, [ 'edits' => $proposal, 'selection' => $selection ] ) );
		$this->set_plan( $plan );
		return sprintf( 'Vista previa creada con %d cambios previstos.', count( $plan['changes'] ) );
	}

	private function set_plan( array $plan ): void {
		$plan['id'] = wp_generate_uuid4();
		$plan['version'] = $this->elementor->status()['core'];
		$this->save_draft( 'plan', $plan );
	}

	private function protect_plan( string $task ): string {
		$plan = $this->draft( 'plan' );
		if ( empty( $plan['id'] ) || ! hash_equals( $plan['id'], $this->input( 'plan_id' ) ) || empty( $plan['changes'] ) ) {
			throw new RuntimeException( 'Genera otra vista previa con cambios antes de aplicar o respaldar.' );
		}
		if ( $task !== 'backup' && ( $task === 'apply' ? 'kit' : 'document' ) !== $plan['kind'] ) {
			throw new RuntimeException( 'El plan y la acción no coinciden.' );
		}
		return $this->history->protect( function () use ( $task, $plan ): string {
			$kit = $this->elementor->kit();
			if ( $kit['id'] !== $plan['kit_id'] || $this->elementor->status()['core'] !== $plan['version'] ) {
				throw new RuntimeException( 'El Kit o la versión de Elementor cambió. Genera otra vista previa.' );
			}
			$key = Elementor_Compatibility::KIT_META;
			$state = $kit['state'];
			if ( $plan['kind'] === 'document' ) {
				$document = $this->elementor->document( $plan['target'] );
				$key = Elementor_Compatibility::DATA_META;
				$state = $document['raw'];
				if ( ! hash_equals( $plan['tokens'], $this->references->fingerprint( $kit ) ) ) {
					throw new RuntimeException( 'Los estilos globales cambiaron. Genera otra vista previa de vínculos.' );
				}
			}
			if ( $state !== $plan['before'] || $this->elementor->is_locked_by_editor( $plan['target'] ) ) {
				throw new RuntimeException( 'El documento cambió o está abierto por otro editor. Genera otra vista previa cuando esté disponible.' );
			}
			$backup = isset( $plan['backup_id'] ) ? $this->history->get( $plan['backup_id'] ) : [];
			$id = ( $backup['state'] ?? '' ) === 'prepared' && ( $backup['plan_id'] ?? '' ) === $plan['id']
				? $backup['id'] : $this->history->create( $plan, $this->elementor->status() );
			$plan['backup_id'] = $id;
			$this->save_draft( 'plan', $plan );
			if ( $task === 'backup' ) {
				return 'Respaldo #' . $id . ' disponible para esta vista previa.';
			}
			$this->history->status( $id, 'pending', [ 'Escritura iniciada.' ] );
			try {
				$this->elementor->write( $plan['target'], $key, $plan['before'], $plan['after'] );
			} catch ( \Throwable $error ) {
				$this->history->status( $id, 'error', [ 'La escritura no pudo verificarse. Revisa el estado actual antes de restaurar.' ] );
				throw $error;
			}
			$css = $this->elementor->clear_css();
			$message = sprintf( 'Se aplicaron %d cambios. Respaldo #%d. %s', count( $plan['changes'] ), $id, $css );
			$details = array_map( static function ( array $change ): string {
				$target = isset( $change['reference'] ) ? $change['element_id'] . ' · ' . $change['reference']
					: ( $change['key'] ?? $change['collection'] . '/' . $change['id'] );
				return $change['label'] . ' · ' . $target;
			}, $plan['changes'] );
			$this->history->status( $id, 'applied', array_merge( [ $message ], $plan['notes'], $details ) );
			delete_transient( self::transient( 'plan' ) );
			return $message;
		} );
	}

	private function restore( int $id ): string {
		$backup = $this->history->get( $id );
		if ( ! in_array( $backup['state'], [ 'pending', 'applied', 'partial', 'error' ], true ) ) {
			throw new RuntimeException( 'Este respaldo carece de cambios aplicados pendientes de restauración.' );
		}
		$kit = $this->elementor->kit();
		if ( $kit['id'] !== $backup['kit_id'] || $this->elementor->is_locked_by_editor( $backup['target'] ) ) {
			throw new RuntimeException( 'El Kit activo no coincide con el respaldo o hay otro editor trabajando en el documento.' );
		}
		$key = Elementor_Compatibility::KIT_META;
		$state = $kit['state'];
		if ( $backup['kind'] === 'kit' ) {
			$result = $this->kits->restore( $kit, $backup, $this->elementor );
		} else {
			$key = Elementor_Compatibility::DATA_META;
			$doc = $this->elementor->document( $backup['target'] );
			$state = $doc['raw'];
			$result = $this->references->restore( $doc, $backup );
		}
		if ( $result['count'] ) {
			$this->elementor->write( $backup['target'], $key, $state, $result['after'] );
			$result['notes'][] = $this->elementor->clear_css();
		}
		$conflicts = count( $result['notes'] ) - ( $result['count'] ? 1 : 0 );
		$message = sprintf( 'Respaldo #%d: %d cambios restaurados y %d omitidos por conflicto o uso posterior.', $id, $result['count'], $conflicts );
		$this->history->status( $id, $conflicts ? 'partial' : 'restored', array_merge( [ $message ], $result['notes'] ) );
		delete_transient( self::transient( 'plan' ) );
		return $message;
	}

	private function export(): void {
		$analysis = $this->draft( 'analysis' );
		if ( empty( $analysis['report'] ) ) {
			throw new RuntimeException( 'Analiza una referencia antes de descargar el reporte.' );
		}
		$report = $analysis['report'] + [ 'proposal' => $analysis['proposal'] ];
		$json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'El reporte no se pudo serializar.' );
		}
		$this->history->activity( 'export', 'success', 'Reporte de análisis exportado.' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="homepage-design-analysis.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON attachment.
		exit;
	}
}
