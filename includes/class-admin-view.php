<?php

namespace Cort\ElementorGlobalStyles;

defined( 'ABSPATH' ) || exit;

final class Admin_View {

	public function render( array $status, array $analysis, array $plan, array $docs, array $history, array $notice, string $search, int $history_page ): void {
		?>
		<div class="wrap">
			<h1>Cort Global Styles</h1>
			<p>Analiza un diseño de Elementor y aplica sus estilos al Kit activo después de revisar los cambios.</p>
			<?php if ( ! empty( $notice['message'] ) ) : ?>
				<div class="notice <?php echo ! empty( $notice['success'] ) ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>
			<p>
				<a href="#cort-source">Analizar diseño</a> · <a href="#cort-analysis">Estilos globales</a> ·
				<a href="#cort-link">Vincular widgets</a> · <a href="#cort-reports">Respaldos e informes</a>
			</p>
			<?php
			$this->dashboard( $status, $history['items'] );
			$this->analysis_forms( $docs, $search, (bool) $status['kit_id'] && $status['version_supported'] );
			$this->analysis_result( $analysis, (bool) $status['kit_id'] && $status['version_supported'] );
			$this->link_form( $docs, (bool) $status['kit_id'] && $status['version_supported'] );
			$this->preview( $plan );
			$this->reports( $history, $history_page );
			?>
		</div>
		<?php
	}

	private function dashboard( array $status, array $backups ): void {
		$last = get_option( 'cort_egs_last_applied', [] );
		?>
		<h2>Panel</h2>
		<table class="widefat striped">
			<tbody>
				<tr><th scope="row">Elementor</th><td><?php echo esc_html( $status['core'] ?: 'Inactivo' ); ?></td></tr>
				<tr><th scope="row">Elementor Pro</th><td><?php echo esc_html( $status['pro'] ?: 'Inactivo o sin detectar' ); ?></td></tr>
				<tr><th scope="row">Kit activo</th><td><?php echo esc_html( $status['kit_id'] ? get_the_title( $status['kit_id'] ) . ' · #' . $status['kit_id'] : 'Sin Kit activo' ); ?></td></tr>
				<tr><th scope="row">Última aplicación al Kit</th><td><?php echo esc_html( isset( $last['at'] ) ? wp_date( 'Y-m-d H:i', $last['at'] ) : 'Sin aplicaciones' ); ?></td></tr>
				<tr><th scope="row">Último respaldo de la lista</th><td><?php echo esc_html( $backups ? '#' . $backups[0]['id'] . ' · ' . $backups[0]['title'] : 'Sin respaldos' ); ?></td></tr>
			</tbody>
		</table>
		<?php if ( ! $status['version_supported'] || ! $status['kit_id'] ) : ?>
			<p class="description">Puedes analizar un JSON. La configuración del sitio requiere Elementor 3.24–4.x y un Kit activo editable.</p>
		<?php endif; ?>
		<?php
	}

	private function form_start( string $task, bool $multipart = false ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" <?php echo $multipart ? 'enctype="multipart/form-data"' : ''; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( Plugin::POST_ACTION ); ?>">
			<input type="hidden" name="task" value="<?php echo esc_attr( $task ); ?>">
			<?php wp_nonce_field( Plugin::POST_ACTION . '_' . $task ); ?>
		<?php
	}

	private function hidden( string $key, $value ): void {
		?>
		<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
		<?php
	}

