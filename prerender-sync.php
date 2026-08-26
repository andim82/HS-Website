<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HEIM:SPIEL Prerender Sync (Pull-Variante) -- v6
 *
 * v6 (17.08.2026): FIX -- raw.githubusercontent.com blockte dauerhaft mit
 * 429 (anhaltende Sperre, vermutlich geteilte Hosting-IP, die das
 * gemeinsame anonyme Rate-Limit mit vielen anderen Kunden-Sites
 * ausschoepft). Umstellung auf die AUTHENTIFIZIERTE GitHub Contents API
 * (api.github.com/repos/.../contents/...) mit einem Personal Access
 * Token (Fine-grained, nur "Contents: Read-only" fuer dieses eine Repo).
 * Authentifizierte Requests haben ein eigenes Kontingent von 5.000/Stunde
 * PRO TOKEN, unabhaengig von der anfragenden IP-Adresse -- das umgeht das
 * IP-basierte Limit vollstaendig.
 *
 * VORAUSSETZUNG: In wp-config.php muss definiert sein:
 *   define( 'HS_GITHUB_TOKEN', 'ghp_xxx...' );
 * (Fine-grained PAT, Repository: andim82/HS-Website, Permission:
 *  Contents -> Read-only)
 */

if ( ! defined( 'HS_GITHUB_REPO_OWNER' ) ) define( 'HS_GITHUB_REPO_OWNER', 'andim82' );
if ( ! defined( 'HS_GITHUB_REPO_NAME' ) )  define( 'HS_GITHUB_REPO_NAME', 'HS-Website' );
if ( ! defined( 'HS_GITHUB_REPO_BRANCH' ) ) define( 'HS_GITHUB_REPO_BRANCH', 'main' );
if ( ! defined( 'HS_GITHUB_MAPPING_PATH' ) ) define( 'HS_GITHUB_MAPPING_PATH', 'dist/hero-images/image-mapping.json' );

/* ---------------------------------------------------------------------
 * 0) GitHub Contents API -- authentifizierter Datei-Abruf (Base64-Decode)
 * ------------------------------------------------------------------- */

/**
 * Laedt eine Datei aus dem Repo ueber die GitHub Contents API.
 *
 * @param string $path Pfad relativ zum Repo-Root, z.B. "dist/hero-images/foo.webp"
 * @return array{ok:bool, binary:?string, error:?string}
 */
function hs_github_api_get_file( $path ) {
	if ( ! defined( 'HS_GITHUB_TOKEN' ) || ! HS_GITHUB_TOKEN ) {
		return [ 'ok' => false, 'binary' => null, 'error' => 'HS_GITHUB_TOKEN ist nicht in wp-config.php definiert.' ];
	}

	$url = sprintf(
		'https://api.github.com/repos/%s/%s/contents/%s?ref=%s',
		HS_GITHUB_REPO_OWNER,
		HS_GITHUB_REPO_NAME,
		ltrim( $path, '/' ),
		HS_GITHUB_REPO_BRANCH
	);

	$delays = [ 3, 8, 15 ];
	$response = null;

	foreach ( array_merge( [ 0 ], $delays ) as $delay ) {
		if ( $delay > 0 ) {
			error_log( "HS Prerender Sync: Retry fuer '$path' nach {$delay}s..." );
			sleep( $delay );
		}

		$response = wp_remote_get( $url, [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . HS_GITHUB_TOKEN,
				'Accept'        => 'application/vnd.github+json',
				'User-Agent'    => 'HEIMSPIEL-WP-Prerender-Sync/1.0',
				'X-GitHub-Api-Version' => '2022-11-28',
			],
		] );

		if ( is_wp_error( $response ) ) {
			continue;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code === 200 ) {
			break;
		}
		if ( ! in_array( $code, [ 403, 429, 502, 503 ], true ) ) {
			// Kein transienter Fehler (z.B. 404 -- Datei existiert nicht) -- sofort abbrechen.
			break;
		}
	}

	if ( is_wp_error( $response ) ) {
		return [ 'ok' => false, 'binary' => null, 'error' => $response->get_error_message() ];
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code !== 200 ) {
		$body_preview = substr( wp_remote_retrieve_body( $response ), 0, 300 );
		return [ 'ok' => false, 'binary' => null, 'error' => "GitHub API HTTP $code fuer '$path'. Body: $body_preview" ];
	}

	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $json ) || empty( $json['content'] ) ) {
		return [ 'ok' => false, 'binary' => null, 'error' => "Unerwartete API-Antwort fuer '$path' (kein 'content'-Feld)." ];
	}

	// GitHub liefert Base64 mit eingebetteten Zeilenumbruechen -- entfernen vor dem Decode.
	$clean_b64 = str_replace( [ "\n", "\r" ], '', $json['content'] );
	$binary    = base64_decode( $clean_b64, true );

	if ( $binary === false ) {
		return [ 'ok' => false, 'binary' => null, 'error' => "Base64-Decode fehlgeschlagen fuer '$path'." ];
	}

	return [ 'ok' => true, 'binary' => $binary, 'error' => null ];
}

