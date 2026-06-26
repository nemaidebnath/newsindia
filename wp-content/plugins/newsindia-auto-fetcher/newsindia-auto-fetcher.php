<?php
/**
 * Plugin Name: News India Auto Fetcher
 * Description: Fetches trending, India, and global news into WordPress posts with category assignment, duplicate checks, and scheduled publishing.
 * Version: 1.0.0
 * Author: News India
 * Text Domain: newsindia-auto-fetcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NewsIndia_Auto_Fetcher {
	const VERSION = '1.0.0';
	const OPTION_SOURCES = 'niaf_sources';
	const OPTION_SETTINGS = 'niaf_settings';
	const CRON_HOOK = 'niaf_fetch_news_event';
	const MAX_PER_RUN = 20;

	private static $instance = null;
	private $run_stats = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( self::CRON_HOOK, array( $this, 'cron_fetch' ) );
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
	}

	public static function activate() {
		self::instance()->seed_categories();
		self::instance()->ensure_defaults();
		self::instance()->schedule_next_fetch();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function cron_schedules( $schedules ) {
		$schedules['niaf_fifteen_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes for News India checks', 'newsindia-auto-fetcher' ),
		);

		return $schedules;
	}

	public function admin_menu() {
		add_menu_page(
			__( 'News Auto Fetcher', 'newsindia-auto-fetcher' ),
			__( 'News Auto Fetcher', 'newsindia-auto-fetcher' ),
			'manage_options',
			'newsindia-auto-fetcher',
			array( $this, 'render_admin_page' ),
			'dashicons-rss',
			58
		);
	}

	public function handle_admin_actions() {
		if ( empty( $_POST['niaf_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'niaf_save_settings' );

		$action = sanitize_text_field( wp_unslash( $_POST['niaf_action'] ) );

		if ( 'save' === $action ) {
			$this->save_settings();
			wp_safe_redirect( add_query_arg( 'niaf_saved', '1', menu_page_url( 'newsindia-auto-fetcher', false ) ) );
			exit;
		}

		if ( 'run_now' === $action ) {
			$stats = $this->fetch_news();
			set_transient( 'niaf_last_manual_run', $stats, 10 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'niaf_ran', '1', menu_page_url( 'newsindia-auto-fetcher', false ) ) );
			exit;
		}

		if ( 'backfill_ai_images' === $action ) {
			$stats = $this->backfill_missing_ai_images();
			set_transient( 'niaf_last_ai_backfill', $stats, 10 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'niaf_ai_backfilled', '1', menu_page_url( 'newsindia-auto-fetcher', false ) ) );
			exit;
		}
	}

	public function render_admin_page() {
		$this->ensure_defaults();
		$sources = $this->get_sources();
		$sources[] = array(
			'enabled'  => false,
			'name'     => '',
			'type'     => 'google_rss',
			'base_url' => '',
			'api_key'  => '',
		);
		$settings = $this->get_settings();
		$manual_stats = get_transient( 'niaf_last_manual_run' );
		$ai_backfill_stats = get_transient( 'niaf_last_ai_backfill' );
		$next = wp_next_scheduled( self::CRON_HOOK );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'News India Auto Fetcher', 'newsindia-auto-fetcher' ); ?></h1>

			<?php if ( isset( $_GET['niaf_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'newsindia-auto-fetcher' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['niaf_ai_backfilled'] ) && is_array( $ai_backfill_stats ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							esc_html__( 'AI image backfill complete. Generated: %1$d, skipped: %2$d, errors: %3$d.', 'newsindia-auto-fetcher' ),
							(int) $ai_backfill_stats['generated'],
							(int) $ai_backfill_stats['skipped'],
							(int) $ai_backfill_stats['errors']
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['niaf_ran'] ) && is_array( $manual_stats ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							esc_html__( 'Manual run complete. Inserted: %1$d, updated: %2$d, skipped: %3$d, errors: %4$d.', 'newsindia-auto-fetcher' ),
							(int) $manual_stats['inserted'],
							(int) $manual_stats['updated'],
							(int) $manual_stats['skipped'],
							(int) $manual_stats['errors']
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'Fetch order: trending news from yesterday, India news, then global news. Each run stops at 20 inserted or updated posts.', 'newsindia-auto-fetcher' ); ?>
				<?php if ( $next ) : ?>
					<?php printf( esc_html__( 'Next scheduled check: %s IST.', 'newsindia-auto-fetcher' ), esc_html( wp_date( 'Y-m-d H:i:s', $next, new DateTimeZone( 'Asia/Kolkata' ) ) ) ); ?>
				<?php endif; ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'niaf_save_settings' ); ?>
				<input type="hidden" name="niaf_action" value="save" />

				<h2><?php esc_html_e( 'Run Settings', 'newsindia-auto-fetcher' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="niaf_max_per_run"><?php esc_html_e( 'Max posts per run', 'newsindia-auto-fetcher' ); ?></label></th>
						<td><input type="number" min="1" max="20" id="niaf_max_per_run" name="settings[max_per_run]" value="<?php echo esc_attr( $settings['max_per_run'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="niaf_similarity"><?php esc_html_e( 'Title similarity threshold', 'newsindia-auto-fetcher' ); ?></label></th>
						<td><input type="number" min="70" max="100" id="niaf_similarity" name="settings[title_similarity]" value="<?php echo esc_attr( $settings['title_similarity'] ); ?>" />%</td>
					</tr>
					<tr>
						<th scope="row"><label for="niaf_user_agent"><?php esc_html_e( 'Article fetch user agent', 'newsindia-auto-fetcher' ); ?></label></th>
						<td><input type="text" class="regular-text" id="niaf_user_agent" name="settings[user_agent]" value="<?php echo esc_attr( $settings['user_agent'] ); ?>" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'AI Featured Image Fallback', 'newsindia-auto-fetcher' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable fallback', 'newsindia-auto-fetcher' ); ?></th>
						<td>
							<input type="hidden" name="settings[ai_image_enabled]" value="0" />
							<label><input type="checkbox" name="settings[ai_image_enabled]" value="1" <?php checked( ! empty( $settings['ai_image_enabled'] ) ); ?> /> <?php esc_html_e( 'Generate an AI image when a news item has no usable featured image.', 'newsindia-auto-fetcher' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="niaf_ai_provider"><?php esc_html_e( 'Provider', 'newsindia-auto-fetcher' ); ?></label></th>
						<td>
							<select id="niaf_ai_provider" name="settings[ai_image_provider]">
								<option value="pollinations" <?php selected( $settings['ai_image_provider'], 'pollinations' ); ?>>Pollinations AI</option>
								<option value="huggingface" <?php selected( $settings['ai_image_provider'], 'huggingface' ); ?>>Hugging Face</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="niaf_ai_key"><?php esc_html_e( 'API key', 'newsindia-auto-fetcher' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="niaf_ai_key" name="settings[ai_image_api_key]" value="<?php echo esc_attr( $settings['ai_image_api_key'] ); ?>" autocomplete="new-password" />
							<p class="description"><?php esc_html_e( 'Pollinations does not require a key. Hugging Face requires a token.', 'newsindia-auto-fetcher' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="niaf_hf_model"><?php esc_html_e( 'Hugging Face model URL', 'newsindia-auto-fetcher' ); ?></label></th>
						<td><input type="url" class="large-text" id="niaf_hf_model" name="settings[ai_huggingface_model_url]" value="<?php echo esc_url( $settings['ai_huggingface_model_url'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="niaf_ai_size"><?php esc_html_e( 'Image size', 'newsindia-auto-fetcher' ); ?></label></th>
						<td>
							<input type="number" min="512" max="1536" step="64" id="niaf_ai_width" name="settings[ai_image_width]" value="<?php echo esc_attr( $settings['ai_image_width'] ); ?>" /> x
							<input type="number" min="512" max="1536" step="64" id="niaf_ai_height" name="settings[ai_image_height]" value="<?php echo esc_attr( $settings['ai_image_height'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="niaf_ai_disabled_categories"><?php esc_html_e( 'Disabled categories/keywords', 'newsindia-auto-fetcher' ); ?></label></th>
						<td>
							<input type="text" class="large-text" id="niaf_ai_disabled_categories" name="settings[ai_disabled_categories]" value="<?php echo esc_attr( $settings['ai_disabled_categories'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Comma-separated values. AI images will be skipped when the title or category matches these words.', 'newsindia-auto-fetcher' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="niaf_ai_caption"><?php esc_html_e( 'Caption', 'newsindia-auto-fetcher' ); ?></label></th>
						<td><input type="text" class="large-text" id="niaf_ai_caption" name="settings[ai_image_caption]" value="<?php echo esc_attr( $settings['ai_image_caption'] ); ?>" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Sources', 'newsindia-auto-fetcher' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Enabled', 'newsindia-auto-fetcher' ); ?></th>
							<th><?php esc_html_e( 'Name', 'newsindia-auto-fetcher' ); ?></th>
							<th><?php esc_html_e( 'Type', 'newsindia-auto-fetcher' ); ?></th>
							<th><?php esc_html_e( 'Base URL', 'newsindia-auto-fetcher' ); ?></th>
							<th><?php esc_html_e( 'API Key', 'newsindia-auto-fetcher' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sources as $index => $source ) : ?>
							<tr>
								<td>
									<input type="hidden" name="sources[<?php echo esc_attr( $index ); ?>][enabled]" value="0" />
									<input type="checkbox" name="sources[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $source['enabled'] ) ); ?> />
								</td>
								<td><input type="text" name="sources[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $source['name'] ); ?>" /></td>
								<td>
									<select name="sources[<?php echo esc_attr( $index ); ?>][type]">
										<?php foreach ( array( 'google_rss', 'gnews', 'newsapi', 'mediastack' ) as $type ) : ?>
											<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $source['type'], $type ); ?>><?php echo esc_html( $type ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><input type="url" class="large-text" name="sources[<?php echo esc_attr( $index ); ?>][base_url]" value="<?php echo esc_url( $source['base_url'] ); ?>" /></td>
								<td><input type="text" class="regular-text" name="sources[<?php echo esc_attr( $index ); ?>][api_key]" value="<?php echo esc_attr( $source['api_key'] ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save Settings', 'newsindia-auto-fetcher' ) ); ?>
			</form>

			<form method="post">
				<?php wp_nonce_field( 'niaf_save_settings' ); ?>
				<input type="hidden" name="niaf_action" value="run_now" />
				<?php submit_button( __( 'Run Fetch Now', 'newsindia-auto-fetcher' ), 'secondary' ); ?>
			</form>

			<form method="post">
				<?php wp_nonce_field( 'niaf_save_settings' ); ?>
				<input type="hidden" name="niaf_action" value="backfill_ai_images" />
				<?php submit_button( __( 'Generate Missing AI Images Now', 'newsindia-auto-fetcher' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	private function save_settings() {
		$settings_input = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$sources_input = isset( $_POST['sources'] ) ? wp_unslash( $_POST['sources'] ) : array();
		$ai_provider = sanitize_key( $settings_input['ai_image_provider'] ?? 'pollinations' );
		if ( ! in_array( $ai_provider, array( 'pollinations', 'huggingface' ), true ) ) {
			$ai_provider = 'pollinations';
		}

		$settings = array(
			'max_per_run'                 => min( self::MAX_PER_RUN, max( 1, absint( $settings_input['max_per_run'] ?? self::MAX_PER_RUN ) ) ),
			'title_similarity'            => min( 100, max( 70, absint( $settings_input['title_similarity'] ?? 88 ) ) ),
			'user_agent'                  => sanitize_text_field( $settings_input['user_agent'] ?? 'NewsIndiaBot/1.0' ),
			'ai_image_enabled'            => ! empty( $settings_input['ai_image_enabled'] ),
			'ai_image_provider'           => $ai_provider,
			'ai_image_api_key'            => sanitize_text_field( $settings_input['ai_image_api_key'] ?? '' ),
			'ai_huggingface_model_url'    => esc_url_raw( $settings_input['ai_huggingface_model_url'] ?? 'https://api-inference.huggingface.co/models/stabilityai/stable-diffusion-xl-base-1.0' ),
			'ai_image_width'              => min( 1536, max( 512, absint( $settings_input['ai_image_width'] ?? 1024 ) ) ),
			'ai_image_height'             => min( 1536, max( 512, absint( $settings_input['ai_image_height'] ?? 576 ) ) ),
			'ai_disabled_categories'      => sanitize_text_field( $settings_input['ai_disabled_categories'] ?? 'Crime, Politics, War, Disaster, Accident, Death, Violence, Terror' ),
			'ai_image_caption'            => sanitize_text_field( $settings_input['ai_image_caption'] ?? 'AI-generated representative image.' ),
		);

		$sources = array();
		foreach ( (array) $sources_input as $source ) {
			$type = sanitize_key( $source['type'] ?? '' );
			$name = sanitize_text_field( $source['name'] ?? '' );
			$base_url = esc_url_raw( $source['base_url'] ?? '' );

			if ( '' === $name && '' === $base_url ) {
				continue;
			}

			if ( ! in_array( $type, array( 'google_rss', 'gnews', 'newsapi', 'mediastack' ), true ) ) {
				continue;
			}

			$sources[] = array(
				'enabled'  => ! empty( $source['enabled'] ),
				'name'     => $name,
				'type'     => $type,
				'base_url' => $base_url,
				'api_key'  => sanitize_text_field( $source['api_key'] ?? '' ),
			);
		}

		update_option( self::OPTION_SETTINGS, $settings, false );
		update_option( self::OPTION_SOURCES, $sources, false );
		$this->schedule_next_fetch();
	}

	public function cron_fetch() {
		$this->fetch_news();
		$this->schedule_next_fetch();
	}

	public function schedule_next_fetch() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( $this->next_run_timestamp(), self::CRON_HOOK );
	}

	private function next_run_timestamp() {
		$tz = new DateTimeZone( 'Asia/Kolkata' );
		$now = new DateTimeImmutable( 'now', $tz );
		$hours = array( 0, 6, 12, 18 );

		foreach ( $hours as $hour ) {
			$candidate = $now->setTime( $hour, 0, 0 );
			if ( $candidate->getTimestamp() > $now->getTimestamp() + 60 ) {
				return $candidate->getTimestamp();
			}
		}

		return $now->modify( '+1 day' )->setTime( 0, 0, 0 )->getTimestamp();
	}

	public function fetch_news() {
		$this->ensure_defaults();
		$this->run_stats = array(
			'inserted' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => 0,
			'ai_images' => 0,
		);

		$settings = $this->get_settings();
		$limit = min( self::MAX_PER_RUN, absint( $settings['max_per_run'] ) );
		$queries = $this->build_queries();

		foreach ( $queries as $query ) {
			foreach ( $this->get_enabled_sources() as $source ) {
				if ( ( $this->run_stats['inserted'] + $this->run_stats['updated'] ) >= $limit ) {
					return $this->run_stats;
				}

				$articles = $this->fetch_from_source( $source, $query, $limit );
				foreach ( $articles as $article ) {
					if ( ( $this->run_stats['inserted'] + $this->run_stats['updated'] ) >= $limit ) {
						break;
					}

					$this->upsert_article( $article, $query['category'], $settings );
				}
			}
		}

		return $this->run_stats;
	}

	private function build_queries() {
		$tz = new DateTimeZone( 'Asia/Kolkata' );
		$yesterday = new DateTimeImmutable( 'yesterday', $tz );
		$today = new DateTimeImmutable( 'today', $tz );

		return array(
			array(
				'label'    => 'Trending Yesterday',
				'query'    => 'trending news',
				'category' => 'Trending',
				'from'     => $yesterday->format( 'Y-m-d' ),
				'to'       => $today->format( 'Y-m-d' ),
			),
			array(
				'label'    => 'India News',
				'query'    => 'India news',
				'category' => 'India',
				'from'     => $today->format( 'Y-m-d' ),
				'to'       => $today->modify( '+1 day' )->format( 'Y-m-d' ),
			),
			array(
				'label'    => 'Global News',
				'query'    => 'world news',
				'category' => 'World',
				'from'     => $today->format( 'Y-m-d' ),
				'to'       => $today->modify( '+1 day' )->format( 'Y-m-d' ),
			),
		);
	}

	private function fetch_from_source( $source, $query, $limit ) {
		switch ( $source['type'] ) {
			case 'google_rss':
				return $this->fetch_google_rss( $source, $query, $limit );
			case 'gnews':
				return $this->fetch_gnews( $source, $query, $limit );
			case 'newsapi':
				return $this->fetch_newsapi( $source, $query, $limit );
			case 'mediastack':
				return $this->fetch_mediastack( $source, $query, $limit );
			default:
				return array();
		}
	}

	private function fetch_google_rss( $source, $query, $limit ) {
		$url = add_query_arg(
			array(
				'q'     => $query['query'] . ' after:' . $query['from'] . ' before:' . $query['to'],
				'hl'    => 'en-IN',
				'gl'    => 'IN',
				'ceid'  => 'IN:en',
			),
			$source['base_url']
		);

		$response = $this->remote_get( $url );
		if ( is_wp_error( $response ) ) {
			$this->run_stats['errors']++;
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return array();
		}

		$xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA );
		if ( false === $xml || empty( $xml->channel->item ) ) {
			$this->run_stats['errors']++;
			return array();
		}

		$articles = array();
		foreach ( $xml->channel->item as $item ) {
			$articles[] = array(
				'title'       => (string) $item->title,
				'description' => wp_strip_all_tags( (string) $item->description ),
				'content'     => '',
				'url'         => (string) $item->link,
				'image_url'   => '',
				'published'   => (string) $item->pubDate,
				'category'    => $query['category'],
				'source'      => $source['name'],
			);

			if ( count( $articles ) >= $limit ) {
				break;
			}
		}

		return $articles;
	}

	private function fetch_gnews( $source, $query, $limit ) {
		if ( empty( $source['api_key'] ) ) {
			return array();
		}

		$url = add_query_arg(
			array(
				'q'      => $query['query'],
				'lang'   => 'en',
				'max'    => min( 10, $limit ),
				'from'   => $query['from'] . 'T00:00:00Z',
				'to'     => $query['to'] . 'T00:00:00Z',
				'token'  => $source['api_key'],
			),
			$source['base_url']
		);

		$response = $this->remote_get( $url );
		if ( is_wp_error( $response ) ) {
			$this->run_stats['errors']++;
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['articles'] ) || ! is_array( $data['articles'] ) ) {
			return array();
		}

		return array_map(
			function ( $item ) use ( $source, $query ) {
				return array(
					'title'       => $item['title'] ?? '',
					'description' => $item['description'] ?? '',
					'content'     => $item['content'] ?? '',
					'url'         => $item['url'] ?? '',
					'image_url'   => $item['image'] ?? '',
					'published'   => $item['publishedAt'] ?? '',
					'category'    => $query['category'],
					'source'      => $source['name'],
				);
			},
			$data['articles']
		);
	}

	private function fetch_newsapi( $source, $query, $limit ) {
		if ( empty( $source['api_key'] ) ) {
			return array();
		}

		$url = add_query_arg(
			array(
				'q'        => $query['query'],
				'language' => 'en',
				'pageSize' => min( 20, $limit ),
				'from'     => $query['from'],
				'to'       => $query['to'],
				'sortBy'   => 'publishedAt',
				'apiKey'   => $source['api_key'],
			),
			$source['base_url']
		);

		$response = $this->remote_get( $url );
		if ( is_wp_error( $response ) ) {
			$this->run_stats['errors']++;
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['articles'] ) || ! is_array( $data['articles'] ) ) {
			return array();
		}

		return array_map(
			function ( $item ) use ( $source, $query ) {
				return array(
					'title'       => $item['title'] ?? '',
					'description' => $item['description'] ?? '',
					'content'     => $item['content'] ?? '',
					'url'         => $item['url'] ?? '',
					'image_url'   => $item['urlToImage'] ?? '',
					'published'   => $item['publishedAt'] ?? '',
					'category'    => $query['category'],
					'source'      => $source['name'],
				);
			},
			$data['articles']
		);
	}

	private function fetch_mediastack( $source, $query, $limit ) {
		if ( empty( $source['api_key'] ) ) {
			return array();
		}

		$url = add_query_arg(
			array(
				'access_key' => $source['api_key'],
				'keywords'   => $query['query'],
				'languages'  => 'en',
				'limit'      => min( 20, $limit ),
				'date'       => $query['from'] . ',' . $query['to'],
			),
			$source['base_url']
		);

		$response = $this->remote_get( $url );
		if ( is_wp_error( $response ) ) {
			$this->run_stats['errors']++;
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return array();
		}

		return array_map(
			function ( $item ) use ( $source, $query ) {
				return array(
					'title'       => $item['title'] ?? '',
					'description' => $item['description'] ?? '',
					'content'     => $item['description'] ?? '',
					'url'         => $item['url'] ?? '',
					'image_url'   => $item['image'] ?? '',
					'published'   => $item['published_at'] ?? '',
					'category'    => $query['category'],
					'source'      => $source['name'],
				);
			},
			$data['data']
		);
	}

	private function upsert_article( $article, $fallback_category, $settings ) {
		$title = sanitize_text_field( $article['title'] ?? '' );
		$url = esc_url_raw( $article['url'] ?? '' );

		if ( '' === $title || '' === $url ) {
			$this->run_stats['skipped']++;
			return;
		}

		$existing_id = $this->find_post_by_source_url( $url );
		if ( ! $existing_id && $this->has_similar_title_today( $title, (int) $settings['title_similarity'] ) ) {
			$this->run_stats['skipped']++;
			return;
		}

		$full_content = $this->build_content( $article );
		$category_names = $this->infer_article_category_names( $article, $fallback_category );
		$category_ids = $this->resolve_categories( $category_names );
		$primary_category = $category_names[0] ?? ( $article['category'] ?: $fallback_category );
		$post_date = $this->parse_post_date( $article['published'] ?? '' );

		$post_data = array(
			'post_title'    => $title,
			'post_content'  => $full_content,
			'post_excerpt'  => sanitize_textarea_field( $article['description'] ?? '' ),
			'post_status'   => 'publish',
			'post_type'     => 'post',
			'post_category' => $category_ids,
			'post_date'     => $post_date,
			'post_date_gmt' => get_gmt_from_date( $post_date ),
		);

		if ( $existing_id ) {
			$post_data['ID'] = $existing_id;
			$post_id = wp_update_post( wp_slash( $post_data ), true );
			$stat = 'updated';
		} else {
			$post_id = wp_insert_post( wp_slash( $post_data ), true );
			$stat = 'inserted';
		}

		if ( is_wp_error( $post_id ) ) {
			$this->run_stats['errors']++;
			return;
		}

		update_post_meta( $post_id, '_niaf_source_url', $url );
		update_post_meta( $post_id, '_niaf_source_name', sanitize_text_field( $article['source'] ?? '' ) );
		update_post_meta( $post_id, '_niaf_title_fingerprint', $this->title_fingerprint( $title ) );

		if ( ! has_post_thumbnail( $post_id ) && ! empty( $article['image_url'] ) ) {
			$this->sideload_featured_image( $post_id, esc_url_raw( $article['image_url'] ) );
		}

		if ( ! has_post_thumbnail( $post_id ) && ! empty( $settings['ai_image_enabled'] ) ) {
			if ( $this->maybe_generate_ai_featured_image( $post_id, $article, $primary_category, $settings ) ) {
				$this->run_stats['ai_images']++;
			}
		}

		$this->run_stats[ $stat ]++;
	}

	public function backfill_missing_ai_images() {
		$settings = $this->get_settings();
		$stats = array(
			'generated' => 0,
			'skipped'   => 0,
			'errors'    => 0,
		);

		if ( empty( $settings['ai_image_enabled'] ) ) {
			$stats['skipped'] = 1;
			return $stats;
		}

		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_PER_RUN,
				'meta_query'     => array(
					array(
						'key'     => '_thumbnail_id',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $posts as $post ) {
			$categories = get_the_category( $post->ID );
			$category_name = $categories ? $categories[0]->name : 'News';
			$article = array(
				'title'       => $post->post_title,
				'description' => $post->post_excerpt,
				'category'    => $category_name,
			);

			if ( $this->maybe_generate_ai_featured_image( $post->ID, $article, $category_name, $settings ) ) {
				$stats['generated']++;
			} elseif ( get_post_meta( $post->ID, '_niaf_ai_image_skipped', true ) ) {
				$stats['skipped']++;
			} else {
				$stats['errors']++;
			}
		}

		return $stats;
	}

	public function recategorize_existing_posts() {
		$stats = array(
			'updated' => 0,
			'skipped' => 0,
		);

		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		foreach ( $posts as $post ) {
			$current_categories = get_the_category( $post->ID );
			$fallback = $current_categories ? $current_categories[0]->name : 'General';
			$article = array(
				'title'       => $post->post_title,
				'description' => $post->post_excerpt,
				'content'     => $post->post_content,
				'category'    => $fallback,
			);
			$category_names = $this->infer_article_category_names( $article, $fallback );
			$category_ids = $this->resolve_categories( $category_names );

			if ( empty( $category_ids ) ) {
				$stats['skipped']++;
				continue;
			}

			wp_set_post_categories( $post->ID, $category_ids, false );
			$stats['updated']++;
		}

		return $stats;
	}

	private function build_content( $article ) {
		$parts = array();
		$article_url = esc_url_raw( $article['url'] ?? '' );
		$description = wp_kses_post( $article['description'] ?? '' );
		$content = wp_kses_post( $article['content'] ?? '' );
		$fetched = $article_url ? $this->fetch_article_body( $article_url ) : '';

		if ( $fetched ) {
			$parts[] = $fetched;
		} elseif ( $content ) {
			$parts[] = wpautop( $content );
		} elseif ( $description ) {
			$parts[] = wpautop( $description );
		}

		if ( $article_url ) {
			$parts[] = '<p><strong>Source:</strong> <a href="' . esc_url( $article_url ) . '" target="_blank" rel="nofollow noopener">Read original report</a></p>';
		}

		return implode( "\n\n", $parts );
	}

	private function fetch_article_body( $url ) {
		$response = $this->remote_get( $url );
		if ( is_wp_error( $response ) ) {
			return '';
		}

		$html = wp_remote_retrieve_body( $response );
		if ( strlen( $html ) < 500 ) {
			return '';
		}

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();

		if ( ! $loaded ) {
			return '';
		}

		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//article//p | //main//p | //div[contains(@class, "article")]//p | //div[contains(@class, "content")]//p' );
		if ( ! $nodes || 0 === $nodes->length ) {
			$nodes = $xpath->query( '//p' );
		}

		$paragraphs = array();
		foreach ( $nodes as $node ) {
			$text = trim( preg_replace( '/\s+/', ' ', $node->textContent ) );
			if ( strlen( $text ) < 80 ) {
				continue;
			}

			$paragraphs[] = '<p>' . esc_html( $text ) . '</p>';
			if ( count( $paragraphs ) >= 12 ) {
				break;
			}
		}

		return count( $paragraphs ) >= 2 ? implode( "\n", $paragraphs ) : '';
	}

	private function sideload_featured_image( $post_id, $image_url ) {
		if ( ! $image_url ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $image_url, $post_id, get_the_title( $post_id ), 'id' );
		if ( ! is_wp_error( $attachment_id ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
			return true;
		}

		update_post_meta( $post_id, '_niaf_image_error', $attachment_id->get_error_message() );
		return false;
	}

	private function maybe_generate_ai_featured_image( $post_id, $article, $category_name, $settings ) {
		if ( ! $this->is_ai_image_allowed( $article['title'] ?? '', $category_name, $settings ) ) {
			update_post_meta( $post_id, '_niaf_ai_image_skipped', 'sensitive_topic' );
			return false;
		}

		$prompt = $this->build_ai_image_prompt( $article, $category_name );
		$image = $this->generate_ai_image( $prompt, $settings );
		if ( is_wp_error( $image ) ) {
			update_post_meta( $post_id, '_niaf_ai_image_error', $image->get_error_message() );
			return false;
		}

		$attachment_id = $this->attach_generated_image( $post_id, $image['body'], $image['mime'], $prompt, $settings );
		if ( is_wp_error( $attachment_id ) ) {
			update_post_meta( $post_id, '_niaf_ai_image_error', $attachment_id->get_error_message() );
			return false;
		}

		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, '_niaf_ai_image_generated', 1 );
		update_post_meta( $post_id, '_niaf_ai_image_prompt', $prompt );
		update_post_meta( $post_id, '_niaf_ai_image_provider', $settings['ai_image_provider'] );
		delete_post_meta( $post_id, '_niaf_ai_image_error' );
		delete_post_meta( $post_id, '_niaf_ai_image_skipped' );
		return true;
	}

	private function is_ai_image_allowed( $title, $category_name, $settings ) {
		$blocked = array_filter( array_map( 'trim', explode( ',', $settings['ai_disabled_categories'] ?? '' ) ) );
		$haystack = strtolower( $title . ' ' . $category_name );

		foreach ( $blocked as $word ) {
			if ( '' !== $word && false !== strpos( $haystack, strtolower( $word ) ) ) {
				return false;
			}
		}

		return true;
	}

	private function build_ai_image_prompt( $article, $category_name ) {
		$title = sanitize_text_field( $article['title'] ?? '' );
		$category = sanitize_text_field( $category_name ?: ( $article['category'] ?? 'News' ) );

		return sprintf(
			'Editorial style conceptual image for a news article in the %1$s category about: "%2$s". Professional news website featured image, realistic but clearly illustrative, no real person likeness, no logos, no readable text, no fake documents, no graphic violence.',
			$category,
			$title
		);
	}

	private function generate_ai_image( $prompt, $settings ) {
		if ( 'huggingface' === $settings['ai_image_provider'] ) {
			return $this->generate_huggingface_image( $prompt, $settings );
		}

		return $this->generate_pollinations_image( $prompt, $settings );
	}

	private function generate_pollinations_image( $prompt, $settings ) {
		$url = add_query_arg(
			array(
				'width'   => absint( $settings['ai_image_width'] ),
				'height'  => absint( $settings['ai_image_height'] ),
				'nologo'  => 'true',
				'safe'    => 'true',
				'model'   => 'flux',
				'seed'    => wp_rand( 1, 999999 ),
			),
			'https://image.pollinations.ai/prompt/' . rawurlencode( $prompt )
		);

		$response = $this->remote_get( $url, 60 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( 200 !== (int) $code || strlen( $body ) < 1000 ) {
			return new WP_Error( 'niaf_pollinations_failed', 'Pollinations image generation failed.' );
		}

		return array(
			'body' => $body,
			'mime' => wp_remote_retrieve_header( $response, 'content-type' ) ?: 'image/jpeg',
		);
	}

	private function generate_huggingface_image( $prompt, $settings ) {
		if ( empty( $settings['ai_image_api_key'] ) ) {
			return new WP_Error( 'niaf_hf_missing_key', 'Hugging Face image generation requires an API token.' );
		}

		$response = wp_remote_post(
			esc_url_raw( $settings['ai_huggingface_model_url'] ),
			array(
				'timeout' => 90,
				'headers' => array(
					'Authorization' => 'Bearer ' . $settings['ai_image_api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'inputs'     => $prompt,
						'parameters' => array(
							'width'  => absint( $settings['ai_image_width'] ),
							'height' => absint( $settings['ai_image_height'] ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( 200 !== (int) $code || false === strpos( strtolower( $content_type ), 'image/' ) ) {
			return new WP_Error( 'niaf_hf_failed', 'Hugging Face image generation failed.' );
		}

		return array(
			'body' => $body,
			'mime' => $content_type,
		);
	}

	private function attach_generated_image( $post_id, $image_body, $mime, $prompt, $settings ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$extension = $this->mime_to_extension( $mime );
		$filename = sanitize_file_name( 'ai-news-' . $post_id . '-' . time() . '.' . $extension );
		$upload = wp_upload_bits( $filename, null, $image_body );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'niaf_upload_failed', $upload['error'] );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => get_the_title( $post_id ) . ' - AI image',
				'post_content'   => '',
				'post_excerpt'   => sanitize_text_field( $settings['ai_image_caption'] ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$post_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( 'Representative image for ' . get_the_title( $post_id ) ) );
		update_post_meta( $attachment_id, '_niaf_ai_image_prompt', $prompt );

		return $attachment_id;
	}

	private function mime_to_extension( $mime ) {
		$mime = strtolower( strtok( (string) $mime, ';' ) );
		if ( 'image/png' === $mime ) {
			return 'png';
		}
		if ( 'image/webp' === $mime ) {
			return 'webp';
		}

		return 'jpg';
	}

	private function find_post_by_source_url( $url ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_niaf_source_url',
				'meta_value'     => $url,
			)
		);

		return $posts ? (int) $posts[0] : 0;
	}

	private function has_similar_title_today( $title, $threshold ) {
		$tz = new DateTimeZone( 'Asia/Kolkata' );
		$today = new DateTimeImmutable( 'today', $tz );
		$tomorrow = $today->modify( '+1 day' );
		$incoming = $this->title_fingerprint( $title );

		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending' ),
				'posts_per_page' => 100,
				'date_query'     => array(
					array(
						'after'     => $today->format( 'Y-m-d H:i:s' ),
						'before'    => $tomorrow->format( 'Y-m-d H:i:s' ),
						'inclusive' => true,
					),
				),
			)
		);

		foreach ( $posts as $post ) {
			$existing = get_post_meta( $post->ID, '_niaf_title_fingerprint', true );
			if ( ! $existing ) {
				$existing = $this->title_fingerprint( $post->post_title );
			}

			similar_text( $incoming, $existing, $percent );
			if ( $percent >= $threshold ) {
				return true;
			}

			$max_len = max( strlen( $incoming ), strlen( $existing ) );
			if ( $max_len > 0 ) {
				$lev_percent = ( 1 - ( levenshtein( $incoming, $existing ) / $max_len ) ) * 100;
				if ( $lev_percent >= $threshold ) {
					return true;
				}
			}
		}

		return false;
	}

	private function title_fingerprint( $title ) {
		$title = strtolower( wp_strip_all_tags( remove_accents( $title ) ) );
		$title = preg_replace( '/[^a-z0-9\s]/', ' ', $title );
		$title = preg_replace( '/\b(the|a|an|and|or|of|to|in|on|for|with|from|by|at|as|is|are|was|were)\b/', ' ', $title );
		return trim( preg_replace( '/\s+/', ' ', $title ) );
	}

	private function resolve_category( $name ) {
		$name = sanitize_text_field( $name ?: 'General' );
		$existing = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
			)
		);

		foreach ( $existing as $term ) {
			if ( $this->is_similar_text( $name, $term->name, 86 ) ) {
				return (int) $term->term_id;
			}
		}

		$created = wp_insert_term( $name, 'category' );
		if ( is_wp_error( $created ) ) {
			$general = get_category_by_slug( 'general' );
			return $general ? (int) $general->term_id : 1;
		}

		return (int) $created['term_id'];
	}

	private function is_similar_text( $a, $b, $threshold ) {
		$a = $this->title_fingerprint( $a );
		$b = $this->title_fingerprint( $b );
		similar_text( $a, $b, $percent );
		return $percent >= $threshold;
	}

	private function parse_post_date( $published ) {
		$tz = new DateTimeZone( 'Asia/Kolkata' );
		try {
			$date = $published ? new DateTimeImmutable( $published ) : new DateTimeImmutable( 'now', $tz );
			return $date->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $exception ) {
			return current_time( 'mysql' );
		}
	}

	private function remote_get( $url, $timeout = 20 ) {
		$settings = $this->get_settings();
		return wp_remote_get(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 5,
				'user-agent'  => $settings['user_agent'],
			)
		);
	}

	private function seed_categories() {
		$categories = array(
			'Trending',
			'India',
			'World',
			'Politics',
			'Business',
			'Technology',
			'Sports',
			'Entertainment',
			'Health',
			'Science',
			'Education',
			'Crime',
			'General',
		);

		foreach ( $categories as $category ) {
			if ( ! term_exists( $category, 'category' ) ) {
				wp_insert_term( $category, 'category' );
			}
		}
	}

	private function ensure_defaults() {
		$default_settings = array(
			'max_per_run'                 => self::MAX_PER_RUN,
			'title_similarity'            => 88,
			'user_agent'                  => 'NewsIndiaBot/1.0 (+http://localhost/newsindia)',
			'ai_image_enabled'            => true,
			'ai_image_provider'           => 'pollinations',
			'ai_image_api_key'            => '',
			'ai_huggingface_model_url'    => 'https://api-inference.huggingface.co/models/stabilityai/stable-diffusion-xl-base-1.0',
			'ai_image_width'              => 1024,
			'ai_image_height'             => 576,
			'ai_disabled_categories'      => 'Crime, Politics, War, Disaster, Accident, Death, Violence, Terror',
			'ai_image_caption'            => 'AI-generated representative image.',
		);
		$current_settings = get_option( self::OPTION_SETTINGS );
		if ( ! is_array( $current_settings ) ) {
			update_option( self::OPTION_SETTINGS, $default_settings, false );
		} else {
			$merged_settings = wp_parse_args( $current_settings, $default_settings );
			if ( $merged_settings !== $current_settings ) {
				update_option( self::OPTION_SETTINGS, $merged_settings, false );
			}
		}

		if ( ! get_option( self::OPTION_SOURCES ) ) {
			update_option(
				self::OPTION_SOURCES,
				array(
					array(
						'enabled'  => true,
						'name'     => 'Google News RSS',
						'type'     => 'google_rss',
						'base_url' => 'https://news.google.com/rss/search',
						'api_key'  => '',
					),
					array(
						'enabled'  => false,
						'name'     => 'GNews',
						'type'     => 'gnews',
						'base_url' => 'https://gnews.io/api/v4/search',
						'api_key'  => '',
					),
					array(
						'enabled'  => false,
						'name'     => 'NewsAPI',
						'type'     => 'newsapi',
						'base_url' => 'https://newsapi.org/v2/everything',
						'api_key'  => '',
					),
					array(
						'enabled'  => false,
						'name'     => 'MediaStack',
						'type'     => 'mediastack',
						'base_url' => 'http://api.mediastack.com/v1/news',
						'api_key'  => '',
					),
				),
				false
			);
		}
	}

	private function get_settings() {
		$this->ensure_defaults();
		return wp_parse_args(
			get_option( self::OPTION_SETTINGS, array() ),
			array(
				'max_per_run'                 => self::MAX_PER_RUN,
				'title_similarity'            => 88,
				'user_agent'                  => 'NewsIndiaBot/1.0',
				'ai_image_enabled'            => true,
				'ai_image_provider'           => 'pollinations',
				'ai_image_api_key'            => '',
				'ai_huggingface_model_url'    => 'https://api-inference.huggingface.co/models/stabilityai/stable-diffusion-xl-base-1.0',
				'ai_image_width'              => 1024,
				'ai_image_height'             => 576,
				'ai_disabled_categories'      => 'Crime, Politics, War, Disaster, Accident, Death, Violence, Terror',
				'ai_image_caption'            => 'AI-generated representative image.',
			)
		);
	}

	private function get_sources() {
		$this->ensure_defaults();
		return (array) get_option( self::OPTION_SOURCES, array() );
	}

	private function get_enabled_sources() {
		return array_values(
			array_filter(
				$this->get_sources(),
				function ( $source ) {
					return ! empty( $source['enabled'] ) && ! empty( $source['base_url'] );
				}
			)
		);
	}
}

register_activation_hook( __FILE__, array( 'NewsIndia_Auto_Fetcher', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NewsIndia_Auto_Fetcher', 'deactivate' ) );

NewsIndia_Auto_Fetcher::instance();
