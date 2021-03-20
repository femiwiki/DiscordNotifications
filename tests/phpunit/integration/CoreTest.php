<?php

namespace MediaWiki\Extension\DiscordNotifications\Tests\Integration;

use MediaWiki\Extension\DiscordNotifications\Core;
use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @group DiscordNotifications
 * @group Database
 *
 * @covers \MediaWiki\Extension\DiscordNotifications\Core
 */
class CoreTest extends MediaWikiIntegrationTestCase {

	/** @var TestingAccessWrapper */
	private $wrapper;

	protected function setUp() : void {
		parent::setUp();
		$this->wrapper = TestingAccessWrapper::newFromClass( Core::class );
	}

	public static function providerTitleIsExcluded() {
		return [
			[ [], 'test', false ],
			[ [ 'Test' ], 'Test', true ],
			[ [ 'Text' ], 'Test', false ],
			[ [ 'Foo', 'Bar' ], 'Test', false ],
			[ [ 'Foo', 'Bar' ], 'Foo', true ],
			[ [ 'Foo', 'Bar' ], 'Bar', true ],
		];
	}

	/**
	 * @dataProvider providerTitleIsExcluded
	 * @covers \MediaWiki\Extension\DiscordNotifications\Core::titleIsExcluded
	 */
	public function testTitleIsExcluded( $excluded, string $titleText, bool $expected ) {
		global $wgDiscordNotificationsExcludeList;
		$excluded = array_merge( $wgDiscordNotificationsExcludeList, [ 'pages' => $excluded ] );
		$this->setMwGlobals( 'wgDiscordNotificationsExcludeList', $excluded );
		$title = $this->getExistingTestPage( $titleText )->getTitle();
		$this->assertSame( $expected, Core::titleIsExcluded( $title ) );
	}

	public static function providerPermissions() {
		return [
			[ 'not-exist', true ],
			[ 'read', false ],
			[ [ 'not-exist' ], true ],
			[ [ 'read' ], false ],
		];
	}

	/**
	 * @dataProvider providerPermissions
	 */
	public function testExcludedPermission( $excluded, $expected ) {
		global $wgDiscordNotificationsExcludeList;
		$excluded = array_merge( $wgDiscordNotificationsExcludeList, [ 'permissions' => $excluded ] );
		$this->setMwGlobals( 'wgDiscordNotificationsExcludeList', $excluded );
		$user = $this->getTestUser()->getUser();
		$arbitrary = 'test' . time() . rand();
		$this->wrapper->pushDiscordNotify( $arbitrary, $user, 'article_saved' );
		$this->assertSame( $expected, Core::$lastMessage === $arbitrary );
	}

	/**
	 * @covers \MediaWiki\Extension\DiscordNotifications\Core::makePost
	 */
	public function testMakePost() {
		$this->assertJsonStringEqualsJsonString(
			'{"embeds": [{ "color" : "2993970" ,"description" : "message"}], "username": "TestWiki"}',
			$this->wrapper->makePost(
				'message',
				'article_saved'
			)
		);

		$this->setMwGlobals( 'wgSitename', 'FooWiki' );
		$this->assertJsonStringEqualsJsonString(
			'{"embeds": [{ "color" : "2993970" ,"description" : "message"}], "username": "FooWiki"}',
			$this->wrapper->makePost(
				'message',
				'article_saved'
			)
		);

		$this->setMwGlobals( 'wgDiscordNotificationsRequestOverride', [ 'username' => 'DummyBot' ] );
		$this->assertJsonStringEqualsJsonString(
			'{"embeds": [ { "color" : "2993970" ,"description" : "message"} ], "username": "DummyBot"}',
			$this->wrapper->makePost(
				'message',
				'article_saved'
			)
		);
	}

	public function testDiscordNotifications() {
		$this->setMwGlobals( [
			'wgDiscordNotificationsIncomingWebhookUrl' => 'https://webhook',
			'wgServer' => 'https://foo.bar'
		] );
		$ct = 1;
		$this->editPage( 'Edit Test', str_repeat( 'lorem', $ct++ ), '', NS_MAIN );
		// phpcs:ignore Generic.Files.LineLength.TooLong
		$regex = '~📄 \[127\.0\.0\.1\]\(https://foo\.bar/index\.php/User:127\.0\.0\.1\) \(\[block\]\(https://foo\.bar/index\.php(\?title=|/)Special:Block/127\.0\.0\.1\) \| \[groups\]\(https://foo\.bar/index\.php(\?title=|/)Special(%3A|:)UserRights(&user=|/)127\.0\.0\.1\) \| \[talk\]\(https://foo\.bar/index\.php(\?title=|/)User_talk:127\.0\.0\.1\) \| \[contribs\]\(https://foo\.bar/index\.php(\?title=|/)Special:Contributions/127\.0\.0\.1\)\) has created article \[Edit Test\]\(https://foo\.bar/index\.php(\?title=|/)Edit(%20|_)Test\) \(\[edit\]\(https://foo\.bar/index\.php\?title=Edit(%20|_)Test&action=edit\) \| \[delete\]\(https://foo\.bar/index\.php\?title=Edit(%20|_)Test&action=delete\) \| \[history\]\(https://foo\.bar/index\.php\?title=Edit(%20|_)Test&action=history\)\)  \(5 bytes\)~';
		$this->assertRegExp( $regex, Core::$lastMessage );
	}
}