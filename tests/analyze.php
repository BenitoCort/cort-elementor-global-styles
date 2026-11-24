<?php
/**
 * Run with WordPress CLI and the plugin active:
 * wp eval-file wp-content/plugins/cort-elementor-global-styles/tests/analyze.php /path/to/crm-knowledge-base-homepage.json
 */

use Cort\ElementorGlobalStyles\Design_Analyzer;
use Cort\ElementorGlobalStyles\Design_Proposal;
use Cort\ElementorGlobalStyles\Style;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
};

$path = $args[0] ?? '';
$assert( is_readable( $path ) && class_exists( Design_Analyzer::class ), 'Activa el plugin y proporciona el archivo de referencia.' );
$analyzer = new Design_Analyzer();
$report = $analyzer->from_json( file_get_contents( $path ), basename( $path ) );
$proposal = ( new Design_Proposal() )->build( $report );

$assert( $report['element_count'] === 114, 'El inventario debe contener los 114 elementos.' );
$assert( $proposal['colors']['primary']['values']['color'] === '#2563EB', 'El azul principal debe venir del botón.' );
$assert( $proposal['colors']['accent']['values']['color'] === '#1D4BA8', 'El azul del enlace debe conservarse.' );
$assert( $proposal['fonts']['primary']['values']['font_family'] === 'Inter', 'La familia original debe conservarse.' );
$assert( $proposal['fonts']['primary']['values']['font_size_mobile']['size'] === 37, 'El tamaño móvil del H1 debe conservarse.' );
$assert( $proposal['theme']['h4']['source'] !== 'Extraído', 'H4 debe registrarse como inferencia.' );
$assert( $proposal['theme']['fields']['values']['form_field_focus_border_border'] === 'solid', 'El foco necesita un borde válido.' );
$assert( ! isset( $proposal['theme']['images'] ), 'La portada no justifica un estilo de imágenes.' );
$assert( Style::color( 'rgb(37, 99, 235)' ) === '#2563EB', 'Los formatos equivalentes de color deben normalizarse.' );
$assert( Style::color( 'rgba(260, 0, 0, 0.2)' ) === null, 'Rechazar canales de color inválidos.' );

try {
	$analyzer->from_json( '{"version":"0.4","content":[{"elType":"widget","settings":{"markup":"<script></script>"},"elements":[]}]}', 'invalid.json' );
	WP_CLI::error( 'El marcado activo debe rechazarse.' );
} catch ( RuntimeException $error ) {
	$assert( strpos( $error->getMessage(), 'marcado activo' ) !== false, 'El motivo del rechazo debe describirse.' );
}

WP_CLI::success( 'Inventario de la portada, propuesta y validaciones del analizador verificados.' );
