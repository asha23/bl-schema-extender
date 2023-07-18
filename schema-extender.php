<?php
/**
* Plugin Name: Yoast Schema Extender - Product with reviews
* Plugin URI: https://brightlocal.com
* Description: Extend Yoast Schema.org data with product type and incorporate product reviews if they are on the page
* Version: 1.0.3
* Author: Ash Whiting for BrightLocal
* Author URI: https://brightlocal.com
**/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Main BL_Product_Review_Schema class
 *
 * @since 1.0
 * @package BE_Product_Review_Schema
 */
class BL_Product_Review_Schema {

	/**
	 * Primary constructor.
	 *
	 * @since 1.0.3
	 */
	function __construct() {
		if(!is_admin()):
			add_action( 'init', array($this, 'template_hooks'), 5, 0 );
		endif;
	}

	/**
	 * Template Hooks
	 *
	 * @since 1.0.3
	 */
	public function template_hooks() {
		add_action( 'wp', array($this, 'create_schema_constructor'));
	}

	/**
	 * Get the post id
	 *
	 * @since 1.0.3
	 */
	public function get_post_id() {
		$post = get_post();

		if(isset($post)):
			$post_id = $post->ID;
    		return $post_id;
		else:
			return;
		endif;
	}

	/**
	 * Create the schema constructor
	 *
	 * @since 1.0.3
	 */
	public function create_schema_constructor() {

		$post_id = $this->get_post_id();

		if(!isset($post_id)):
			return;
		endif;

		$activate_product_schema = get_field('activate_product_schema', $post_id);

		if(get_field('debug_product_schema', 'options')):
			add_filter( 'yoast_seo_development_mode', '__return_true' ); // Debug the schema
		endif;

		// check if we have this turned on.
		if(!isset($activate_product_schema)):
			return;
		endif;

		if($activate_product_schema == 'on'):
			add_filter( 'wpseo_schema_webpage', array($this, 'change_schema_to_product') );
			add_filter( 'wpseo_schema_graph_pieces', array($this,'remove_breadcrumbs_from_schema'), 11, 2 );
			add_filter( 'wpseo_schema_webpage', array($this,'change_schema_properties'), 11, 1 );
		else:
			return;
		endif;
		
	}

	/**
	 * Change schema type to product
	 *
	 * @since 1.0.3
	 */
	public function change_schema_to_product($data) {
		$data['@type'] = 'Product';
    	return $data;
	}

	/**
	 * Remove Breadcrumbs from Schema
	 *
	 * @since 1.0.3
	 */
	public function remove_breadcrumbs_from_schema($pieces, $context) {
		return \array_filter( $pieces, function( $piece ) {
        	return ! $piece instanceof \Yoast\WP\SEO\Generators\Schema\Breadcrumb;
    	} );
	}

	/**
	 * Construct the schema properties for the product.
	 *
	 * @since 1.0.3
	 */
	public function change_schema_properties($data) {
		$post_id = $this->get_post_id();

		if(!isset($post_id)):
			return;
		endif;

		$post_title = get_the_title($post_id);

		$aggregate_rating = get_field('aggregate_rating', $post_id);
		$best_rating = get_field('best_rating', $post_id);
		$total_reviews = get_field('total_reviews', $post_id);
		$product_image = get_field('product_image', $post_id);

		$agg_rating = [];
		$review = [];
		$review_outer = [];
		$brand = [];

		if(isset($product_image)):
			$data['image'] = $product_image;
		endif;
		
		$data['sku'] = strtolower(str_replace(' ', '-', $post_title));
		$data['mpn'] = $post_title;

		$brand['@type'] = 'Brand';
		$brand['name'] =  'BrightLocal';
		$data['brand'] = $brand;

		$agg_rating['@type'] = "AggregateRating";
	
		if(isset($aggregate_rating)):
			$agg_rating['ratingValue'] = $aggregate_rating;
		else:
			$agg_review_rating = 0;
			$agg_rating['ratingValue'] = $agg_review_rating;
		endif;

		if(isset($best_rating)):
			$agg_rating['bestRating'] = $best_rating;
		else:
			$agg_review_best = 5;
			$agg_rating['bestRating'] = $agg_review_best;
		endif;

		if(isset($total_reviews)):
			$agg_rating['reviewCount'] = $total_reviews;
		else:
			$agg_review_total = 5;
			$agg_rating['reviewCount'] = $agg_review_total;
		endif;

		$data['aggregateRating'] = $agg_rating;
		
		// Unset some unecessary properties
		if (array_key_exists('breadcrumb', $data))
        	unset($data['breadcrumb']);

		if (array_key_exists('potentialAction', $data))
        	unset($data['potentialAction']);
    	
		if (array_key_exists('datePublished', $data))
        	unset($data['datePublished']);
    	
		if (array_key_exists('dateModified', $data))
        	unset($data['dateModified']);
    	
		if (array_key_exists('inLanguage', $data))
        	unset($data['inLanguage']);

		if (array_key_exists('isPartOf', $data))
        	unset($data['isPartOf']);

		// Add reviews to the schema
		if ( have_rows('sections_content', $post_id) ):
    		while ( have_rows('sections_content', $post_id) ):
        		the_row();

				$show = get_sub_field('show_hide');
				
				if(!$show || $show == "show"):
					switch(get_row_layout()):
					
						case 'testimonials':

							$testimonials = get_sub_field('testimonial', $post_id);

							if($testimonials):
								foreach($testimonials as $test_item):
									
									$add_review_score = $test_item['add_review_score'];
									$score = $test_item['score'];
									$out_of = $test_item['out_of'];
									$text = $test_item['what_they_said'];
									$name = $test_item['who_said_it'];
									$company = $test_item['their_company'];
									$position = $test_item['their_company'];

									if($score === '' || $score === null):
										
									else:

										// create json review object
										$review['@type'] = "Review";
										$review['name'] = $text;
										$review['reviewRating'] = array(
											'@type' => 'Rating',
											'ratingValue' => $score,
											'bestRating' => $out_of,
										);
										$review['author'] = array(
											'@type' => 'Person',
											'name' => $name,
										);
										
										$review['publisher'] = array(
											'@type' => 'Organization',
											'name' => $company,
										);
										
										$review_outer[] = $review;
									endif;

								endforeach;

								// output the reviews
								$data['review'] = $review_outer;

							endif;
						break;
					endswitch;
				endif;
			endwhile;
		endif;

    	return $data;
	}
}

new BL_Product_Review_Schema;


