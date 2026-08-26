<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin-Seite: Einstellungen -> HEIM:SPIEL Cache
 * NEU (v2.0): Zeigt pro Datenquelle einen EIGENEN Erfolgs-/Fehlerstatus
 * (statt nur einem globalen "Cache erfolgreich aktualisiert"), inkl.
 * Zeitpunkt des letzten ERFOLGREICHEN Refreshs pro Quelle (wichtig fuer
 * Quellen, die gerade fehlschlagen, aber frueher mal geklappt haben --
 * z.B. bei einem Google-Apps-Script-Kaltstart-Timeout).
 */
add_action( 'admin_menu', 'hs_register_admin_page' );
function hs_register_admin_page() {
	add_options_page(
		'HEIM:SPIEL Data Cache',
		'HEIM:SPIEL Cache',
		'manage_options',
		'hs-data-cache',
		'hs_render_admin_page'
	);
}

function hs_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$refreshed = false;
	$report    = null;

	if ( isset( $_POST['hs_refresh_cache'] ) && check_admin_referer( 'hs_refresh_cache_action', 'hs_refresh_nonce' ) ) {
		$report    = hs_refresh_all_cache_v2();
		$refreshed = true;
	}

	// Letzten bekannten Report anzeigen, auch wenn diese Seite ohne neuen
	// Refresh geladen wird (z.B. nach einem automatischen Cron-Lauf).
	if ( $report === null ) {
		$report = get_option( 'hs_cache_refresh_report', null );
	}

	$last_run           = get_option( 'hs_cache_last_run', '' );
	$indexde_success_at = get_option( 'hs_cache_indexde_last_success', '' );
	$generalde_success_at = get_option( 'hs_cache_generalindexde_last_success', '' );

	$has_index      = get_transient( 'hs_index_data' ) !== false;
	$has_general    = get_transient( 'hs_general_index_data' ) !== false;
	$has_index_de   = get_transient( 'hs_index_de_data' ) !== false;
	$has_general_de = get_transient( 'hs_general_index_de_data' ) !== false;

	$next_cron = wp_next_scheduled( HS_CRON_HOOK );
	$base      = trailingslashit( rest_url( 'hs-cache/v1' ) );

	// Labels fuer die menschenlesbare Anzeige der Quellen im Report.
	$source_labels = [
		'index'          => 'Index (EN)',
		'generalIndex'   => 'General Index (EN)',
		'indexDe'        => 'Index (DE)',
		'generalIndexDe' => 'General Index (DE)',
		'csv'            => 'Sport-CSV-Tabs (alle Disziplinen)',
		'coverage'       => 'Coverage-Aggregation (Cluster-Bundles)',
		'bundleTotals'   => 'Bundle-Totals (Cluster-Bundles)',
	];
	?>
	<div class="wrap">
		<h1>🏅 HEIM:SPIEL Data Cache</h1>

		<?php if ( $refreshed ) : ?>
			<?php if ( $report['overall_success'] ) : ?>
				<div class="notice notice-success"><p>✅ Alle Datenquellen erfolgreich aktualisiert.</p></div>
			<?php else : ?>
				<div class="notice notice-error">
					<p>⚠️ Refresh abgeschlossen, aber <strong>nicht alle</strong> Datenquellen konnten aktualisiert werden. Details unten -- betroffene Quellen liefern weiterhin ihren zuletzt erfolgreich gecachten (moeglicherweise veralteten) Stand.</p>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'hs_refresh_cache_action', 'hs_refresh_nonce' ); ?>
			<p><button type="submit" name="hs_refresh_cache" value="1" class="button button-primary">Cache jetzt aktualisieren</button></p>
			<p style="color:#666;font-size:.9em;">Jeder Abruf wird bei Fehlschlag automatisch bis zu <?php echo esc_html( HS_FETCH_MAX_RETRIES ); ?>&times; wiederholt (mit ansteigender Wartezeit), bevor er als fehlgeschlagen markiert wird.</p>
		</form>

		<h2>Status pro Datenquelle</h2>
		<table class="widefat striped" style="max-width:900px">
			<thead>
				<tr>
					<th>Datenquelle</th>
					<th>Status (letzter Refresh)</th>
					<th>Anzahl Einträge</th>
					<th>Fehlermeldung</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( $report && isset( $report['sources'] ) ) : ?>
				<?php foreach ( $report['sources'] as $key => $s ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $source_labels[ $key ] ?? $key ); ?></strong></td>
						<td>
							<?php if ( $s['success'] ) : ?>
								<span style="color:#00a32a;">✅ OK</span>
							<?php else : ?>
								<span style="color:#d63638;">❌ Fehlgeschlagen</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $s['count'] ); ?></td>
						<td><?php echo $s['error'] ? '<code style="font-size:.85em;">' . esc_html( $s['error'] ) . '</code>' : '–'; ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr><td colspan="4">Noch kein Refresh-Report vorhanden. Klicke oben auf "Cache jetzt aktualisieren".</td></tr>
			<?php endif; ?>
			</tbody>
		</table>

		<h2 style="margin-top:2rem;">Cache-Zustand (aktuell im Transient-Speicher)</h2>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
				<tr><td>Cache-Status (Index EN)</td><td><?php echo $has_index ? '✅ Aktiv' : '⚠️ Kein Cache'; ?></td></tr>
				<tr><td>Cache-Status (General Index EN)</td><td><?php echo $has_general ? '✅ Aktiv' : '⚠️ Kein Cache'; ?></td></tr>
				<tr><td>Cache-Status (Index DE)</td><td><?php echo $has_index_de ? '✅ Aktiv' : '⚠️ Kein Cache'; ?></td></tr>
				<tr><td>Cache-Status (General Index DE)</td><td><?php echo $has_general_de ? '✅ Aktiv' : '⚠️ Kein Cache'; ?></td></tr>
				<tr><td>Letzter Refresh-Versuch</td><td><?php echo esc_html( $last_run ?: '–' ); ?></td></tr>
				<tr><td>Letzter <strong>erfolgreicher</strong> Refresh von Index DE</td><td><?php echo esc_html( $indexde_success_at ?: '–' ); ?></td></tr>
				<tr><td>Letzter <strong>erfolgreicher</strong> Refresh von General Index DE</td><td><?php echo esc_html( $generalde_success_at ?: '–' ); ?></td></tr>
				<tr><td>Nächster automatischer Refresh</td><td><?php echo $next_cron ? esc_html( date_i18n( 'd.m.Y H:i', $next_cron ) ) : '–'; ?></td></tr>
				<tr><td>Cache-Intervall</td><td>30 Tage (monatlich)</td></tr>
			</tbody>
		</table>

		<h2 style="margin-top:2rem;">REST-API Endpunkte</h2>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
				<tr><td>Index (EN)</td><td><code><?php echo esc_html( $base . 'index' ); ?></code></td></tr>
				<tr><td>General Index (EN)</td><td><code><?php echo esc_html( $base . 'generalIndex' ); ?></code></td></tr>
				<tr><td>Index (DE)</td><td><code><?php echo esc_html( $base . 'indexDe' ); ?></code></td></tr>
				<tr><td>General Index (DE)</td><td><code><?php echo esc_html( $base . 'generalIndexDe' ); ?></code></td></tr>
				<tr><td>CSV</td><td><code><?php echo esc_html( $base . 'csv/{gid}' ); ?></code></td></tr>
				<tr><td>Coverage</td><td><code><?php echo esc_html( $base . 'coverage/{sport}' ); ?></code></td></tr>
				<tr><td>Bundle-Totals</td><td><code><?php echo esc_html( $base . 'bundle-totals/{bundle}' ); ?></code></td></tr>
			</tbody>
		</table>

		<p style="margin-top:1.5rem;color:#666;">
			Die <code>CONFIG</code> in <code>hs-landing.js</code> enthält bereits alle URLs.
		</p>
<?php do_action( 'hs_data_cache_admin_page_footer' ); ?>
	</div>
	<?php
}
