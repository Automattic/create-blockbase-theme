<?php

require_once( __DIR__ . '/theme-media.php' );
require_once( __DIR__ . '/theme-patterns.php' );

class Theme_Templates {

	/**
	 * Build a collection of templates and template-parts that should be exported (and modified)
	 * based on the given export_type.
	 *
	 * @param string $export_type The type of export to perform. 'all', 'current', or 'user'.
	 * @return object An object containing the templates and parts that should be exported.
	 */
	public static function get_theme_templates( $export_type ) {

		$templates          = get_block_templates();
		$template_parts     = get_block_templates( array(), 'wp_template_part' );
		$exported_templates = array();
		$exported_parts     = array();

		// build collection of templates/parts in currently activated theme
		$templates_paths = get_block_theme_folders();
		$templates_path  = get_stylesheet_directory() . '/' . $templates_paths['wp_template'] . '/';
		$parts_path      = get_stylesheet_directory() . '/' . $templates_paths['wp_template_part'] . '/';

		foreach ( $templates as $template ) {
			if ( self::should_include_template(
				$template,
				$export_type,
				$templates_path
			) ) {
				$exported_templates[] = self::cleanup_template( $template );
			}
		}

		foreach ( $template_parts as $template ) {
			if ( self::should_include_template(
				$template,
				$export_type,
				$parts_path
			) ) {
				$exported_parts[] = self::cleanup_template( $template );
			}
		}

		return (object) array(
			'templates' => $exported_templates,
			'parts'     => $exported_parts,
		);

	}

	/**
	 * Filter a template out (return false) based on the export_type expected and the templates origin.
	 *
	 * @param object $template The template to filter.
	 * @param string $export_type The type of export to perform. 'all', 'current', or 'user'.
	 * @param string $path The path to the templates folder.
	 * @return object|bool The template if it should be included, or false if it should be excluded.
	 */
	static function should_include_template( $template, $export_type, $path ) {
		if ( 'theme' === $template->source && 'user' === $export_type ) {
			return false;
		}
		if (
			'theme' === $template->source &&
			'current' === $export_type &&
			! file_exists( $path . $template->slug . '.html' )
		) {
			return false;
		}
		return true;
	}

	/**
	 * Clean up the template content before exporting.
	 * @param object $template The template to clean up.
	 * @return object The cleaned up template.
	 */
	private static function cleanup_template( $template ) {
		// NOTE: Dashes are encoded as \u002d in the content that we get (noteably in things like css variables used in templates)
		// This replaces that with dashes again. We should consider decoding the entire string but that is proving difficult.
		$template->content = str_replace( '\u002d', '-', $template->content );

		return $template;
	}

	/**
	 * Replace the old theme slug with the new theme slug in the template content.
	 *
	 * @param object $template The template to replace the namespace in.
	 * @param string $new_slug The new theme slug.
	 * @return object The template with the namespace replaced.
	 */
	public static function replace_template_namespace( $template, $new_slug ) {
		$old_slug = wp_get_theme()->get( 'TextDomain' );
		if ( $new_slug ) {
			$template->content = str_replace( $old_slug, $new_slug, $template->content );
		}
		return $template;
	}

	/**
	 * Clear all user templates customizations.
	 * This will remove all user templates from the database.
	 */
	public static function clear_user_templates_customizations() {
		//remove all user templates (they have been saved in the theme)
		$templates      = get_block_templates();
		$template_parts = get_block_templates( array(), 'wp_template_part' );
		foreach ( $template_parts as $template ) {
			if ( 'custom' !== $template->source ) {
				continue;
			}
			wp_delete_post( $template->wp_id, true );
		}

		foreach ( $templates as $template ) {
			if ( 'custom' !== $template->source ) {
				continue;
			}
			wp_delete_post( $template->wp_id, true );
		}
	}

	/**
	 * Extract content from a template that need to be patternized.
	 * Return the modified template and the pattern that was created
	 *
	 * @param object $template The template to extract content from.
	 * @return object The template with the patternized content.
	 */
	public static function paternize_template( $template ) {
		// If there is any PHP in the template then paternize
		if ( str_contains( $template->content, '<?php' ) ) {
			$pattern                 = Theme_Patterns::pattern_from_template( $template );
			$pattern_link_attributes = array(
				'slug' => $pattern['slug'],
			);
			$template->content       = Theme_Patterns::create_pattern_link( $pattern_link_attributes );
			$template->pattern       = $pattern['content'];
		}
		return $template;
	}

	public static function prepare_template_for_export( $template ) {

		$template = Theme_Media::make_template_images_local( $template );
		$template = self::escape_text_in_template( $template );
		$template = self::eliminate_environment_specific_content( $template );
		$template = self::paternize_template( $template );

		return $template;
	}

