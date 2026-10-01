<?php
/**
 * Plugin Name: LOGADOR GitHub Updater
 * Description: Един token за сайта; всеки плъгин с ред „GitHub Plugin URI: owner/repo“ в header-а си се обновява от GitHub Releases през стандартния WP ъпдейт (Update now / auto-updates). Webhook: GitHub Action-ът пингва сайта при release и той се обновява веднага. Инсталира се сам от плъгините на LOGADOR (mu-plugins).
 * Version: 1.1.1
 * Author: LOGADOR
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( defined( 'LOGADOR_GH_UPDATER_VERSION' ) ) return; // вече е зареден (от друго копие)
define( 'LOGADOR_GH_UPDATER_VERSION', '1.1.1' );

final class LOGADOR_GitHub_Updater {
	const OPT_TOKEN = 'logador_github_token';
	const OPT_SECRET = 'logador_github_hook_secret'; // v1.1.0: общ secret за webhook-а от GitHub Actions
	const CACHE     = 'logador_gh_releases';
	const TTL       = 6 * HOUR_IN_SECONDS;

	private static $inst;
	public static function instance() { return self::$inst ?: ( self::$inst = new self() ); }

	private function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder' ), 10, 4 );
		add_filter( 'http_request_args', array( $this, 'auth' ), 10, 2 );
		add_filter( 'extra_plugin_headers', function ( $h ) { $h[] = 'GitHub Plugin URI'; return $h; } );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_logador_gh_save', array( $this, 'save' ) );
		add_action( 'admin_post_logador_gh_check', array( $this, 'check' ) );
		add_action( 'admin_post_nopriv_logador_gh_hook', array( $this, 'hook' ) ); // v1.1.0: push от GitHub Action → дърпа release-а сега
		add_action( 'admin_post_logador_gh_hook', array( $this, 'hook' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/* ---------- кои плъгини следим ---------- */
	public static function tracked() {
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$out = array();
		foreach ( get_plugins() as $file => $h ) {
			$repo = trim( (string) ( $h['GitHub Plugin URI'] ?? '' ) );
			$repo = preg_replace( '#^https?://github\.com/#', '', $repo ); $repo = rtrim( $repo, '/' ); $repo = preg_replace( '/\.git$/', '', $repo );
			if ( ! preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ) continue;
			$out[ $file ] = array( 'file' => $file, 'slug' => dirname( $file ), 'name' => $h['Name'], 'version' => $h['Version'], 'repo' => $repo );
		}
		return $out;
	}
	public static function token() { return trim( (string) get_option( self::OPT_TOKEN, '' ) ); }
	public static function secret() {
		$s = trim( (string) get_option( self::OPT_SECRET, '' ) );
		if ( '' === $s ) { $s = wp_generate_password( 40, false, false ); update_option( self::OPT_SECRET, $s, false ); }
		return $s;
	}
	public static function hook_url() { return admin_url( 'admin-post.php?action=logador_gh_hook' ); }

	/* ---------- GitHub ---------- */
	public static function release( $repo, $force = false ) {
		$all = get_site_transient( self::CACHE ); if ( ! is_array( $all ) ) $all = array();
		if ( ! $force && isset( $all[ $repo ] ) && ( $all[ $repo ]['at'] ?? 0 ) > time() - self::TTL ) return $all[ $repo ];
		$args = array( 'timeout' => 12, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'logador-gh-updater/' . LOGADOR_GH_UPDATER_VERSION ) );
		if ( self::token() ) $args['headers']['Authorization'] = 'Bearer ' . self::token();
		$res  = wp_remote_get( 'https://api.github.com/repos/' . $repo . '/releases/latest', $args );
		$row  = array( 'at' => time(), 'data' => null, 'error' => '' );
		if ( is_wp_error( $res ) ) { $row['error'] = 'Сайтът не стигна до GitHub: ' . $res->get_error_message(); }
		else {
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( 200 === $code && is_array( $body ) && ! empty( $body['tag_name'] ) ) {
				$pkg = ''; $api = '';
				foreach ( (array) ( $body['assets'] ?? array() ) as $a ) { if ( preg_match( '/\.zip$/i', (string) ( $a['name'] ?? '' ) ) ) { $pkg = (string) $a['browser_download_url']; $api = (string) $a['url']; break; } }
				$row['data'] = array( 'version' => ltrim( (string) $body['tag_name'], 'vV' ), 'tag' => (string) $body['tag_name'], 'package' => $pkg ?: (string) ( $body['zipball_url'] ?? '' ), 'asset_api' => $api,
					'notes' => (string) ( $body['body'] ?? '' ), 'published' => (string) ( $body['published_at'] ?? '' ), 'html_url' => (string) ( $body['html_url'] ?? '' ) );
			} elseif ( 404 === $code ) {
				$row['error'] = self::token() ? 'HTTP 404 — token-ът няма достъп до ' . $repo . ' (частно репо: Fine-grained token с „Only select repositories“ → това репо, Contents: Read; за организация — одобрен от owner-а) или още няма Release.' : 'HTTP 404 — репото е частно (трябва token) или няма Release.';
			} elseif ( 401 === $code ) { $row['error'] = 'HTTP 401 — невалиден/изтекъл token.'; }
			else { $row['error'] = 'GitHub HTTP ' . $code . ( isset( $body['message'] ) ? ': ' . $body['message'] : '' ); }
		}
		$all[ $repo ] = $row; set_site_transient( self::CACHE, $all, self::TTL );
		return $row;
	}

	/* ---------- WP ъпдейт механизъм ---------- */
	public function inject( $t ) {
		if ( ! is_object( $t ) ) $t = new stdClass();
		foreach ( self::tracked() as $file => $p ) {
			$r = self::release( $p['repo'] ); $d = $r['data'] ?? null;
			$item = array( 'id' => 'github.com/' . $p['repo'], 'slug' => $p['slug'], 'plugin' => $file, 'new_version' => $p['version'], 'url' => 'https://github.com/' . $p['repo'], 'package' => '', 'icons' => array(), 'banners' => array(), 'banners_rtl' => array(), 'tested' => '', 'requires_php' => '7.4', 'compatibility' => new stdClass() );
			if ( ! $d || version_compare( $d['version'], $p['version'], '<=' ) ) {
				// Актуален: казваме го на WP през no_update — само така показва „Enable auto-updates“ и „Виж детайли“.
				unset( $t->response[ $file ] );
				if ( ! isset( $t->no_update ) || ! is_array( $t->no_update ) ) $t->no_update = array();
				$t->no_update[ $file ] = (object) $item;
				continue;
			}
			if ( isset( $t->no_update[ $file ] ) ) unset( $t->no_update[ $file ] );
			$item['new_version'] = $d['version']; $item['url'] = $d['html_url'];
			$item['package'] = ( self::token() && $d['asset_api'] ) ? $d['asset_api'] : $d['package'];
			$t->response[ $file ] = (object) $item;
		}
		return $t;
	}
	public function info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) return $res;
		foreach ( self::tracked() as $p ) {
			if ( $p['slug'] !== $args->slug ) continue;
			$d = self::release( $p['repo'] )['data'] ?? null; if ( ! $d ) return $res;
			return (object) array( 'name' => $p['name'], 'slug' => $p['slug'], 'version' => $d['version'], 'author' => 'LOGADOR', 'homepage' => 'https://github.com/' . $p['repo'], 'download_link' => $d['package'], 'last_updated' => $d['published'],
				'sections' => array( 'changelog' => nl2br( esc_html( $d['notes'] ?: 'Виж release-а в GitHub.' ) ) ) );
		}
		return $res;
	}
	/** zipball-ът се разархивира като owner-repo-<sha>/ — WP иска папка с името на плъгина. */
	public function fix_folder( $source, $remote, $upgrader, $extra ) {
		if ( empty( $extra['plugin'] ) ) return $source;
		$tr = self::tracked(); if ( ! isset( $tr[ $extra['plugin'] ] ) ) return $source;
		global $wp_filesystem;
		$want = trailingslashit( $remote ) . $tr[ $extra['plugin'] ]['slug'] . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) return $source;
		return ( $wp_filesystem && $wp_filesystem->move( $source, $want, true ) ) ? $want : $source;
	}
	public function auth( $args, $url ) {
		if ( ! self::token() ) return $args;
		if ( 0 !== strpos( $url, 'https://api.github.com/' ) ) {
			$ok = false; foreach ( self::tracked() as $p ) if ( 0 === strpos( $url, 'https://github.com/' . $p['repo'] ) ) $ok = true;
			if ( ! $ok ) return $args;
		}
		if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) $args['headers'] = array();
		$args['headers']['Authorization'] = 'Bearer ' . self::token();
		if ( false !== strpos( $url, '/releases/assets/' ) ) $args['headers']['Accept'] = 'application/octet-stream';
		return $args;
	}
	public function row_meta( $links, $file ) {
		$tr = self::tracked(); if ( isset( $tr[ $file ] ) ) $links[] = '<a href="https://github.com/' . esc_attr( $tr[ $file ]['repo'] ) . '/releases" target="_blank" rel="noopener">GitHub</a>';
		return $links;
	}

	/* ---------- Settings → GitHub ъпдейти ---------- */
	public function menu() { add_options_page( 'GitHub ъпдейти', 'GitHub ъпдейти', 'manage_options', 'logador-github', array( $this, 'page' ) ); }
	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Няма достъп.' ); check_admin_referer( 'logador_gh_save' );
		update_option( self::OPT_TOKEN, sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ), false );
		$sec = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) wp_unslash( $_POST['hook_secret'] ?? '' ) );
		if ( ! empty( $_POST['hook_regen'] ) ) delete_option( self::OPT_SECRET ); elseif ( strlen( $sec ) >= 16 ) update_option( self::OPT_SECRET, $sec, false );
		delete_site_transient( self::CACHE ); delete_site_transient( 'update_plugins' );
		wp_safe_redirect( admin_url( 'options-general.php?page=logador-github&saved=1' ) ); exit;
	}
	public function check() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Няма достъп.' ); check_admin_referer( 'logador_gh_check' );
		$rep = self::pull();
		set_transient( 'logador_gh_last_pull', $rep, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'options-general.php?page=logador-github&checked=1' ) ); exit;
	}

	/* ---------- v1.1.0: webhook + незабавен ъпдейт ---------- */
	/** GitHub Action (release.yml) → POST admin-post.php?action=logador_gh_hook с header X-Logador-Secret. Отговорът е JSON отчет. */
	public function hook() {
		$given  = isset( $_SERVER['HTTP_X_LOGADOR_SECRET'] ) ? (string) $_SERVER['HTTP_X_LOGADOR_SECRET'] : (string) ( $_POST['secret'] ?? '' );
		$given  = trim( wp_unslash( $given ) );
		$secret = trim( (string) get_option( self::OPT_SECRET, '' ) );
		if ( '' === $secret || '' === $given || ! hash_equals( $secret, $given ) ) wp_send_json( array( 'ok' => false, 'error' => 'Невалиден secret — виж Settings → GitHub ъпдейти.' ), 403 );
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 300 );
		$only = sanitize_text_field( wp_unslash( $_POST['repo'] ?? '' ) );
		$rep  = self::pull( $only );
		set_transient( 'logador_gh_last_pull', $rep, DAY_IN_SECONDS );
		wp_send_json( $rep, $rep['ok'] ? 200 : 500 );
	}

	/**
	 * Ядрото: свеж release от GitHub → WP update_plugins → WP_Automatic_Updater (същото, което WP кронът прави 2× дневно, но сега).
	 * Обновяват се само плъгините с включени auto-updates (Plugins → Enable auto-updates); за другите само се появява „Update now“.
	 */
	public static function pull( $only_repo = '' ) {
		$rep = array( 'ok' => true, 'updater' => LOGADOR_GH_UPDATER_VERSION, 'at' => gmdate( 'c' ), 'plugins' => array(), 'notes' => array() );
		$tr  = self::tracked();
		if ( ! $tr ) { $rep['notes'][] = 'Няма плъгин с „GitHub Plugin URI“ в header-а.'; return $rep; }

		/* 1. Свежи данни от GitHub (без кеша). */
		delete_site_transient( self::CACHE );
		foreach ( $tr as $p ) {
			if ( $only_repo && 0 !== strcasecmp( $only_repo, $p['repo'] ) ) continue;
			$r = self::release( $p['repo'], true );
			if ( ! empty( $r['error'] ) ) $rep['notes'][] = $p['repo'] . ': ' . $r['error'];
		}

		/* 2. WP transient-ът — inject() минава през pre_set филтъра; ако api.wordpress.org не отговори, го записваме сами. */
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$t = get_site_transient( 'update_plugins' );
		if ( ! is_object( $t ) ) $t = new stdClass();
		$t->last_checked = time();
		set_site_transient( 'update_plugins', $t );
		$t = get_site_transient( 'update_plugins' );

		$pending = array();
		foreach ( $tr as $file => $p ) if ( is_object( $t ) && isset( $t->response[ $file ] ) ) $pending[ $file ] = $t->response[ $file ]->new_version;
		$auto = (array) get_site_option( 'auto_update_plugins', array() );

		/* 3. Auto-update — точно през WP_Automatic_Updater, за да важат всички правила на WP (lock, DISALLOW_FILE_MODS, VCS, имейли). */
		$results = array();
		if ( $pending ) {
			include_once ABSPATH . 'wp-admin/includes/admin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			if ( ! class_exists( 'WP_Automatic_Updater' ) ) require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
			$u = new WP_Automatic_Updater();
			if ( $u->is_disabled() ) $rep['notes'][] = 'WP automatic updater е изключен (AUTOMATIC_UPDATER_DISABLED / DISALLOW_FILE_MODS / филтър automatic_updater_disabled).';
			elseif ( $u->is_vcs_checkout( WP_PLUGIN_DIR ) ) $rep['notes'][] = 'Плъгините са в VCS checkout (.git/.svn) — WP не прави auto-update там.';
			else {
				$lock = (int) get_option( 'auto_updater.lock', 0 );
				if ( $lock && time() - $lock < HOUR_IN_SECONDS ) $rep['notes'][] = 'auto_updater.lock е зает (друг ъпдейт тече) — ще мине при следващия опит.';
			}
			$grab = function ( $all ) use ( &$results ) { /* update_results е protected — идва през този хук */
				foreach ( (array) ( $all['plugin'] ?? array() ) as $r ) {
					$file = isset( $r->item->plugin ) ? (string) $r->item->plugin : '';
					$results[ $file ] = is_wp_error( $r->result ) ? $r->result->get_error_message() : ( $r->result ? 'ok' : 'fail' );
				}
			};
			/* v1.1.1: Plugin_Upgrader::upgrade() ДЕАКТИВИРА активния плъгин преди ъпдейт, освен когато
			 * wp_doing_cron() — WP разчита браузърът (update.php) да го реактивира после. Тук сме в
			 * webhook/admin-post заявка, не в cron → плъгинът оставаше изключен след всеки ъпдейт.
			 * Казваме на WP, че сме „cron" само за времето на run() (точно пътят на фоновите ъпдейти),
			 * и за всеки случай реактивираме тихо каквото все пак е било изключено. */
			$was_active = array();
			foreach ( array_keys( $tr ) as $file ) if ( is_plugin_active( $file ) ) $was_active[ $file ] = is_multisite() && is_plugin_active_for_network( $file );
			$as_cron = '__return_true';
			add_filter( 'wp_doing_cron', $as_cron );
			add_action( 'automatic_updates_complete', $grab );
			try { $u->run(); }
			finally {
				remove_action( 'automatic_updates_complete', $grab );
				remove_filter( 'wp_doing_cron', $as_cron );
			}
			foreach ( $was_active as $file => $network ) {
				if ( is_plugin_active( $file ) || ! is_file( WP_PLUGIN_DIR . '/' . $file ) ) continue;
				$r = activate_plugin( $file, '', $network, true );
				$rep['notes'][] = is_wp_error( $r ) ? $file . ': WP го деактивира при ъпдейта и реактивирането се провали — ' . $r->get_error_message() : $file . ': WP го беше деактивирал при ъпдейта — реактивиран.';
			}
		}

		/* 4. Отчет — какво е тук, какво е в GitHub, обнови ли се. */
		wp_clean_plugins_cache( false );
		$after = self::tracked();
		foreach ( $tr as $file => $p ) {
			$d   = self::release( $p['repo'] )['data'] ?? null;
			$row = array( 'plugin' => $p['name'], 'file' => $file, 'repo' => $p['repo'], 'before' => $p['version'], 'after' => $after[ $file ]['version'] ?? $p['version'],
				'github' => $d ? $d['version'] : null, 'auto_updates' => in_array( $file, $auto, true ), 'updated' => false );
			$row['updated'] = version_compare( $row['after'], $row['before'], '>' );
			if ( isset( $pending[ $file ] ) && ! $row['updated'] ) {
				$rep['ok'] = false;
				if ( ! $row['auto_updates'] ) $row['error'] = 'auto-updates са изключени за този плъгин — Plugins → Enable auto-updates (или Update now ръчно).';
				elseif ( isset( $results[ $file ] ) && 'ok' !== $results[ $file ] ) $row['error'] = 'WP_Automatic_Updater: ' . $results[ $file ];
				else $row['error'] = 'WP_Automatic_Updater не го обнови — виж notes.';
			}
			$rep['plugins'][] = $row;
		}
		return $rep;
	}
	public function page() {
		$tr = self::tracked();
		echo '<div class="wrap"><h1>GitHub ъпдейти (LOGADOR)</h1>';
		if ( ! empty( $_GET['saved'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Записано.</p></div>';
		if ( ! empty( $_GET['checked'] ) ) {
			$last = get_transient( 'logador_gh_last_pull' );
			$msg  = 'Проверено и обновено, където auto-updates са включени — виж таблицата.';
			if ( is_array( $last ) ) {
				$upd = array(); foreach ( $last['plugins'] as $row ) if ( ! empty( $row['updated'] ) ) $upd[] = $row['plugin'] . ' ' . $row['before'] . ' → ' . $row['after'];
				$err = array(); foreach ( $last['plugins'] as $row ) if ( ! empty( $row['error'] ) ) $err[] = $row['plugin'] . ': ' . $row['error'];
				$msg = ( $upd ? 'Обновени: ' . implode( ', ', $upd ) . '.' : 'Нищо ново за обновяване.' ) . ( $err ? ' Проблеми: ' . implode( ' · ', $err ) : '' ) . ( ! empty( $last['notes'] ) ? ' Бележки: ' . implode( ' · ', $last['notes'] ) : '' );
			}
			echo '<div class="notice notice-' . ( is_array( $last ) && ! $last['ok'] ? 'warning' : 'success' ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		echo '<p>Един token за всички плъгини. Всеки плъгин с ред <code>GitHub Plugin URI: owner/repo</code> в header-а си се появява тук и се обновява от последния GitHub Release. Публично репо не иска token.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'logador_gh_save' ); echo '<input type="hidden" name="action" value="logador_gh_save">';
		echo '<table class="form-table"><tr><th>GitHub token</th><td><input type="password" name="token" class="regular-text" value="' . esc_attr( self::token() ) . '" autocomplete="new-password"><p class="description">Fine-grained PAT: Repository access → всички репота на LOGADOR (или „All repositories“), Permissions → Contents: Read-only. Един за целия сайт.</p></td></tr><tr><th>Webhook secret</th><td><input type="text" name="hook_secret" class="regular-text code" value="' . esc_attr( self::secret() ) . '" autocomplete="off"> <label style="margin-left:8px"><input type="checkbox" name="hook_regen" value="1"> изтрий и генерирай нов</label>';
		echo '<p class="description">Webhook URL: <code>' . esc_html( self::hook_url() ) . '</code><br>В GitHub репото → Settings → Secrets and variables → Actions: <code>LOGADOR_HOOK_URL</code> = този URL (няколко сайта — през запетая), <code>LOGADOR_HOOK_SECRET</code> = secret-ът горе. При всеки release Action-ът пингва сайта и той се обновява <b>веднага</b> (за плъгините с включени auto-updates), без да чака крона. Без secrets всичко работи както преди — проверка на 6 ч.</p></td></tr></table>';
		submit_button( 'Запази' ); echo '</form>';
		echo '<h2>Следени плъгини</h2><table class="widefat striped"><thead><tr><th>Плъгин</th><th>Репо</th><th>Тук</th><th>В GitHub</th><th>Състояние</th></tr></thead><tbody>';
		if ( ! $tr ) echo '<tr><td colspan="5">Няма плъгин с „GitHub Plugin URI“ в header-а.</td></tr>';
		foreach ( $tr as $p ) {
			$r = self::release( $p['repo'] ); $d = $r['data'] ?? null;
			$st = $d ? ( version_compare( $d['version'], $p['version'], '>' ) ? '<b style="color:#b8481c">нова версия → Plugins → Update now</b>' : '<span style="color:#1f7a4d">актуален</span>' ) : '<b style="color:#d64545">' . esc_html( $r['error'] ?: 'няма данни' ) . '</b>';
			echo '<tr><td><b>' . esc_html( $p['name'] ) . '</b></td><td><a href="https://github.com/' . esc_attr( $p['repo'] ) . '/releases" target="_blank">' . esc_html( $p['repo'] ) . '</a></td><td>' . esc_html( $p['version'] ) . '</td><td>' . esc_html( $d['version'] ?? '—' ) . '</td><td>' . $st . '</td></tr>';
		}
		echo '</tbody></table><p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=logador_gh_check' ), 'logador_gh_check' ) ) . '">Провери и обнови сега</a> &nbsp; <span class="description">Същото като webhook-а: дърпа release-ите и обновява плъгините с включени auto-updates. Иначе се проверява на 6 ч. Auto-updates се включват от Plugins → „Enable auto-updates“ за всеки плъгин.</span></p></div>';
	}
}
LOGADOR_GitHub_Updater::instance();
