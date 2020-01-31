<?php
/**
 * Extend on Elementor Kit.
 *
 * @package Analog
 */

namespace Analog\Elementor\Kit;

use Analog\Admin\Notice;
use Analog\Options;
use Analog\Utils;
use Elementor\Plugin;
use Elementor\Core\Files\CSS\Post as Post_CSS;
use Elementor\TemplateLibrary\Source_Local;

/**
 * Class Manager.
 *
 * @since 1.6.0
 * @package Analog\Elementor\Kit
 */
class Manager {
	/**
	 * Elementor key storing active kit ID.
	 */
	const OPTION_ACTIVE = 'elementor_active_kit';

	const OPTION_CUSTOM_KIT = '_elementor_page_settings';

	/**
	 * Manager constructor.
	 */
	public function __construct() {
		add_action( 'elementor/frontend/after_enqueue_global', array( $this, 'frontend_before_enqueue_styles' ), 999 );
		add_action( 'elementor/preview/enqueue_styles', array( $this, 'preview_enqueue_styles' ), 999 );
		add_filter( 'body_class', array( $this, 'should_remove_global_kit_class' ), 999 );
		add_action( 'delete_post', array( $this, 'restore_default_kit' ) );

		add_filter(
			'analog_admin_notices',
			function( $notices ) {
				$notices[] = $this->get_migration_notice();
				return $notices;
			}
		);
	}

	/**
	 * Restore Elementor default if a custom Kit is deleted, if it was global.
	 *
	 * @param int $post_id Post ID being deleted.
	 * @return void
	 */
	public function restore_default_kit( $post_id ) {
		if ( Source_Local::CPT !== get_post_type( $post_id ) ) {
			return;
		}

		$global_kit = Options::get_instance()->get( 'global_kit' );

		if ( $global_kit && $post_id === (int) $global_kit ) {
			update_option( self::OPTION_ACTIVE, Options::get_instance()->get( 'default_kit' ) );
		}
	}

	/**
	 * Get current Post object.
	 *
	 * @return \Elementor\Core\Base\Document|false
	 */
	public function get_current_post() {
		return Plugin::$instance->documents->get( get_the_ID() );
	}

	/**
	 * Deterrmine if current post is using a custom Kit or not.
	 *
	 * @return bool
	 */
	public function is_using_custom_kit() {
		if ( ! get_the_ID() ) {
			return false;
		}

		$kit = $this->get_current_post()->get_meta( self::OPTION_CUSTOM_KIT );

		if ( isset( $kit['ang_action_tokens'] ) && '' !== $kit['ang_action_tokens'] ) {
			return true;
		}

		return false;
	}

	/**
	 * Remove Global Kit CSS added by Elementor.
	 *
	 * @return void
	 */
	public function remove_global_kit_css() {
		$kit_id = get_option( self::OPTION_ACTIVE );

		if ( wp_style_is( 'elementor-post-' . $kit_id, 'enqueued' ) ) {
			wp_dequeue_style( 'elementor-post-' . $kit_id );
		}
	}

	/**
	 * Remove Kit class added by Elementor, if user has custom kit.
	 *
	 * Fired by `body_class` filter.
	 *
	 * @param array $classes Body classes.
	 * @return mixed Modified classes.
	 */
	public function should_remove_global_kit_class( $classes ) {
		if ( $this->is_using_custom_kit() ) {
			$class = 'elementor-kit-' . get_option( self::OPTION_ACTIVE );
			$found = array_search( $class, $classes, true );
			if ( $found ) {
				unset( $classes[ $found ] );
			}
		}

		return $classes;
	}

	/**
	 *
	 * Fired by `elementor/frontend/after_enqueue_global` action.
	 *
	 * @return void
	 */
	public function frontend_before_enqueue_styles() {
		if ( ! $this->is_using_custom_kit() ) {
			return;
		}

		$custom_kit = $this->get_current_post()->get_meta( self::OPTION_CUSTOM_KIT );
		$custom_kit = $custom_kit['ang_action_tokens'];

		$post_status = get_post_status( $custom_kit );
		if ( 'publish' !== $post_status ) {
			return;
		}

		if ( Plugin::$instance->preview->is_preview_mode() ) {
			$this->generate_kit_css();
		} else {
			$this->remove_global_kit_css();
		}

		$css = Post_CSS::create( $custom_kit );
		$css->enqueue();

		Plugin::$instance->frontend->add_body_class( 'elementor-kit-' . $custom_kit );
	}

	/**
	 * Generate CSS stylesheets for all Kits.
	 *
	 * @return void
	 */
	public function generate_kit_css() {
		$kits = Utils::get_kits();

		foreach ( $kits as $id => $title ) {
			$css = Post_CSS::create( $id );
			$css->enqueue();
		}
	}

	/**
	 * Enqueue Elementor preview styles.
	 *
	 * Fired by `elementor/preview/enqueue_styles` action.
	 *
	 * @return void
	 */
	public function preview_enqueue_styles() {
		if ( ! $this->is_using_custom_kit() ) {
			return;
		}

		Plugin::$instance->frontend->print_fonts_links();

		$this->frontend_before_enqueue_styles();
	}

	/**
	 * Create an Elementor Kit.
	 *
	 * @param string $title Kit title.
	 * @param array  $meta Kit meta data. Optional.
	 *
	 * @access private
	 * @return string
	 */
	public function create_kit( string $title, $meta = array() ) {
		$kit = Plugin::$instance->documents->create(
			'kit',
			array(
				'post_type'   => Source_Local::CPT,
				'post_title'  => $title,
				'post_status' => 'publish',
			),
			$meta
		);

		return $kit->get_id();
	}

	/**
	 * Display Kit migration notice.
	 *
	 * @return Notice
	 */
	public function get_migration_notice() {
		$style_kits = wp_count_posts( 'ang_tokens' );

		return new Notice(
			'kit_migration',
			array(
				// TODO: Add docs link
				'content'         => "With the introduction of Theme Styles in Elementor v2.9.0, <strong>Style Kits</strong> has changed how you create and manage Style Kits and need migration.
					You can also run the migration using <a href='https://docs.analogwp.com/' target='_blank'>CLI</a>.
					<br>
					You have {$style_kits->publish} Style Kits, that need migration.
					Upon running this migration, all your Style Kits will be converted to Elementor Kits. This will only take a few seconds.
					<br><br>
					<a href='#' class='button-primary'>Click here to migrate now</a>
					<a href='https://docs.analogwp.	com/' class='button-secondary' target='_blank'>Learn More</a>
					",
				'type'            => Notice::TYPE_ERROR,
				'active_callback' => function() use ( $style_kits ) {
					if ( ! current_user_can( 'manage_options' ) ) {
						return false;
					}

					// Don't show notice if no SKs are found.
					if ( $style_kits && $style_kits->publish < 1 ) {
						return false;
					}

					return true;
				},
				'dismissible'     => false,
			)
		);
	}
}

new Manager();
