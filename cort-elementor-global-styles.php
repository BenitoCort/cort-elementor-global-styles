<?php
/**
 * Plugin Name: Cort Elementor Global Styles
 * Description: Analiza diseños de Elementor y configura colores, tipografías y estilos del sitio con vista previa y respaldo.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Desarrollo Digital MX
 * Text Domain: cort-elementor-global-styles
 */

namespace Cort\ElementorGlobalStyles;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.0.0';

foreach ( [
	'style',
	'design-analyzer',
	'design-proposal',
	'elementor-compatibility',
	'kit-changes',
	'global-reference-manager',
	'history',
	'plugin',
	'admin-view',
] as $file ) {
	require_once __DIR__ . '/includes/class-' . $file . '.php';
}

add_action( 'init', [ History::class, 'register' ] );

if ( is_admin() ) {
	( new Plugin() )->register();
}
