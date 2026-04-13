<?php
/**
 * Plugin Name: KO Admin Permalink Column + CSV Export (Admins Only)
 * Description: Adds a Permalink column to Posts/Pages/public CPT list tables and provides an Admin-only CSV export (Title, Author, Categories, Tags, Permalink, Date).
 * Version: 1.2.0
 * Author: KO
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class KO_Admin_Permalink_Column_CSV_Admins_Only {

	const ACTION_EXPORT = 'ko_export_posts_csv';
	const NONCE_ACTION  = 'ko_admin_csv_export';

	public function __construct() {
		add_action('admin_init', [$this, 'register_list_table_hooks']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

		// Button on list screens
		add_action('restrict_manage_posts', [$this, 'add_export_button'], 20, 1);

		// Export endpoint (admins only)
		add_action('admin_post_' . self::ACTION_EXPORT, [$this, 'handle_export']);
	}

	/**
	 * Add Permalink column across public post types.
	 */
	public function register_list_table_hooks() : void {
		$post_types = get_post_types(['public' => true], 'names');

		foreach ($post_types as $pt) {
			add_filter("manage_{$pt}_posts_columns", [$this, 'add_permalink_column'], 20);
			add_action("manage_{$pt}_posts_custom_column", [$this, 'render_permalink_column'], 10, 2);
		}
	}

	public function add_permalink_column(array $columns) : array {
		// Insert before "Date" if present, otherwise append.
		$new = [];

		foreach ($columns as $key => $label) {
			if ($key === 'date') {
				$new['ko_permalink'] = __('Permalink', 'ko-admin-permalink-column-csv');
			}
			$new[$key] = $label;
		}

		if (!isset($new['ko_permalink'])) {
			$new['ko_permalink'] = __('Permalink', 'ko-admin-permalink-column-csv');
		}

		return $new;
	}

	public function render_permalink_column(string $column, int $post_id) : void {
		if ($column !== 'ko_permalink') return;

		$link = get_permalink($post_id);
		if (!$link || is_wp_error($link)) {
			echo '<span style="opacity:.7;">—</span>';
			return;
		}

		echo '<div class="ko-permalink-cell" data-ko-permalink="' . esc_attr($link) . '">';
		echo '  <a class="ko-permalink-url" href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer">' . esc_html($link) . '</a>';
		echo '  <button type="button" class="button button-small ko-permalink-copy" aria-label="Copy permalink">Copy</button>';
		echo '</div>';
	}

	/**
	 * Add the "Download CSV" button on edit.php list table screens (Admins only).
	 */
	public function add_export_button(string $post_type) : void {
		// Only show on list-table screens where WP uses this hook
		if (!is_admin()) return;

		// Admins only
		if (!current_user_can('manage_options')) return;

		// Only for public post types
		$allowed = get_post_types(['public' => true], 'names');
		if (!in_array($post_type, $allowed, true)) return;

		// Preserve current list-table filters/search querystring
		$current_query = $_GET;
		unset($current_query['action'], $current_query['_wpnonce']); // avoid collisions

		$query_string = http_build_query($current_query);

		$url = admin_url('admin-post.php?action=' . self::ACTION_EXPORT . '&post_type=' . urlencode($post_type));
		$url = wp_nonce_url($url, self::NONCE_ACTION, '_wpnonce');

		if (!empty($query_string)) {
			$url .= '&' . $query_string;
		}

		echo '<a class="button" style="margin-left:8px;" href="' . esc_url($url) . '">Download CSV</a>';
	}

	/**
	 * Export handler (Admins only). Uses wp_die() hard stop.
	 */
	public function handle_export() : void {

		// Hard stop: Admins only
		if (!current_user_can('manage_options')) {
			wp_die(
				'Admins only.',
				'Access denied',
				['response' => 403]
			);
		}

		// CSRF protection
		check_admin_referer(self::NONCE_ACTION, '_wpnonce');

		$post_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : 'post';

		// Only public post types
		$allowed = get_post_types(['public' => true], 'names');
		if (!in_array($post_type, $allowed, true)) {
			wp_die('Invalid post type.', 'Bad request', ['response' => 400]);
		}

		// Build query, respecting common list-table filters
		$args = [
			'post_type'      => $post_type,
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			// Match list table: allow these statuses if user can see them in admin.
			'post_status'    => ['publish', 'future', 'draft', 'pending', 'private'],
		];

		// Search
		if (!empty($_GET['s'])) {
			$args['s'] = sanitize_text_field(wp_unslash($_GET['s']));
		}

		// Author filter
		if (!empty($_GET['author'])) {
			$args['author'] = absint($_GET['author']);
		}

		// Post status filter
		if (!empty($_GET['post_status'])) {
			$args['post_status'] = [sanitize_key($_GET['post_status'])];
		}

		// Month dropdown filter (YYYYMM)
		if (!empty($_GET['m'])) {
			$args['m'] = preg_replace('/[^0-9]/', '', (string) $_GET['m']);
		}

		// Category filter (only if taxonomy applies)
		if (!empty($_GET['cat'])) {
			$args['cat'] = absint($_GET['cat']);
		}

		// Tag filter (classic param)
		if (!empty($_GET['tag'])) {
			$args['tag'] = sanitize_text_field(wp_unslash($_GET['tag']));
		}

		$q = new WP_Query($args);

		$filename = sprintf('%s-export-%s.csv', $post_type, gmdate('Y-m-d_H-i-s'));

		// Output CSV headers
		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=' . $filename);
		header('Pragma: no-cache');
		header('Expires: 0');

		$out = fopen('php://output', 'w');

		// UTF-8 BOM to help Excel
		fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

		// CSV header row
		fputcsv($out, ['Title', 'Author', 'Categories', 'Tags', 'Permalink', 'Date']);

		foreach ($q->posts as $post) {
			$post_id = (int) $post->ID;

			$title     = get_the_title($post_id);
			$author_id = (int) $post->post_author;
			$author    = $author_id ? get_the_author_meta('display_name', $author_id) : '';
			$permalink = get_permalink($post_id);

			$cats = $this->get_terms_list($post_id, 'category');
			$tags = $this->get_terms_list($post_id, 'post_tag');

			// WP site timezone format (matches admin expectations)
			$date = get_the_date('Y-m-d H:i', $post_id);

			fputcsv($out, [
				$title,
				$author,
				$cats,
				$tags,
				$permalink,
				$date,
			]);
		}

		fclose($out);
		exit;
	}

	private function get_terms_list(int $post_id, string $taxonomy) : string {
		if (!taxonomy_exists($taxonomy)) return '';
		if (!is_object_in_taxonomy(get_post_type($post_id), $taxonomy)) return '';

		$terms = get_the_terms($post_id, $taxonomy);
		if (empty($terms) || is_wp_error($terms)) return '';

		$names = array_map(static function($t) { return $t->name; }, $terms);
		return implode(', ', $names);
	}

	/**
	 * Load minimal CSS + JS for permalink copy.
	 */
	public function enqueue_assets(string $hook) : void {
		if ($hook !== 'edit.php') return;

		wp_add_inline_style('wp-admin', $this->css());
		wp_add_inline_script('jquery-core', $this->js(), 'after');
	}

	private function css() : string {
		return "
			.column-ko_permalink { width: 28%; }
			.ko-permalink-cell { display:flex; gap:8px; align-items:center; min-width: 220px; }
			.ko-permalink-url {
				font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
				font-size: 12px;
				white-space: nowrap;
				overflow: hidden;
				text-overflow: ellipsis;
				max-width: 520px;
				display:inline-block;
				vertical-align: middle;
			}
			.ko-permalink-copy { flex: 0 0 auto; }
		";
	}

	private function js() : string {
		return "
			(function(){
				function copyText(text){
					if (navigator.clipboard && window.isSecureContext) {
						return navigator.clipboard.writeText(text);
					}
					return new Promise(function(resolve, reject){
						try {
							var ta = document.createElement('textarea');
							ta.value = text;
							ta.style.position = 'fixed';
							ta.style.left = '-9999px';
							document.body.appendChild(ta);
							ta.focus();
							ta.select();
							var ok = document.execCommand('copy');
							document.body.removeChild(ta);
							ok ? resolve() : reject();
						} catch(e){ reject(e); }
					});
				}

				document.addEventListener('click', function(e){
					var btn = e.target.closest('.ko-permalink-copy');
					if(!btn) return;

					var wrap = btn.closest('.ko-permalink-cell');
					if(!wrap) return;

					var url = wrap.getAttribute('data-ko-permalink') || '';
					if(!url) return;

					var original = btn.textContent;
					btn.disabled = true;

					copyText(url).then(function(){
						btn.textContent = 'Copied';
						setTimeout(function(){
							btn.textContent = original;
							btn.disabled = false;
						}, 900);
					}).catch(function(){
						btn.textContent = 'Failed';
						setTimeout(function(){
							btn.textContent = original;
							btn.disabled = false;
						}, 900);
					});
				});
			})(); 
		";
	}
}

new KO_Admin_Permalink_Column_CSV_Admins_Only();