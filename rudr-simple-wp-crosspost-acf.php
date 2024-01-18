<?php
/**
 * Plugin Name: Simple WP Crossposting – ACF
 * Plugin URL: https://rudrastyh.com/support/acf
 * Description: Provides better compatibility with ACF and ACF PRO.
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Version: 1.0
 */
class Rudr_SWC_ACF {


	function __construct() {

		add_filter( 'rudr_swc_pre_crosspost_post_data', array( $this, 'process_fields' ), 25, 2 );
		add_filter( 'rudr_swc_pre_crosspost_post_data', array( $this, 'process_acf_blocks' ), 30, 2 );

		register_activation_hook( __FILE__, array( $this, 'activate' ) );

	}

	public function activate() {

		// deactivate outdated add-ons
		deactivate_plugins(
			array(
				'rudr-simple-wp-crosspost-attachments/rudr-simple-wp-crosspost-attachments.php', // deactivate image processing via add-on
				'rudr-simple-wp-crosspost-relationship/rudr-simple-wp-crosspost-relationship.php', // deactivate relationships fields via add-on
				'rudr-simple-wp-crosspost-acf-blocks/rudr-simple-wp-crosspost-acf-blocks.php', // deactivate acf blocks add-on
			),
			true
		);

	}

	public function process_fields( $data, $blog ) {

		// if no meta fields do nothing
		if( ! isset( $data[ 'meta' ] ) || ! is_array( $data[ 'meta' ] ) ) {
			return $data;
		}
		// if no ACF
		if( ! function_exists( 'get_field_object' ) ) {
			return $data;
		}
		// just in case
		if( empty( $data[ 'id' ] ) ) {
			return $data;
		}
		$object_id = (int) $data[ 'id' ];

		foreach( $data[ 'meta' ] as $meta_key => $meta_value ) { // ACF doesn't use an array of meta values per key

			$field = get_field_object( $meta_key, $object_id, false );
			// if it is not really an ACF field (returns false)
			if( ! $field ) {
				continue;
			}

			$meta_value = $this->process_field_by_type( $meta_value, $field, $object_id, $blog );

			// re-organize the fields
			$data[ 'acf' ][ $meta_key ] = $meta_value;
			unset( $data[ 'meta' ][ $meta_key ] );
			unset( $data[ 'meta' ][ "_{$meta_key}" ] );
			// not necessary to unset repeater subfields like repeater_0_text

		}
//echo '<pre>';print_r( $data);exit;

		return $data;

	}


	public function process_field_by_type( $meta_value, $field, $object_id, $blog ) {

		switch( $field[ 'type' ] ) {
			case 'image':
			case 'gallery':
			case 'file': {
				$meta_value = $this->process_attachment_field( $meta_value, $field, $blog );
				break;
			}
			case 'relationship':
			case 'post_object': {
				$meta_value = $this->process_relationships_field( $meta_value, $field, $blog );
				break;
			}
			case 'repeater' : {
				$meta_value = $this->process_repeater_field( $meta_value, $field, $object_id, $blog );
				break;
			}
			case 'flexible_content' : {
				$meta_value = $this->process_flexible_field( $meta_value, $field, $object_id, $blog );
				break;
			}

		}

		return $meta_value;

	}


	/**
	 * Replaces attachment IDs with the appropriate IDs on another site
	 * $meta_value - an attachment ID or an array of IDs
	 */
	private function process_attachment_field( $meta_value, $field, $blog ) {
		// sometimes we need if
		$meta_value = maybe_unserialize( $meta_value );

		if( is_array( $meta_value ) ) {
			// gallery field
			$meta_value = array_filter( array_map( function( $attachment_id ) use ( $blog ) {
				$crossposted = Rudr_Simple_WP_Crosspost::maybe_crosspost_image( $attachment_id, $blog );
				if( isset( $crossposted[ 'id' ] ) && $crossposted[ 'id' ] ) {
					return $crossposted[ 'id' ];
				}
				return 0;
			}, $meta_value ) );
		} else {
			// image or file field
			$crossposted = Rudr_Simple_WP_Crosspost::maybe_crosspost_image( $meta_value, $blog );
			if( isset( $crossposted[ 'id' ] ) && $crossposted[ 'id' ] ) {
				$meta_value = $crossposted[ 'id' ];
		 	} else {
				$meta_value = 0;
			}
		}
		return $meta_value;
	}


	/**
	 * Replaces post IDs in relationships, post_object fields
	 * $meta_value - an post ID or an array of IDs
	 */
	private function process_relationships_field( $meta_value, $field, $blog ) {

		$blog_id = Rudr_Simple_WP_Crosspost::get_blog_id( $blog );

		$meta_value = maybe_unserialize( $meta_value );
		if( is_array( $meta_value ) ) {
			$meta_value = array_filter( array_map( function( $post_id ) use ( $blog_id ) {
				if( $crossposted_id = Rudr_Simple_WP_Crosspost::is_crossposted( $post_id, $blog_id ) ) {
					return $crossposted_id;
				}
				return 0;
			}, $meta_value ) );
		} else {
			if( $crossposted_id = Rudr_Simple_WP_Crosspost::is_crossposted( $meta_value, $blog_id ) ) {
				$meta_value = $crossposted_id;
			} else {
				$meta_value = 0;
			}
		}
		return $meta_value;
	}


	/**
	 * Formats repeaters for REST API
	 */
	private function process_repeater_field( $meta_value, $field, $object_id, $blog ){

		$meta_value = $field[ 'value' ];

		foreach( $meta_value as &$repeater ) {

			foreach( $repeater as $subfield_key => $subfield_value ) {
				$subfield = get_field_object( $subfield_key, $object_id, false );
				unset( $repeater[ $subfield_key ] );
				$repeater[ $subfield[ 'name' ] ] = $this->process_field_by_type( $subfield_value, $subfield, $object_id, $blog );
			}

		}
		return $meta_value;

	}