/* ---------------------------------------------------------------------
 * 1) Cron: taeglicher automatischer Sync
 * ------------------------------------------------------------------- */

add_action( 'init', 'hs_prerender_sync_schedule_cron' );

function hs_prerender_sync_schedule_cron() {
	if ( ! wp_next_scheduled( 'hs_prerender_sync_cron' ) ) {
		wp_schedule_event( strtotime( 'tomorrow 4:00' ), 'daily', 'hs_prerender_sync_cron' );
	}
}

add_action( 'hs_prerender_sync_cron', 'hs_prerender_sync_run' );

/* ---------------------------------------------------------------------
 * 2) Kernlogik
 * ------------------------------------------------------------------- */

function hs_prerender_sync_run() {

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	if ( ! defined( 'HS_GITHUB_TOKEN' ) || ! HS_GITHUB_TOKEN ) {
		return hs_prerender_sync_finish( false, 'HS_GITHUB_TOKEN fehlt in wp-config.php. Fine-grained Token mit "Contents: Read-only" fuer andim82/HS-Website anlegen und dort eintragen.', [] );
	}

	$mapping_result = hs_github_api_get_file( HS_GITHUB_MAPPING_PATH );

	if ( ! $mapping_result['ok'] ) {
		return hs_prerender_sync_finish( false, 'Mapping-Abruf fehlgeschlagen: ' . $mapping_result['error'], [] );
	}

	$mapping = json_decode( $mapping_result['binary'], true );
	if ( ! is_array( $mapping ) ) {
		return hs_prerender_sync_finish( false, 'Ungueltiges JSON im Mapping (json_decode fehlgeschlagen).', [] );
	}

	if ( empty( $mapping ) ) {
		return hs_prerender_sync_finish( true, 'Mapping-JSON ist leer -- keine Bilder zum Synchronisieren.', [] );
	}

	$results = [];

	foreach ( $mapping as $entry ) {
		if ( ( $entry['status'] ?? '' ) !== 'ok' || empty( $entry['repoPath'] ) ) {
			$results[] = [
				'disciplineKey' => $entry['disciplineKey'] ?? '?',
				'status'        => 'skipped',
				'reason'        => 'Kein gueltiger Eintrag (status != ok oder repoPath fehlt).',
			];
			continue;
		}

		$discipline_key = $entry['disciplineKey'];
		$filename       = $entry['filename'] ?? ( $discipline_key . '-hero.webp' );
		$alt_text       = $entry['altText'] ?? '';
		$title          = $entry['title'] ?? $discipline_key;

$existing = get_posts( [
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'posts_per_page' => 1,
    'meta_query'     => [
        [ 'key' => '_hs_prerender_filename', 'value' => $filename ],
    ],
] );

		if ( ! empty( $existing ) ) {
			$results[] = [
				'disciplineKey' => $discipline_key,
				'filename'      => $filename,
				'status'        => 'skipped',
				'reason'        => 'Bereits in Mediathek.',
				'mediaId'       => $existing[0]->ID,
				'url'           => wp_get_attachment_url( $existing[0]->ID ),
				'uploadedAt'    => get_the_date( 'd.m.Y H:i', $existing[0]->ID ),
			];
			continue;
		}

		$img_result = hs_github_api_get_file( $entry['repoPath'] );

		if ( ! $img_result['ok'] ) {
			$results[] = [ 'disciplineKey' => $discipline_key, 'filename' => $filename, 'status' => 'error', 'error' => $img_result['error'] ];
			continue;
		}

		$binary = $img_result['binary'];
		if ( empty( $binary ) ) {
			$results[] = [ 'disciplineKey' => $discipline_key, 'filename' => $filename, 'status' => 'error', 'error' => 'Leerer Bild-Body.' ];
			continue;
		}

		$upload = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $upload['error'] ) ) {
			$results[] = [ 'disciplineKey' => $discipline_key, 'filename' => $filename, 'status' => 'error', 'error' => $upload['error'] ];
			continue;
		}

		$attachment = [
			'post_mime_type' => 'image/webp',
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
		];
		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attach_id ) ) {
			$results[] = [ 'disciplineKey' => $discipline_key, 'filename' => $filename, 'status' => 'error', 'error' => $attach_id->get_error_message() ];
			continue;
		}

		$attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		if ( $alt_text ) {
			update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt_text );
		}
		update_post_meta( $attach_id, '_hs_prerender_filename', $filename );
		update_post_meta( $attach_id, '_hs_prerender_discipline_key', $discipline_key );

		$results[] = [
			'disciplineKey' => $discipline_key,
			'filename'      => $filename,
			'status'        => 'imported',
			'mediaId'       => $attach_id,
			'url'           => $upload['url'],
			'uploadedAt'    => current_time( 'd.m.Y H:i' ),
		];
	}

	$errors = count( array_filter( $results, fn( $r ) => $r['status'] === 'error' ) );

	return hs_prerender_sync_finish(
		$errors === 0,
		$errors > 0 ? "$errors von " . count( $results ) . " Bildern fehlgeschlagen -- Details unten." : null,
		$results
	);
}

