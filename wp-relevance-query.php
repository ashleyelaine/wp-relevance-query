<?php
/**
 * Plugin Name:       WP Relevance Query
 * Plugin URI:        http://mannieschumpert.com/plugins/wp-relevance-query/
 * Description:       Extends WP_Query to allow for post queries sorted by relevance.
 * Version:           0.1
 * Author:            Mannie Schumpert
 * Author URI:        http://mannieschumpert.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * WP Relevance Query class
 */
class WP_Relevance_Query extends WP_Query {

	/**
	 * Queried terms
	 *
	 * These exist in other class vars, but we put them here for easier usage
	 *
	 * @var array
	 */
	var $queried_terms = array();

	/**
	 * Total terms included in query
	 *
	 * @var int
	 */
	var $total_terms = 0;

	/**
	 * Orderby parameter stored for later use
	 *
	 * @var mixed
	 */
	var $orderby;

	/**
	 * Constructor
	 */
	public function __construct( $args = array() ) {

		$this->process_args( $args );
		parent::__construct( $args );
		$this->set_queried_terms();
		$this->set_total_terms();
		$this->modify_posts_array();

	}

	/************************************************
	 * Query Methods
	 ************************************************/

	/**
	 * Process query arguments
	 *
	 * @return array
	 */
	private function process_args( $args ){

		if ( isset( $args['orderby'] ) ){

			// Save orderby parameter to class var
			$this->orderby = $args['orderby'];

			// Unset from main query
			// (posts will be re-ordered later, no reason to give sql query directives for order)
			unset( $args['orderby'] );
		};

		return $args;
	}

	/**
	 * Set queried_terms var
	 *
	 * Used in calculating relevance, etc
	 *
	 * @return void
	 */
	private function set_queried_terms(){

		$queried_terms = array();

		// Add terms as taxonomy => array( terms )
		// TODO: extend for other query parameters, e.g. author, meta
		foreach ( $this->query_vars['tax_query'] as $query_var => $var_data ) {
			$queried_terms[ $var_data['taxonomy'] ] = $var_data['terms'];
		}

		$this->queried_terms = $queried_terms;

	}

	/**
	 * Set total_terms var
	 *
	 * Used in calculating relevance
	 * 
	 * @return void
	 */
	private function set_total_terms(){

		$total_terms = 0;

		// Add number of terms in each taxonomy to the total number of terms
		foreach ( $this->queried_terms as $terms ) {
			$tax_terms = count( $terms );
			$total_terms = $total_terms + $tax_terms;
		}

		$this->total_terms = $total_terms;

	}

	/**
	 * Primary Query modification method
	 *
	 * @return void
	 */
	private function modify_posts_array() {

		// Add data to post objects
		$this->add_posts_terms();
		$this->add_posts_relevance();

		// Order the posts
		$this->order_posts();

	}

	/************************************************
	 * Post Data Methods
	 ************************************************/

	/**
	 * Add terms as array to each post
	 *
	 * The whole term object is added for possible use in templates
	 * (We're querying the post terms now, might as well avoid doing it again later)
	 *
	 * @return void
	 */
	private function add_posts_terms() {

		foreach ( $this->posts as $post ) {
			$post->terms = $this->get_post_terms( $post->ID );
		}

	}

	/**
	 * Get post terms
	 *
	 * @param integer $post_id
	 * 
	 * @return array
	 *
	 * @todo Needs reworking for adding other query argument types
	 */
	private function get_post_terms( $post_id ) {

		$post_terms = array();

		foreach ( $this->queried_terms as $taxonomy => $terms ) {
			$post_terms[ $taxonomy ] = get_the_terms( $post_id, $taxonomy );
		}
		
		return $post_terms;

	}

	/**
	 * Add relevance to post object
	 *
	 * @return void
	 */
	private function add_posts_relevance() {

		foreach ( $this->posts as $post ) {
			$post->relevance = $this->calculate_post_relevance( $post->terms );
		}

	}

	/**
	 * Calculate post relevance by number of queried terms
	 *
	 * @param array $post_terms Post's terms for current query
	 * 
	 * @return integer percentage grade from 1-100
	 * 
	 * @todo Add logic for weight-per-argument functionality
	 * @todo Add filter for rounding result
	 * @todo Add filter for other grade scales?
	 */
	private function calculate_post_relevance( $post_terms ) {

		$term_number = 0;

		// For each taxonomy, check for the queried terms in the post's terms
		foreach ( $post_terms as $taxonomy => $tax_terms ) {

			// TODO: needs reworked for use with slugs as well
			$terms_arr = wp_list_pluck( $tax_terms, 'term_id' );

			foreach ( $terms_arr as $term ){

				// If the post has the queried terms, increment the post's relevance level
				if ( in_array( $term, $this->queried_terms[ $taxonomy ] ) ){
					$term_number ++;
				}
			}

		}

		// Calculate relevance based on post's terms vs total queried terms
		$relevance = ( $term_number / $this->total_terms ) * 100;

		return $relevance;

	}

	/************************************************
	 * Sorting Methods
	 ************************************************/

	/**
	 * Order posts
	 *
	 * @link http://php.net/manual/en/function.array-multisort.php
	 * 
	 * @return void
	 * 
	 * @todo Needs modification for secondary sorting options
	 */
	private function order_posts() {

		$posts = $this->posts;

		// Loop through posts and add sorting flags
		foreach ( $posts as $post => $object ) {
			$relevance[ $post ] = $object->relevance;
			$post_date[ $post ] = $object->post_date;
		}

		// holy cow, this is magic
		array_multisort( $relevance, SORT_NUMERIC, SORT_DESC, $post_date, SORT_STRING, SORT_DESC, $posts );

		$this->posts = $posts;

	}

}
