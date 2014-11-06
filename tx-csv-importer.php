<?php
/*
Plugin Name: Taxonomies CSV Importer
Plugin URI:
Description: Import taxonomy terms from csv file.
Author: Mantish
Author URI: https://8manos.com/
Text Domain: tx-csv-importer
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
Version: 0.0.1
*/

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

// Load Helpers
require dirname( __FILE__ ) . '/tx-csv-helper.php';

/**
 * CSV Importer
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class TX_CSV_Importer extends WP_Importer {

	/** Sheet columns
	* @value array
	*/
	public $column_indexes = array();
	public $column_keys = array();

 	// User interface wrapper start
	function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>'.__('Import CSV', 'tx-csv-importer').'</h2>';
	}

	// User interface wrapper end
	function footer() {
		echo '</div>';
	}

	// Step 1
	function greet() {
		echo '<p>'.__( 'Choose a CSV (.csv) file to upload, then click Upload file and import.', 'tx-csv-importer' ).'</p>';
		echo '<p>'.__( 'Excel-style CSV file is unconventional and not recommended. LibreOffice has enough export options and recommended for most users.', 'tx-csv-importer' ).'</p>';
		echo '<p>'.__( 'Requirements:', 'tx-csv-importer' ).'</p>';
		echo '<ol>';
		echo '<li>'.__( 'Select UTF-8 as charset.', 'tx-csv-importer' ).'</li>';
		echo '<li>'.sprintf( __( 'You must use field delimiter as "%s"', 'tx-csv-importer'), TX_CSV_Helper::DELIMITER ).'</li>';
		echo '<li>'.__( 'You must quote all text cells.', 'tx-csv-importer' ).'</li>';
		echo '</ol>';
		wp_import_upload_form( add_query_arg('step', 1) );
	}

	// Step 2
	function import() {
		$file = wp_import_handle_upload();

		if ( isset( $file['error'] ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'tx-csv-importer' ) . '</strong><br />';
			echo esc_html( $file['error'] ) . '</p>';
			return false;
		} else if ( ! file_exists( $file['file'] ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'tx-csv-importer' ) . '</strong><br />';
			printf( __( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'tx-csv-importer' ), esc_html( $file['file'] ) );
			echo '</p>';
			return false;
		}

		$this->id = (int) $file['id'];
		$this->file = get_attached_file($this->id);
		$result = $this->process_terms();
		if ( is_wp_error( $result ) )
			return $result;
	}

	/**
	* Insert post and postmeta using wp_post_helper.
	*
	* More information: https://gist.github.com/4084471
	*
	* @param array $post
	* @param array $meta
	* @param array $terms
	* @param string $thumbnail The uri or path of thumbnail image.
	* @param bool $is_update
	* @return int|false Saved post id. If failed, return false.
	*/
	public function save_term($term_name,$taxonomy,$args,$is_update,$term_id) {

		if ($is_update) {
			$result = wp_update_term( $term_id, $taxonomy, $args );
		} else {
			$result = wp_insert_term( $term_name, $taxonomy, $args );
		}

		return $result;
	}

	// process parse csv ind insert terms
	function process_terms() {
		$h = new TX_CSV_Helper;

		$handle = $h->fopen($this->file, 'r');
		if ( $handle == false ) {
			echo '<p><strong>'.__( 'Failed to open file.', 'tx-csv-importer' ).'</strong></p>';
			wp_import_cleanup($this->id);
			return false;
		}

		$is_first = true;

		echo '<ol>';

		while (($data = $h->fgetcsv($handle)) !== FALSE) {
			if ($is_first) {
				$h->parse_columns( $this, $data );
				$is_first = false;
			} else {
				echo '<li>';

				$args = array();
				$is_update = false;
				$error = new WP_Error();

				/* Toca hacerla seleccionable !!! */
				$taxonomy = 'product_cat';

				// term name
				$term_name = $h->get_data($this,$data,'name');
				if ( ! $term_name) {
					$error->add( 'term_name', sprintf(__('No term name defined', 'tx-csv-importer')) );
				}

				// parent
				$parent_id = $h->get_data($this,$data,'parent_id');
				if ($parent_id) {
					$args['parent'] = $parent_id;
				}
				else {
					$parent_name = $h->get_data($this,$data,'parent');
					if ($parent_name) {
						$term_parent = term_exists( $parent_name, $taxonomy );
						if ($term_parent !== 0 && $term_parent !== null) {
							$args['parent'] = $term_parent['term_id'];
						} else {
							$error->add( 'term_parent', sprintf(__('The term parent name does not match the existing data in your database. term_name: %s, parent(csv): %s', 'tx-csv-importer'), $term_name, $parent_name) );
						}
					}
				}

				//check if exists
				$parent_id = isset( $args['parent'] )? $args['parent'] : 0;
				$term_array = term_exists( $term_name, $taxonomy, $parent_id );
				if ($term_array !== 0 && $term_array !== null) {
					$term_id = $term_array['term_id'];
					$is_update = true;
				} else {
					$term_id = null;
				}

				// (string) term slug
				$term_slug = $h->get_data($this,$data,'slug');
				if ($term_slug) {
					$args['slug'] = $term_slug;
				}

				// (string) term description
				$term_description = $h->get_data($this,$data,'description');
				if ($term_description) {
					$args['description'] = $term_description;
				}

				if (!$error->get_error_codes()) {

					$result = $this->save_term($term_name,$taxonomy,$args,$is_update,$term_id);

					if ( is_array($result) ) {
						echo esc_html(sprintf(__('Processing "%s" done.', 'tx-csv-importer'), $term_name));
					} else {
						$error->add( 'save_term', __('An error occurred while saving the term to database: '.$result->get_error_message(), 'tx-csv-importer') );
					}
				}

				// show error messages
				foreach ($error->get_error_messages() as $message) {
					echo esc_html($message).'<br>';
				}

				echo '</li>';
			}
		}

		echo '</ol>';

		$h->fclose($handle);

		wp_import_cleanup($this->id);

		echo '<h3>'.__('All Done.', 'tx-csv-importer').'</h3>';
	}

	// dispatcher
	function dispatch() {
		$this->header();

		if (empty ($_GET['step']))
			$step = 0;
		else
			$step = (int) $_GET['step'];

		switch ($step) {
			case 0 :
				$this->greet();
				break;
			case 1 :
				check_admin_referer('import-upload');
				set_time_limit(0);
				$result = $this->import();
				if ( is_wp_error( $result ) )
					echo $result->get_error_message();
				break;
		}

		$this->footer();
	}

}

// setup importer
$tx_csv_importer = new TX_CSV_Importer();

register_importer('csv', __('CSV', 'tx-csv-importer'), __('Import taxonomy terms from csv file.', 'tx-csv-importer'), array ($tx_csv_importer, 'dispatch'));

} // class_exists( 'WP_Importer' )
