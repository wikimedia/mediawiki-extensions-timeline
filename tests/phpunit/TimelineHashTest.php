<?php
class TimelineHashTest extends MediaWikiTestCase {

	/**
	 * @dataProvider provideHashCases
	 * @covers Timeline::hash
	 */
	public function testHash( $expected, $timelinesrc, $args, $hashAppend ) {
		$this->setMwGlobals( 'wgRenderHashAppend', $hashAppend );

		$this->assertEquals(
			$expected,
			Timeline::hash( $timelinesrc, $args )
		);
	}

	public static function provideHashCases() {
		$NO_APPEND = '';

		return [
			'no arguments' => [
				md5( 'hello' ), 'hello', [], $NO_APPEND ],
			'no arguments and $wgRenderHashAppend' => [
				md5( md5( 'hello' ) . 'World' ), 'hello', [], 'World' ],
			'with two arguments' => [
				md5( 'helloarg1arg2' ), 'hello',
				[ 'arg1', 'arg2' ], $NO_APPEND
			],
			'with two arguments and $wgRenderHashAppend' => [
				md5( md5( 'helloarg1arg2' ) . 'World' ), 'hello',
				[ 'arg1', 'arg2' ], 'World'
			],
		];
	}

}