function hs_prerender_sync_finish( $ok, $error, $items ) {
	$report = [
		'ok'    => $ok,
		'error' => $error,
		'items' => $items,
		'ranAt' => current_time( 'd.m.Y H:i:s' ),
	];
	update_option( 'hs_prerender_sync_last_report', $report );
	return $report;
}

/* ---------------------------------------------------------------------
 * 3) Manueller Button
 * ------------------------------------------------------------------- */

add_action( 'admin_post_hs_prerender_sync_now', 'hs_prerender_sync_handle_manual_trigger' );

function hs_prerender_sync_handle_manual_trigger() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Keine Berechtigung.' );
	}
	check_admin_referer( 'hs_prerender_sync_action', 'hs_prerender_sync_nonce' );

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 180 );
	}

	hs_prerender_sync_run();

	wp_safe_redirect( add_query_arg( 'hs_prerender_synced', '1', wp_get_referer() ?: admin_url( 'options-general.php?page=hs-data-cache' ) ) );
	exit;
}

/* ---------------------------------------------------------------------
 * 4) Admin-Box: Button + letzter Report + dauerhafte Liste aller Bilder
 * ------------------------------------------------------------------- */

add_action( 'hs_data_cache_admin_page_footer', 'hs_prerender_sync_render_admin_box' );

