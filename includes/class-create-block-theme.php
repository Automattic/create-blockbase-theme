<?php

/**
 * The core Create Block Theme plugin class.
 *
 * @since      0.0.2
 * @package    Create_Block_Theme
 * @subpackage Create_Block_Theme/includes
 * @author     WordPress.org
 */
#[AllowDynamicProperties]
class CBT_Plugin
{

	/**
	 * Define the core functionality of the plugin.
	 *
	 * @since    0.0.2
	 */
	public function __construct()
	{

		$this->load_dependencies();
		$this->define_admin_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @since    0.0.2
	 * @access   private
	 */
	private function load_dependencies()
	{

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-create-block-theme-loader.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-create-block-theme-api.php';
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-create-block-theme-editor-tools.php';
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-create-block-theme-admin-landing.php';

		$this->loader = new CBT_Plugin_Loader();
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    0.0.2
	 * @access   private
	 */
	private function define_admin_hooks()
	{
		$plugin_api    = new CBT_Theme_API();
		$editor_tools  = new CBT_Editor_Tools();
		$admin_landing = new CBT_Admin_Landing();
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    0.0.2
	 */
	public function run()
	{
		$this->loader->run();
	}
}

function CBT_render_pattern($pattern_file) {
	ob_start();
	include $pattern_file;
	return ob_get_clean();
}


function CBT_get_theme_block_patterns()
{

	$registry = WP_Block_Patterns_Registry::get_instance();

	$default_headers = array(
		'title'         => 'Title',
		'slug'          => 'Slug',
		'description'   => 'Description',
		'viewportWidth' => 'Viewport Width',
		'inserter'      => 'Inserter',
		'categories'    => 'Categories',
		'keywords'      => 'Keywords',
		'blockTypes'    => 'Block Types',
		'postTypes'     => 'Post Types',
		'templateTypes' => 'Template Types',
		'synced'	=> 'Synced',
	);

	$all_patterns = array();
	$themes   = array();
	$theme    = wp_get_theme();
	$themes[] = $theme;

	if ($theme->parent()) {
		$themes[] = $theme->parent();
	}

	foreach ($themes as $theme) {

		$pattern_files = glob($theme->get_stylesheet_directory() . '/patterns/*.php');

		foreach ($pattern_files as $pattern_file) {

			$pattern_data = get_file_data( $pattern_file, $default_headers );

			if ($registry->is_registered($pattern_data['slug'])) {
				continue;
			}

			$pattern_data['content'] = CBT_render_pattern($pattern_file);

			$all_patterns[] = array(
				'id' => '8888' . crc32($pattern_data['slug']),
				'file_path' => $pattern_file,
				'slug' => $pattern_data['slug'] ?? null,
				'status' => 'publish',
				'type' => 'wp_block',
				'title' => array(
					'raw' => $pattern_data['title'] ?? null,
				),
				'content' => array(
					'raw' => $pattern_data['content'] ?? null,
					'protected' => false,
					'block_version' => null,
				),
				'excerpt' => array(
					'raw' => $pattern_data['description'] ?? null,
					'rendered' => null,
					'protected' => false,
				),
				'wp_pattern_category' => array(),
				'wp_pattern_sync_status' => $pattern_data['synced'] === 'yes' ? "" : "unsynced",
			);;
		}
	}

	return $all_patterns;
}

// Add in the block patterns from the theme to the collection of blocks
function CBT_filter_blocks_api_response($response, $server, $request)
{
	if ($request->get_route() !== '/wp/v2/blocks') {
		return $response;
	}

	$data = $response->get_data();
	$patterns = CBT_get_theme_block_patterns();

	$response->set_data(array_merge($data, $patterns));

	return $response;
}

// Handle CBT block updates
function CBT_filter_block_update($result, $server, $request)
{
	$route = $request->get_route();

	if ( strpos( $route, '/wp/v2/blocks/' ) !== 0 ) {
		return $result;
	}


	if ( ! str_contains($route, '8888')) {
		return $result;
	}


	$encoded_pattern_slug = (int)ltrim(strstr($route, '8888'), '8888');
	$theme_patterns = CBT_get_theme_block_patterns();



	// if a pattern with a matching slug exists in the theme, do work on it
	foreach ($theme_patterns as $pattern) {
		if (crc32($pattern['slug']) === $encoded_pattern_slug) {

			$pattern_slug = $pattern['slug'];

			// if the request is a GET, return the pattern content
			if ($request->get_method() === 'GET') {
				return rest_ensure_response($pattern);
			}
			// if the request is a PUT or POST, create/update the pattern content file
			if ($request->get_method() === 'PUT' || $request->get_method() === 'POST') {
				// die('trying' . var_dump($pattern));
				$block_content = $request->get_param('content');
				$categories = implode(', ',$pattern['wp_pattern_category']);
				$synced_status = $pattern['wp_pattern_sync_status'] === '' ? 'yes' : 'no';
				$file_content = <<<PHP
				<?php
				/**
				* Title: {$pattern['title']['raw']}
				* Slug: {$pattern['slug']}
				* Categories: {$categories}
				* Synced: {$synced_status}
				*/
				?>
				{$block_content}
				PHP;

				file_put_contents($pattern['file_path'], $file_content);

				$pattern['content']['raw'] = $block_content;
				return rest_ensure_response($pattern);
			}
			// if the request is a DELETE, delete the pattern content file
			if ($request->get_method() === 'DELETE') {
				unlink($pattern['file_path']);
				return rest_ensure_response($pattern);
			}

		}

	}

	return $result;
}


// don't register the theme block patterns
remove_action('init', '_register_theme_block_patterns');
// add the theme block patterns to the block collection
add_filter('rest_post_dispatch', 'CBT_filter_blocks_api_response', 10, 3);
add_filter( 'rest_pre_dispatch', 'CBT_filter_block_update', 10, 3 );