	/**
	 * Formats Flexible content for REST API
	 */
	private function process_flexible_field( $meta_value, $field, $object_id, $blog ) {

		$meta_value = $field[ 'value' ];

		foreach( $meta_value as &$layout ) {

			foreach( $layout as $subfield_key => $subfield_value ) {
				if( 'acf_fc_layout' === $subfield_key ) {
					continue;
				}
				$subfield = get_field_object( $subfield_key, $object_id, false );
				unset( $layout[ $subfield_key ] );
				$layout[ $subfield[ 'name' ] ] = $this->process_field_by_type( $subfield_value, $subfield, $object_id, $blog );
			}

		}
		return $meta_value;

	}


	/**
	 * ACF Blocks
	 */
	public function process_acf_blocks( $data, $blog ) {

		// no blocks, especially no acf ones
		if( ! has_blocks( $data[ 'content' ] ) ) {
			return $data;
		}

		$blocks = parse_blocks( $data[ 'content' ] );
		//file_put_contents( __DIR__ . '/log.txt' , print_r( $blocks, true ) );

		// let's do the shit
		foreach( $blocks as &$block ) {
			$block = $this->process_acf_block( $block, $blog );
		}

		//file_put_contents( __DIR__ . '/log.txt' , print_r( $blocks, true ) );

		$processed_content = '';
		foreach( $blocks as $processed_block ) {
			if( $processed_rendered_block = $this->render_acf_block( $processed_block ) ) {
				$processed_content .= "{$processed_rendered_block}\n\n";
			}
		}

		//file_put_contents( __DIR__ . '/log.txt' , $processed_content );
		$data[ 'content' ] = $processed_content;
		return $data;
	}

	public function process_acf_block( $block, $blog ) {

		// first – process inner blocks
		if( $block[ 'innerBlocks' ] ) {
			foreach( $block[ 'innerBlocks' ] as &$innerBlock ) {
				$innerBlock = $this->process_acf_block( $innerBlock, $blog );
			}
		}

		// second – once the block itself non acf, we do nothing
		if( 0 !== strpos( $block[ 'blockName' ], 'acf/' ) ) {
			return $block;
		}

		// skip the block if it has empty data
		if( empty( $block[ 'attrs' ][ 'data' ] ) || ! $block[ 'attrs' ][ 'data' ] ) {
			return $block;
		}

		// now we are going to work with fields!
		$fields = array();
		foreach( $block[ 'attrs' ][ 'data' ] as $key => $value ) {
			// modify
			if( 0 !== strpos( $key, '_' ) ) {
				$field_key = $block[ 'attrs' ][ 'data' ][ '_'.$key ];

				$value = apply_filters( 'rudr_swc_pre_crosspost_acf_block_value', $value, $field_key, $blog );

				$fields[ $key ] = str_replace(
					array( "\r" . PHP_EOL, PHP_EOL ),
					'\r\n',
					$value
					//addslashes( wp_kses( stripslashes( $value ), 'post' ) )
				);

				//$fields[ $key ] = trim( json_encode( $fields[ $key ] ), '"' );
				$fields[ $key ] = str_replace(
					array(
						'<',
						'>',
						'"',
						"\t",
						'’',
						'é',
						' ', //nbsp
						'×',
						'ë',
						'€',
						'‘',
						'ñ',
						'í',
						'á',
						'ó',
						'ú',
						'Í',
						'”',
						'°',
						'–',
						'®',
						'™',
						'″',
					),
					array(
						'\u003c',
						'\u003e',
						'\u0022',
						'',
						'\u2019',
						'\u00e9',
						'\u00a0',
						'\u00d7',
						'\u00eb',
						'\u20ac',
						'\u2018',
						'\u00f1',
						'\u00ed',
						'\u00e1',
						'\u00f3',
						'\u00fa',
						'\u00cd',
						'\u201d',
						'\u00b0',
						'\u2013',
						'\u00ae',
						'\u2122',
						'\u2033',
					),
					$fields[ $key ]
				);

				$fields[ '_'.$key ] = $field_key;
			}
		}

		$block[ 'attrs' ][ 'data' ] = $fields;

		return $block;

	}

	public function render_acf_block( $processed_block ) {

		if( empty( $processed_block[ 'blockName' ] ) ){
			return false;
		}

		$processed_rendered_block = '';
		// block name
		$processed_rendered_block .= "<!-- wp:{$processed_block[ 'blockName' ]}";
		// data
		if( $processed_block[ 'attrs' ] ) {
			$processed_rendered_block .= ' ' . wp_unslash( wp_json_encode( $processed_block[ 'attrs' ] ) );
		}

		if( ! $processed_block[ 'innerHTML' ] && ! $processed_block[ 'innerBlocks' ] ) {
			$processed_rendered_block .= " /-->";
		} else {
			// ok now we have either html or innerblocks or both
			// but we are going to use innerContent to populate that
			$innerBlockIndex = 0;
			$processed_rendered_block .= " -->";
			foreach( $processed_block[ 'innerContent' ] as $piece ) {
				if( isset( $piece ) && $piece ) {
					$processed_rendered_block .= $piece; // innerHTML
				} else {
					if( $processed_inner_block = $this->render_acf_block( $processed_block[ 'innerBlocks' ][$innerBlockIndex] ) ) {
						$processed_rendered_block .= $processed_inner_block;
					}
					$innerBlockIndex++;
				}
			}
			$processed_rendered_block .= "<!-- /wp:{$processed_block[ 'blockName' ]} -->";
		}

		return $processed_rendered_block;

	}


}

new Rudr_SWC_ACF;
