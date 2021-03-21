<?php

namespace MediaWiki\Extension\DiscordNotifications;

use Exception;
use Flow\Model\UUID;
use MediaWiki\User\UserIdentity;
use MessageSpecifier;
use Title;
use User;

class Core {
	/**
	 * @var string used for phpunit
	 */
	public static $lastMessage;

	/**
	 * Returns whether the given title should be excluded
	 * @param Title $title
	 * @return bool
	 * @todo Check case-sensitively when $wgCapitalLinks is false. Case-sensitive only now.
	 */
	public static function titleIsExcluded( Title $title ) {
		global $wgDiscordNotificationsExclude;
		$exclude = $wgDiscordNotificationsExclude['page'];
		if ( isset( $exclude['list'] ) ) {
			$list = $exclude['list'];
			if ( !is_array( $list ) ) {
				$list = [ $list ];
			}
			if ( in_array( $title->getText(), $list ) ) {
				return true;
			}
		}

		if ( isset( $exclude['patterns'] ) ) {
			$patterns = $exclude['patterns'];
			if ( !is_array( $patterns ) ) {
				$patterns = [ $patterns ];
			}
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $title ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Sends the message into Discord room.
	 * @param string $message to be sent.
	 * @param User|UserIdentity|null $user
	 * @param string $action
	 * @see https://discordapp.com/developers/docs/resources/webhook#execute-webhook
	 */
	public static function pushDiscordNotify( string $message, $user, string $action ) {
		global $wgDiscordNotificationsIncomingWebhookUrl, $wgDiscordNotificationsSendMethod,
			$wgDiscordNotificationsExclude;

		// Users with the permission suppress notifications
		if ( $user && $user instanceof User ) {
			$permissions = $wgDiscordNotificationsExclude['permissions'];
			if ( !is_array( $permissions ) ) {
				$permissions = [ $permissions ];
			}
			foreach ( $permissions as $p ) {
				if ( $user->isAllowed( $p ) ) {
					return;
				}
			}
		}

		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			self::$lastMessage = $message;
			return;
		}

		$post = self::makePost( $message, $action );

		$hooks = $wgDiscordNotificationsIncomingWebhookUrl;
		if ( !$hooks ) {
			throw new Exception( '$wgDiscordNotificationsIncomingWebhookUrl is not set' );
		} elseif ( is_string( $hooks ) ) {
			$hooks = [ $hooks ];
		}

		foreach ( $hooks as $hook ) {
			if ( $wgDiscordNotificationsSendMethod == 'file_get_contents' ) {
				// Use file_get_contents to send the data. Note that you will need to have allow_url_fopen
				// enabled in php.ini for this to work.
				self::sendHttpRequest( $hook, $post );
			} else {
				// Call the Discord API through cURL (default way). Note that you will need to have cURL
				// enabled for this to work.
				self::sendCurlRequest( $hook, $post );
			}
		}
	}

	private const ACTION_COLOR_MAP = [
		'article_saved'       => 2993970,
		'import_complete'     => 2993970,
		'user_groups_changed' => 2993970,
		'article_inserted'    => 3580392,
		'article_deleted'     => 15217973,
		'article_moved'       => 14038504,
		'article_protected'   => 3493864,
		'new_user_account'    => 3580392,
		'file_uploaded'       => 3580392,
		'user_blocked'        => 15217973,
		'flow'                => 2993970,
	];

	/**
	 * @param string $message to be sent.
	 * @param string $action
	 * @return string
	 */
	private static function makePost( $message, $action ) {
		global $wgDiscordNotificationsRequestOverride, $wgSitename;

		$colour = 11777212;
		if ( isset( self::ACTION_COLOR_MAP[$action] ) ) {
			$colour = self::ACTION_COLOR_MAP[$action];
		}

		$post = [
			'embeds' => [
				[
					'color' => "$colour",
					'description' => $message,
				]
			],
			'username' => $wgSitename
		];
		$post = array_replace_recursive( $post, $wgDiscordNotificationsRequestOverride );
		return json_encode( $post );
	}

	/**
	 * @param string $url
	 * @param string $postData
	 */
	private static function sendCurlRequest( $url, $postData ) {
		$h = curl_init();
		foreach ( [
			CURLOPT_URL => $url,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $postData,
			CURLOPT_RETURNTRANSFER => true,
			// Set 10 second timeout to connection
			CURLOPT_CONNECTTIMEOUT => 10,
			// Set global 10 second timeout to handle all data
			CURLOPT_TIMEOUT => 10,
			// Set Content-Type to application/json
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Content-Length: ' . strlen( $postData )
			],
			// Commented out lines below. Using default curl settings for host and peer verification.
			// CURLOPT_SSL_VERIFYHOST => 0,
			// CURLOPT_SSL_VERIFYPEER => 0,
		] as $option => $value ) {
			curl_setopt( $h, $option, $value );
		}
		// ... And execute the curl script!
		curl_exec( $h );
		curl_close( $h );
	}

	/**
	 * @param string $url
	 * @param string $postData
	 */
	private static function sendHttpRequest( $url, $postData ) {
		$extra = [
			'http' => [
				'header'  => 'Content-type: application/json',
				'method'  => 'POST',
				'content' => $postData,
			],
		];
		$context = stream_context_create( $extra );
		file_get_contents( $url, false, $context );
	}

	/**
	 * @param string|string[]|MessageSpecifier $key Message key, or array of keys, or a MessageSpecifier
	 * @param mixed ...$params Normal message parameters
	 * @return string
	 */
	public static function msg( $key, ...$params ) {
		if ( $params ) {
			return wfMessage( $key, ...$params )->inContentLanguage()->text();
		} else {
			return wfMessage( $key )->inContentLanguage()->text();
		}
	}

	/**
	 * @param UUID $uuid
	 * @return string
	 */
	public static function flowUUIDToTitleText( UUID $uuid ) {
		$uuid = UUID::create( $uuid );
		$collection = \Flow\Collection\PostCollection::newFromId( $uuid );
		$revision = $collection->getLastRevision();
		return $revision->getContent( 'topic-title-plaintext' );
	}
}