	private function doc_select( array $docs, string $id ): void {
		?>
		<label for="<?php echo esc_attr( $id ); ?>">Página o plantilla de Elementor</label>
		<select id="<?php echo esc_attr( $id ); ?>" name="document_id" required>
			<option value="">Selecciona un documento</option>
			<?php foreach ( $docs as $post_id => $title ) : ?>
				<option value="<?php echo esc_attr( (string) $post_id ); ?>"><?php echo esc_html( $title . ' · #' . $post_id ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function analysis_forms( array $docs, string $search, bool $kit_available ): void {
		?>
		<h2 id="cort-source">Analizar diseño</h2>
		<?php $this->form_start( 'analyze', true ); ?>
			<?php $this->hidden( 'source', 'json' ); ?>
			<p>
				<label for="cort-json">Plantilla JSON de Elementor</label>
				<input type="file" id="cort-json" name="reference" accept=".json,application/json" required>
				<button class="button button-primary">Analizar JSON</button>
			</p>
			<p class="description">Máximo 8 MiB. Se analiza el archivo temporal y se guarda únicamente el inventario de estilos.</p>
		</form>
		<?php if ( $kit_available ) : ?>
			<form method="get">
				<?php $this->hidden( 'page', Plugin::SLUG ); ?>
				<p>
					<label for="cort-doc-search">Filtrar páginas y plantillas por título</label>
					<input type="search" id="cort-doc-search" name="doc_search" value="<?php echo esc_attr( $search ); ?>">
					<button class="button">Buscar</button>
				</p>
			</form>
			<?php $this->form_start( 'analyze' ); ?>
				<?php $this->hidden( 'source', 'page' ); ?>
				<p><?php $this->doc_select( $docs, 'cort-analysis-doc' ); ?> <button class="button" <?php disabled( ! $docs ); ?>>Analizar documento</button></p>
			</form>
		<?php endif; ?>
		<?php
	}

	private function analysis_result( array $analysis, bool $ready ): void {
		?>
		<h2 id="cort-analysis">Inventario y estilos globales</h2>
		<?php
		if ( empty( $analysis['report'] ) ) {
			echo '<p>El inventario y la propuesta aparecen después de analizar una referencia.</p>';
			return;
		}
		$r = $analysis['report'];
		$p = $analysis['edits'] ?? $analysis['proposal'];
		printf( '<p><strong>%s</strong> · %s</p>', esc_html( $r['source']['title'] ), esc_html( sprintf( '%d elementos · %d colores · %d perfiles tipográficos · %d tipos de referencia', $r['element_count'], count( $r['colors'] ), count( $r['typography'] ), count( $r['global_references'] ) ) ) );
		$this->form_start( 'export' );
		echo '<p><button class="button">Descargar reporte JSON</button></p></form>';
		$this->inventory( $r );
		$this->theme_example( $p['theme'] );
		?>
		<p>Los tokens personalizados extraídos se preseleccionan al cargar el análisis. Los cuatro tokens de sistema, Theme Style y las inferencias requieren selección explícita. Theme Style aplica valores predeterminados que pueden quedar anulados por los controles locales.</p>
		<p class="description">Usa el formato de los campos. Para medidas, escribe número y unidad, por ejemplo <code>16 px</code>. Para dimensiones, usa el orden arriba, derecha, abajo, izquierda y la unidad.</p>
		<?php
		$this->form_start( 'preview' );
		$this->hidden( 'analysis_id', $analysis['id'] );
		foreach ( [ 'colors' => 'Global Colors', 'fonts' => 'Global Fonts', 'theme' => 'Theme Style y Layout' ] as $group => $heading ) {
			$this->edit_table( $p[ $group ], $heading, $group, $analysis['selection'] ?? null );
		}
		$this->notes( $p['notes'] );
		?>
			<p><button class="button button-primary" <?php disabled( ! $ready ); ?>>Vista previa de cambios</button></p>
		</form>
		<?php
	}

	private function inventory( array $r ): void {
		?>
		<details>
			<summary>Colores y frecuencia</summary>
			<table class="widefat striped">
				<thead><tr><th>Color</th><th>Usos</th><th>Controles detectados</th></tr></thead>
				<tbody>
					<?php foreach ( $r['colors'] as $row ) : ?>
					<tr><td><?php $this->swatch( $row['value'] ); ?> <?php echo esc_html( $row['value'] ); ?></td>
					<td><?php echo esc_html( (string) $row['count'] ); ?></td>
					<td><?php echo esc_html( implode( ', ', array_map( static function ( array $v ): string {
						return sprintf( '%s · %s (%d, %s)', $v['widget'], $v['control'], $v['count'], $v['source'] );
					}, array_slice( $row['uses'], 0, 6 ) ) ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<details>
			<summary>Tipografías y tamaños responsive</summary>
			<table class="widefat striped">
				<thead><tr><th>Uso</th><th>Tipografía</th><th>Frecuencia</th></tr></thead>
				<tbody>
					<?php foreach ( $r['typography'] as $row ) : ?>
					<tr><td><?php echo esc_html( $row['role'] ); ?></td>
					<td><?php echo esc_html( $this->summarize( $row['values'] ) ); ?></td>
					<td><?php echo esc_html( (string) $row['count'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<details>
			<summary>Espaciados, bordes y referencias existentes</summary>
			<?php
			foreach ( [ 'spacing' => 'Espaciados', 'borders' => 'Bordes y sombras', 'global_references' => 'Referencias globales' ] as $key => $label ) {
				printf( '<h4>%s (%d)</h4>', esc_html( $label ), count( $r[ $key ] ) );
				$list = array_map( function ( array $row ) use ( $key ): string {
					$value = $key === 'global_references'
						? trim( $row['kind'] . '/' . $row['id'] . ' · ' . ( $row['status'] === 'resolved' ? 'resuelto' : 'pendiente' ) )
						: Style::display( $row['value'] );
					return $row['control'] . ' · ' . $value . ' · ' . $row['count'];
				}, array_slice( $r[ $key ], 0, 16 ) );
				$this->notes( $list );
			}
			?>
			<p class="description">El JSON descargable contiene el inventario completo, incluidos los estilos por componente.</p>
		</details>
		<?php
	}

	private function theme_example( array $theme ): void {
		$h = $theme['h2']['values'] ?? [];
		$body = $theme['body']['values'] ?? [];
		$button = $theme['buttons']['values'] ?? [];
		$field = $theme['fields']['values'] ?? [];
		?>
		<details>
			<summary>Muestra de Theme Style</summary>
			<div class="card">
				<p style="<?php echo esc_attr( $this->example_styles( $h, 'h2_typography_', 'h2_color' ) ); ?>">Browse by Topic</p>
				<p style="<?php echo esc_attr( $this->example_styles( $body, 'body_typography_', 'body_color' ) ); ?>">Explore the knowledge base.</p>
				<?php if ( $button ) : ?>
					<p><button type="button" style="<?php echo esc_attr( $this->example_styles( $button, 'button_typography_', 'button_text_color', 'button_background_color', 'button_border_radius', 'button_padding' ) ); ?>">Contact Support</button></p>
				<?php endif; ?>
				<?php if ( $field ) : ?>
					<p><label for="cort-demo-field">Campo de búsqueda</label>
					<input id="cort-demo-field" readonly value="Search for articles…" style="<?php echo esc_attr( $this->example_styles( $field, 'form_field_typography_', 'form_field_text_color', 'form_field_background_color', 'form_field_border_radius', 'form_field_padding' ) ); ?>"></p>
				<?php endif; ?>
			</div>
			<p>Los estados hover y foco, las imágenes y el layout se revisan en la tabla de ajustes.</p>
		</details>
		<?php
	}

	private function example_styles( array $values, string $type, string $color, string $bg = '', string $radius = '', string $padding = '' ): string {
		$css = [];
		foreach ( [ 'color' => $color, 'background-color' => $bg ] as $prop => $key ) {
			if ( isset( $values[ $key ] ) ) {
				$css[] = $prop . ':' . $values[ $key ];
			}
		}
		if ( isset( $values[ $type . 'font_family' ] ) ) {
			$css[] = 'font-family:"' . $values[ $type . 'font_family' ] . '",sans-serif';
		}
		if ( isset( $values[ $type . 'font_weight' ] ) ) {
			$css[] = 'font-weight:' . $values[ $type . 'font_weight' ];
		}
		if ( isset( $values[ $type . 'font_size' ], $values[ $type . 'font_size' ]['unit'] ) && $values[ $type . 'font_size' ]['unit'] === 'px' ) {
			$css[] = 'font-size:' . min( 48, $values[ $type . 'font_size' ]['size'] ) . 'px';
		}
		foreach ( [ 'border-radius' => $radius, 'padding' => $padding ] as $prop => $key ) {
			if ( isset( $values[ $key ] ) ) {
				$d = $values[ $key ];
				$css[] = $prop . ':' . implode( ' ', array_map( static function ( string $side ) use ( $d ): string {
					return min( 36, (float) $d[ $side ] ) . $d['unit'];
				}, [ 'top', 'right', 'bottom', 'left' ] ) );
			}
		}
		return implode( ';', $css );
	}

	private function edit_table( array $rows, string $title, string $group, ?array $selected ): void {
		?>
		<h3><?php echo esc_html( $title ); ?></h3>
		<?php if ( ! $rows ) : ?>
			<p class="description">La referencia no aportó estilos compatibles para esta sección.</p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped">
			<thead><tr><th>Aplicar</th><th>Ajuste</th><th>Valores propuestos</th></tr></thead>
			<tbody>
				<?php foreach ( $rows as $key => $row ) :
					$enabled = $selected !== null ? isset( $selected[ $group ][ $key ] )
						: $group !== 'theme' && ! $row['system'] && $row['source'] === 'Extraído';
					?>
					<tr>
						<td><input type="checkbox" name="choose[<?php echo esc_attr( $group ); ?>][<?php echo esc_attr( $key ); ?>]" value="1" aria-label="<?php echo esc_attr( 'Aplicar ' . $row['title'] ); ?>" <?php checked( $enabled ); ?>></td>
						<th scope="row"><?php echo esc_html( $row['title'] ); ?><br><small><?php echo esc_html( $row['source'] . ' · ' . $row['role'] ); ?></small></th>
						<td>
							<?php foreach ( $row['values'] as $field => $value ) :
								$name = sprintf( 'values[%s][%s][%s]', $group, $key, $field );
								$label = $group === 'colors' ? 'Color' : str_replace( '_', ' ', $field );
								?>
								<p><label><?php echo esc_html( $label ); ?>
									<input type="text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( Style::display( $value ) ); ?>" maxlength="180" size="22">
								</label>
								<?php if ( $row['types'][ $field ] === 'color' ) { $this->swatch( $value ); } ?>
								</p>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function link_form( array $docs, bool $ready ): void {
		?>
		<h2 id="cort-link">Vincular widgets a estilos globales</h2>
		<p>Elige un documento para detectar coincidencias exactas de color o tipografía con tokens del mismo uso. La vista previa indica qué controles se pueden vincular.</p>
		<?php $this->form_start( 'link_preview' ); ?>
			<p><?php $this->doc_select( $docs, 'cort-link-doc' ); ?> <button class="button" <?php disabled( ! $ready || ! $docs ); ?>>Vista previa de vínculos</button></p>
		</form>
		<?php
	}

	private function preview( array $plan ): void {
		?>
		<h2 id="cort-preview">Vista previa y aplicación</h2>
		<?php
		if ( empty( $plan['id'] ) ) {
			echo '<p>Genera una vista previa para revisar el alcance antes de guardar.</p>';
			return;
		}
		$count = count( $plan['changes'] );
		printf( '<p><strong>%s</strong> · %s</p>', esc_html( $plan['title'] ), esc_html( $count . ' cambios previstos' ) );
		if ( $plan['kind'] === 'document' ) {
			printf( '<p>%s</p>', esc_html( count( array_unique( array_column( $plan['changes'], 'element_id' ) ) ) . ' elementos afectados.' ) );
		}
		if ( $count ) :
			?>
			<table class="widefat striped">
				<thead><tr><th>Control</th><th>Actual</th><th>Propuesto</th></tr></thead>
				<tbody>
				<?php foreach ( $plan['changes'] as $change ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $change['label'] . ' · ' . ( $change['element_id'] ?? $change['key'] ?? $change['id'] ?? '' ) ); ?></th>
						<td><?php echo esc_html( $this->summarize( $change['local'] ?? $change['previous_display'] ?? $change['previous'] ) ?: '—' ); ?></td>
						<td><?php echo esc_html( $this->summarize( $change['reference'] ?? $change['new'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php $this->form_start( 'backup' ); ?>
				<?php $this->hidden( 'plan_id', $plan['id'] ); ?>
				<p><button class="button">Crear respaldo</button>
				<?php if ( isset( $plan['backup_id'] ) ) : ?> <strong>#<?php echo esc_html( (string) $plan['backup_id'] ); ?></strong><?php endif; ?></p>
			</form>
			<?php $this->form_start( $plan['kind'] === 'kit' ? 'apply' : 'link_apply' ); ?>
				<?php $this->hidden( 'plan_id', $plan['id'] ); ?>
				<p><button class="button button-primary"><?php echo $plan['kind'] === 'kit' ? 'Aplicar estilos globales' : 'Aplicar vínculos al documento'; ?></button></p>
			</form>
		<?php endif; ?>
		<?php
		$this->notes( $plan['notes'] );
	}

	private function reports( array $history, int $page ): void {
		$last = get_option( 'cort_egs_last_activity', [] );
		?>
		<h2 id="cort-reports">Respaldos e informes</h2>
		<?php if ( isset( $last['at'], $last['message'] ) ) : ?>
			<p><?php echo esc_html( wp_date( 'Y-m-d H:i', $last['at'] ) . ' · ' . $last['message'] ); ?></p>
		<?php endif; ?>
		<?php $this->form_start( 'css' ); ?>
			<p><button class="button">Regenerar CSS de Elementor</button></p>
		</form>
		<?php if ( $history['items'] ) : ?>
			<table class="widefat striped">
				<thead><tr><th>Respaldo</th><th>Estado y registro</th><th>Acción</th></tr></thead>
				<tbody>
				<?php foreach ( $history['items'] as $item ) :
					$state = $item['last']['state'];
					$labels = [ 'prepared' => 'Preparado', 'pending' => 'Pendiente', 'applied' => 'Aplicado', 'restored' => 'Restaurado', 'partial' => 'Restauración parcial', 'error' => 'Error' ];
					?>
					<tr><th scope="row">#<?php echo esc_html( $item['id'] . ' · ' . $item['title'] ); ?></th>
					<td><details><summary><?php echo esc_html( ( $labels[ $state ] ?? $state ) . ' · ' . wp_date( 'Y-m-d H:i', $item['last']['at'] ) ); ?></summary>
						<?php foreach ( array_reverse( $item['events'] ) as $event ) :
							printf( '<p><strong>%s</strong></p>', esc_html( ( $labels[ $event['state'] ] ?? $event['state'] ) . ' · ' . wp_date( 'Y-m-d H:i', $event['at'] ) ) );
							$this->notes( $event['notes'] );
						endforeach; ?>
					</details></td><td>
					<?php if ( in_array( $state, [ 'applied', 'partial', 'error', 'pending' ], true ) ) :
						$this->form_start( 'restore' );
						$this->hidden( 'backup_id', $item['id'] );
						echo '<button class="button">Restaurar ajustes anteriores</button></form>';
					endif; ?>
					</td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $history['pages'] > 1 ) : ?>
				<p>
				<?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( Plugin::url( [ 'history_page' => $page - 1 ] ) . '#cort-reports' ); ?>">Anterior</a> · <?php endif; ?>
				<?php echo esc_html( $page . ' / ' . $history['pages'] ); ?>
				<?php if ( $page < $history['pages'] ) : ?> · <a href="<?php echo esc_url( Plugin::url( [ 'history_page' => $page + 1 ] ) . '#cort-reports' ); ?>">Siguiente</a><?php endif; ?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	private function swatch( string $color ): void {
		$color = Style::color( $color );
		if ( $color !== null ) {
			printf( '<span aria-hidden="true" style="display:inline-block;width:1.1em;height:1.1em;border:1px solid #8c8f94;vertical-align:middle;background:%s"></span>', esc_attr( $color ) );
		}
	}

	private function summarize( $value ): string {
		if ( ! is_array( $value ) ) {
			return Style::display( $value );
		}
		$formatted = Style::display( $value );
		if ( $formatted !== '' ) {
			return $formatted;
		}
		$parts = [];
		foreach ( $value as $key => $field ) {
			if ( $key === 'exists' || $field === null || $field === '' ) {
				continue;
			}
			$display = $this->summarize( $field );
			if ( $display !== '' ) {
				$parts[] = $key . ' ' . $display;
			}
		}
		return implode( ' · ', $parts );
	}

	private function notes( array $notes ): void {
		if ( ! $notes ) {
			return;
		}
		echo '<ul class="ul-disc">';
		foreach ( $notes as $note ) {
			echo '<li>' . esc_html( $note ) . '</li>';
		}
		echo '</ul>';
	}
}