function hs_prerender_sync_render_admin_box() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$report = get_option( 'hs_prerender_sync_last_report', null );

	if ( isset( $_GET['hs_prerender_synced'] ) ) {
		if ( $report && $report['ok'] === false ) {
			echo '<div class="notice notice-error" style="padding:10px;"><p><strong>Sync fehlgeschlagen:</strong> ' . esc_html( $report['error'] ?? 'Unbekannter Fehler.' ) . '</p></div>';
		} elseif ( $report && ! empty( $report['error'] ) ) {
			echo '<div class="notice notice-warning" style="padding:10px;"><p><strong>Sync mit Warnungen:</strong> ' . esc_html( $report['error'] ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success" style="padding:10px;"><p>Bild-Sync erfolgreich durchgefuehrt. Details unten.</p></div>';
		}
	}

	$token_configured = defined( 'HS_GITHUB_TOKEN' ) && HS_GITHUB_TOKEN;
	?>
	<h2 style="margin-top:2rem;">Hero-Bilder von GitHub synchronisieren</h2>

	<?php if ( ! $token_configured ) : ?>
		<div class="notice notice-error" style="padding:10px;">
			<p><strong>HS_GITHUB_TOKEN fehlt.</strong> Bitte in wp-config.php ergaenzen: <code>define( 'HS_GITHUB_TOKEN', 'ghp_...' );</code></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="hs_prerender_sync_now">
		<?php wp_nonce_field( 'hs_prerender_sync_action', 'hs_prerender_sync_nonce' ); ?>
		<button type="submit" class="button button-primary" <?php disabled( ! $token_configured ); ?>>Bilder jetzt synchronisieren</button>
		<p style="color:#666;font-size:.9em;">
			Laeuft automatisch taeglich per Cron. Naechster automatischer Lauf:
			<?php echo esc_html( wp_next_scheduled( 'hs_prerender_sync_cron' ) ? date_i18n( 'd.m.Y H:i', wp_next_scheduled( 'hs_prerender_sync_cron' ) ) : '-' ); ?>
			<br>Quelle: GitHub Contents API (authentifiziert) -- Repo <code><?php echo esc_html( HS_GITHUB_REPO_OWNER . '/' . HS_GITHUB_REPO_NAME ); ?></code>, Branch <code><?php echo esc_html( HS_GITHUB_REPO_BRANCH ); ?></code>
		</p>
	</form>

	<h3>Letzter Sync-Lauf</h3>
	<?php if ( $report ) : ?>
		<p style="color:#666;font-size:.9em;">
			Zeitpunkt: <?php echo esc_html( $report['ranAt'] ?? '-' ); ?>
			&nbsp;|&nbsp;
			Status: <?php echo $report['ok'] ? '<span style="color:#00a32a;">OK</span>' : '<span style="color:#d63638;">Fehler</span>'; ?>
			<?php if ( ! empty( $report['error'] ) ) : ?>
				<br>Meldung: <code style="color:#d63638;"><?php echo esc_html( $report['error'] ); ?></code>
			<?php endif; ?>
		</p>
		<?php if ( ! empty( $report['items'] ) ) : ?>
			<table class="widefat striped" style="max-width:900px;">
				<thead><tr><th>Sportart</th><th>Dateiname</th><th>Status</th><th>Details</th></tr></thead>
				<tbody>
				<?php foreach ( $report['items'] as $item ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $item['disciplineKey'] ?? '?' ); ?></strong></td>
						<td><code><?php echo esc_html( $item['filename'] ?? '-' ); ?></code></td>
						<td>
							<?php
							$status = $item['status'] ?? '?';
							$color  = $status === 'imported' ? '#00a32a' : ( $status === 'error' ? '#d63638' : '#666' );
							echo '<span style="color:' . $color . '">' . esc_html( $status ) . '</span>';
							?>
						</td>
						<td>
							<?php
							if ( ! empty( $item['url'] ) ) {
								echo '<a href="' . esc_url( $item['url'] ) . '" target="_blank">Ansehen</a>';
								if ( ! empty( $item['uploadedAt'] ) ) echo ' <span style="color:#999;">(' . esc_html( $item['uploadedAt'] ) . ')</span>';
							} elseif ( ! empty( $item['error'] ) ) {
								echo '<code style="color:#d63638;">' . esc_html( $item['error'] ) . '</code>';
							} elseif ( ! empty( $item['reason'] ) ) {
								echo esc_html( $item['reason'] );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php else : ?>
		<p>Noch kein Sync-Lauf durchgefuehrt.</p>
	<?php endif; ?>

	<h3 style="margin-top:2rem;">Alle bisher synchronisierten Hero-Bilder</h3>
	<?php
$all_synced = get_posts( [
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'posts_per_page' => -1,
    'meta_key'       => '_hs_prerender_filename',
    'orderby'        => 'date',
    'order'          => 'DESC',
] );

	if ( empty( $all_synced ) ) :
		?>
		<p>Noch keine Hero-Bilder in der Mediathek vorhanden.</p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:900px;">
			<thead><tr><th>Sportart</th><th>Dateiname</th><th>Hochgeladen am</th><th>URL</th></tr></thead>
			<tbody>
			<?php foreach ( $all_synced as $post ) :
				$discipline = get_post_meta( $post->ID, '_hs_prerender_discipline_key', true );
				$filename   = get_post_meta( $post->ID, '_hs_prerender_filename', true );
				?>
				<tr>
					<td><strong><?php echo esc_html( $discipline ?: '-' ); ?></strong></td>
					<td><code><?php echo esc_html( $filename ?: '-' ); ?></code></td>
					<td><?php echo esc_html( get_the_date( 'd.m.Y H:i', $post->ID ) ); ?></td>
					<td><a href="<?php echo esc_url( wp_get_attachment_url( $post->ID ) ); ?>" target="_blank"><?php echo esc_html( wp_get_attachment_url( $post->ID ) ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}