	/**
	 * Copy the templates and template-parts (including user customizations)
	 * as well as any media to the theme filesystem.
	 * If patterns need to be created for media or localizations they will also be added.
	 *
	 * @param string $export_type The type of export to perform. 'all', 'current', or 'user'.
	 */
	public static function add_templates_to_local( $export_type ) {

		$theme_templates  = self::get_theme_templates( $export_type );
		$template_folders = get_block_theme_folders();

		// If there is no templates folder, create it.
		if ( ! is_dir( get_stylesheet_directory() . DIRECTORY_SEPARATOR . $template_folders['wp_template'] ) ) {
			wp_mkdir_p( get_stylesheet_directory() . DIRECTORY_SEPARATOR . $template_folders['wp_template'] );
		}

		// If there is no parts folder, create it.
		if ( ! is_dir( get_stylesheet_directory() . DIRECTORY_SEPARATOR . $template_folders['wp_template_part'] ) ) {
			wp_mkdir_p( get_stylesheet_directory() . DIRECTORY_SEPARATOR . $template_folders['wp_template_part'] );
		}

		// If there is no patterns folder, create it.
		if ( ! is_dir( get_stylesheet_directory() . DIRECTORY_SEPARATOR . 'patterns' ) ) {
			wp_mkdir_p( get_stylesheet_directory() . DIRECTORY_SEPARATOR . 'patterns' );
		}

		foreach ( $theme_templates->templates as $template ) {

			$template = self::prepare_template_for_export( $template );

			// Write the template content
			file_put_contents(
				get_stylesheet_directory() . DIRECTORY_SEPARATOR . $template_folders['wp_template'] . DIRECTORY_SEPARATOR . $template->slug . '.html',
				$template->content
			);

			// Write the media assets if there are any
			if ( $template->media ) {
				Theme_Media::add_media_to_local( $template->media );
			}

			// Write the pattern if it exists
			if ( isset( $template->pattern ) ) {
				file_put_contents(
					get_stylesheet_directory() . DIRECTORY_SEPARATOR . 'patterns' . DIRECTORY_SEPARATOR . $template->slug . '.php',
					$template->pattern
				);
			}
		}

		foreach ( $theme_templates->parts as $template ) {

			$template = self::prepare_template_for_export( $template );

			// Write the template content
			file_put_contents(
				get_stylesheet_directory() . DIRECTORY_SEPARATOR . $template_folders['wp_template_part'] . DIRECTORY_SEPARATOR . $template->slug . '.html',
				$template->content
			);

			// Write the media assets if there are any
			if ( $template->media ) {
				Theme_Media::add_media_to_local( $template->media );
			}

			// Write the pattern if it exists
			if ( isset( $template->pattern ) ) {
				file_put_contents(
					get_stylesheet_directory() . DIRECTORY_SEPARATOR . 'patterns' . DIRECTORY_SEPARATOR . $template->slug . '.php',
					$template->pattern
				);
			}
		}
	}

	public static function escape_text_in_template( $template ) {
		$template_blocks = parse_blocks( $template->content );
		foreach ( $template_blocks as &$block ) {
			$block = self::escape_text_in_block( $block );
		}
		$template->content = serialize_blocks( $template_blocks );
		return $template;
	}

	public static function escape_text_in_block( $block ) {

		// TODO: More blocks besides just the paragraph block
		// Can we make it generic?
		if ( 'core/paragraph' === $block['blockName'] ) {
			$block = self::escape_paragraph( $block );
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as &$inner_block ) {
				$inner_block = self::escape_text_in_block( $inner_block );
			}
		}
		return $block;
	}

	/**
	 * Escape the text in a paragraph block.
	 */
	public static function escape_paragraph( $block ) {
		//TODO: This seems messy.  How can we make this more elegant?
		$content = $block['innerContent'][0];
		$doc     = new DOMDocument();
		$doc->loadHTML( $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		$elements = $doc->getElementsByTagName( '*' );
		foreach ( $elements as $element ) {
			// phpcs:ignore
			$element->nodeValue = self::escape_text( $element->nodeValue );
		}
		$block['innerContent'][0] = html_entity_decode( $doc->saveHTML() );
		return $block;
	}

	public static function escape_text( $text ) {
		if ( ! $text ) {
			return $text;
		}
		return "<?php echo __('" . $text . "', '" . wp_get_theme()->get( 'TextDomain' ) . "');?>";
	}

	public static function eliminate_environment_specific_content( $template ) {

		$template_blocks = parse_blocks( $template->content );
		$blocks          = _flatten_blocks( $template_blocks );

		foreach ( $blocks as $key => $block ) {

			// remove theme attribute from template parts
			if ( 'core/template-part' === $block['blockName'] && isset( $block['attrs']['theme'] ) ) {
				unset( $blocks[ $key ]['attrs']['theme'] );
			}

			// remove ref attribute from blocks
			// TODO: are there any other blocks that have refs?
			if ( 'core/navigation' === $block['blockName'] && isset( $block['attrs']['ref'] ) ) {
				unset( $blocks[ $key ]['attrs']['ref'] );
			}

			if ( in_array( $block['blockName'], array( 'core/image', 'core/cover' ), true ) ) {
				// remove id attribute from image and cover blocks
				// TODO: are there any other blocks that have ids?
				if ( isset( $block['attrs']['id'] ) ) {
					unset( $blocks[ $key ]['attrs']['id'] );
				}

				// remove wp-image-[id] class from image and cover blocks
				if ( isset( $block['attrs']['className'] ) ) {
					$blocks[ $key ]['attrs']['className'] = preg_replace( '/wp-image-\d+/', '', $block['attrs']['className'] );
				}
			}

			// set taxQuery to null for query blocks
			if ( 'core/query' === $block['blockName'] ) {
				if ( isset( $block['attrs']['taxQuery'] ) ) {
					unset( $blocks[ $key ]['attrs']['taxQuery'] );
				}
				if ( isset( $block['attrs']['queryId'] ) ) {
					unset( $blocks[ $key ]['attrs']['queryId'] );
				}
			}
		}

		$new_content = '';
		foreach ( $template_blocks as $block ) {
			$new_content .= serialize_block( $block );
		}
		$template->content = $new_content;
		return $template;
	}
}
