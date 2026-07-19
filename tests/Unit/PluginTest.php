<?php

namespace BITS\GroupsIOSync\Tests\Unit;

use BITS\GroupsIOSync\Plugin;
use WP_UnitTestCase;

final class PluginTest extends WP_UnitTestCase {

	public function test_instance_returns_the_same_singleton(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_instance_returns_a_plugin(): void {
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
	}
}
