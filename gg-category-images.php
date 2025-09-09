<?php
/**
 * Plugin Name: GG Category Images
 * Description: Adds an image field to Post Categories and exposes helpers to render it.
 * Author: GG Dev
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'GGCI_META_KEY', 'ggci_image_id' );

/* ========== Admin UI ========== */

/** Enqueue the media modal + tiny script on both list and single-term edit screens */
add_action( 'admin_enqueue_scripts', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) { return; }

	// Load on Categories list/add (edit-tags) and single term edit (term)
	if ( $screen->taxonomy === 'category' && in_array( $screen->base, [ 'edit-tags', 'term' ], true ) ) {
		wp_enqueue_media();

		// Inline script (no optional chaining; supports older admins)
		wp_add_inline_script( 'jquery', '(function($){
			$(document).on("click", ".ggci-upload", function(e){
				e.preventDefault();
				var $wrap = $(this).closest(".ggci-field");
				var frame = $wrap.data("ggciFrame");
				if (!frame){
					frame = wp.media({ title:"Select category image", multiple:false, library:{ type:"image" }});
					frame.on("select", function(){
						var att = frame.state().get("selection").first().toJSON();
						var url = att.url;
						if (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url){ url = att.sizes.thumbnail.url; }
						$wrap.find(".ggci-id").val(att.id);
						$wrap.find(".ggci-preview").html(\'<img src="\'+url+\'" style="max-width:120px;height:auto;border-radius:6px;" />\');
					});
					$wrap.data("ggciFrame", frame);
				}
				frame.open();
			});

			$(document).on("click", ".ggci-remove", function(e){
				e.preventDefault();
				var $wrap = $(this).closest(".ggci-field");
				$wrap.find(".ggci-id").val("");
				$wrap.find(".ggci-preview").empty();
			});
		})(jQuery);' );
	}
} );

/** Field on Add Category screen */
add_action( 'category_add_form_fields', function () {
	wp_nonce_field( 'ggci_save', 'ggci_nonce' ); ?>
	<div class="form-field ggci-field">
		<label for="ggci_id">Category Image</label>
		<div class="ggci-preview" style="margin-bottom:8px;"></div>
		<input type="hidden" id="ggci_id" name="ggci_id" class="ggci-id" value="">
		<button type="button" class="button ggci-upload">Add/Change Image</button>
		<button type="button" class="button-link ggci-remove" style="color:#b32d2e;">Remove</button>
		<p class="description">Optional image used for this category’s archive/cards.</p>
	</div>
<?php } );

/** Field on Edit Category screen */
add_action( 'category_edit_form_fields', function ( $term ) {
	wp_nonce_field( 'ggci_save', 'ggci_nonce' );
	$image_id = (int) get_term_meta( $term->term_id, GGCI_META_KEY, true );
	$thumb    = $image_id ? wp_get_attachment_image( $image_id, 'thumbnail', false, [ 'style' => 'max-width:120px;height:auto;border-radius:6px;' ] ) : ''; ?>
	<tr class="form-field ggci-field">
		<th scope="row"><label for="ggci_id">Category Image</label></th>
		<td>
			<div class="ggci-preview" style="margin-bottom:8px;"><?php echo $thumb ?: ''; ?></div>
			<input type="hidden" id="ggci_id" name="ggci_id" class="ggci-id" value="<?php echo esc_attr( $image_id ); ?>">
			<button type="button" class="button ggci-upload">Add/Change Image</button>
			<button type="button" class="button-link ggci-remove" style="color:#b32d2e;">Remove</button>
			<p class="description">Optional image used for this category’s archive/cards.</p>
		</td>
	</tr>
<?php }, 10, 1 );

/** Save handler (both create + edit) */
add_action( 'created_category', 'ggci_save_term_image' );
add_action( 'edited_category',  'ggci_save_term_image' );
function ggci_save_term_image( $term_id ){
	if ( ! current_user_can( 'manage_categories' ) ) { return; }
	if ( isset( $_POST['ggci_nonce'] ) && wp_verify_nonce( $_POST['ggci_nonce'], 'ggci_save' ) ) {
		$image_id = isset( $_POST['ggci_id'] ) ? (int) $_POST['ggci_id'] : 0;
		if ( $image_id ) {
			update_term_meta( $term_id, GGCI_META_KEY, $image_id );
		} else {
			delete_term_meta( $term_id, GGCI_META_KEY );
		}
	}
}

/* ========== Frontend helpers ========== */

function ggci_get_category_image_id( $term = 0 ){
	$term = $term ? get_term( $term, 'category' ) : get_queried_object();
	if ( ! $term || is_wp_error( $term ) ) { return 0; }
	return (int) get_term_meta( $term->term_id, GGCI_META_KEY, true );
}

function ggci_the_category_image( $args = [] ){
	$defaults = [
		'term'     => 0,
		'size'     => 'large',
		'class'    => 'category-image',
		'fallback' => '',
		'alt'      => '',
	];
	$args = wp_parse_args( $args, $defaults );

	$term     = $args['term'] ? get_term( $args['term'], 'category' ) : get_queried_object();
	$image_id = ggci_get_category_image_id( $term );
	$alt      = trim( (string) $args['alt'] );

	if ( $image_id ) {
		if ( $alt === '' ) {
			$alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
			if ( $alt === '' && $term && ! is_wp_error( $term ) ) { $alt = $term->name; }
		}
		echo wp_get_attachment_image( $image_id, $args['size'], false, [
			'class'    => $args['class'],
			'alt'      => $alt,
			'loading'  => 'lazy',
			'decoding' => 'async',
		] );
	} elseif ( $args['fallback'] ) {
		$alt = $alt ?: ( $term && ! is_wp_error( $term ) ? $term->name : 'Category image' );
		printf(
			'<img src="%s" class="%s" alt="%s" loading="lazy" decoding="async" />',
			esc_url( $args['fallback'] ),
			esc_attr( $args['class'] ),
			esc_attr( $alt )
		);
	}
}
