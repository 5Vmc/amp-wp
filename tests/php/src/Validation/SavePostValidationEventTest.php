<?php
/**
 * Tests for the SavePostValidationEvent class.
 */

namespace AmpProject\AmpWP\Tests\Validation;

use AmpProject\AmpWP\BackgroundTask\BackgroundTaskDeactivator;
use AmpProject\AmpWP\BackgroundTask\SingleScheduledBackgroundTask;
use AmpProject\AmpWP\DevTools\UserAccess;
use AmpProject\AmpWP\Infrastructure\Registerable;
use AmpProject\AmpWP\Infrastructure\Service;
use AmpProject\AmpWP\Tests\Helpers\AssertContainsCompatibility;
use AmpProject\AmpWP\Tests\Helpers\PrivateAccess;
use AmpProject\AmpWP\Tests\Helpers\ValidationRequestMocking;
use AmpProject\AmpWP\Validation\SavePostValidationEvent;
use AmpProject\AmpWP\Validation\URLValidationProvider;
use WP_UnitTestCase;

/**
 * @coversDefaultClass \AmpProject\AmpWP\Validation\SavePostValidationEvent
 */
final class SavePostValidationEventTest extends WP_UnitTestCase {
	use AssertContainsCompatibility, ValidationRequestMocking, PrivateAccess;

	/**
	 * SavePostValidationEvent instance.
	 *
	 * @var SavePostValidationEvent.
	 */
	private $test_instance;

	public function setUp() {
		$this->test_instance = new SavePostValidationEvent( new BackgroundTaskDeactivator(), new UserAccess() );
		add_filter( 'pre_http_request', [ $this, 'get_validate_response' ] );
	}

	/** @covers ::__construct() */
	public function test__construct() {
		$this->assertInstanceof( SingleScheduledBackgroundTask::class, $this->test_instance );
		$this->assertInstanceof( SavePostValidationEvent::class, $this->test_instance );
		$this->assertInstanceof( Service::class, $this->test_instance );
		$this->assertInstanceof( Registerable::class, $this->test_instance );
	}

	/**
	 * @covers ::register()
	 */
	public function test_register() {
		$this->test_instance->register();

		$this->assertEquals( 10, has_action( 'save_post', [ $this->test_instance, 'schedule_event' ] ) );
		$this->assertEquals( 10, has_action( 'amp_single_post_validate', [ $this->test_instance, 'process' ] ) );
	}

	/**
	 * @covers ::process()
	 * @covers ::get_url_validation_provider
	 */
	public function test_process() {
		$this->test_instance->process();
		$this->assertCount( 0, $this->get_validated_urls() );

		$post = $this->factory()->post->create_and_get(
			[
				'post_content' => '<div invalid-attr="1"></div>',
			]
		);

		$this->test_instance->process( $post->ID );

		$this->assertCount( 1, $this->get_validated_urls() );

		$this->assertInstanceof(
			URLValidationProvider::class,
			$this->get_private_property( $this->test_instance, 'url_validation_provider' )
		);
	}

	/** @covers ::should_schedule_event() */
	public function test_should_schedule_event() {
		// No user set.
		$this->assertFalse( $this->call_private_method( $this->test_instance, 'should_schedule_event', [ [] ] ) );

		// Array not passed.
		$this->assertFalse( $this->call_private_method( $this->test_instance, 'should_schedule_event', [ null ] ) );

		// Too many args passed.
		$this->assertFalse( $this->call_private_method( $this->test_instance, 'should_schedule_event', [ [ 'arg1', 'arg2' ] ] ) );

		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post = $this->factory()->post->create();
		$this->assertTrue( $this->call_private_method( $this->test_instance, 'should_schedule_event', [ [ $post ] ] ) );
	}

	/** @covers ::get_action_hook() */
	public function test_get_action_hook() {
		$this->assertEquals( 'save_post', $this->call_private_method( $this->test_instance, 'get_action_hook' ) );
	}
}
