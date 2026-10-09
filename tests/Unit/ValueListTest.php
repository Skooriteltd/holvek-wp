<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Core\Support\ValueList;
use PHPUnit\Framework\TestCase;

class ValueListTest extends TestCase {

	public function testEmptyArrayIsAList(): void {
		$this->assertTrue( ValueList::isList( array() ) );
		$this->assertSame( array_is_list( array() ), ValueList::isList( array() ) );
	}

	public function testZeroBasedValuesAreAList(): void {
		$list = array( 'article', 'news' );
		$this->assertTrue( ValueList::isList( $list ) );
		$this->assertSame( array_is_list( $list ), ValueList::isList( $list ) );
	}

	public function testAMapIsNotAList(): void {
		$map = array( 'name' => 'Holvek' );
		$this->assertFalse( ValueList::isList( $map ) );
		$this->assertSame( array_is_list( $map ), ValueList::isList( $map ) );
	}

	public function testAGapIsNotAList(): void {
		$gapped = array(
			0 => 'first',
			2 => 'third',
		);
		$this->assertFalse( ValueList::isList( $gapped ) );
		$this->assertSame( array_is_list( $gapped ), ValueList::isList( $gapped ) );
	}
}
