<?php

namespace MediaWiki\Extension\DiscordNotifications;

use APIBase;
use Config;
use Exception;
use ExtensionRegistry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionStore;

class Hooks implements
	\MediaWiki\Storage\Hook\PageSaveCompleteHook,
	\MediaWiki\Page\Hook\ArticleDeleteCompleteHook,
	\MediaWiki\Hook\TitleMoveCompleteHook,
	\MediaWiki\Hook\AddNewAccountHook,
	\MediaWiki\Hook\BlockIpCompleteHook,
	\MediaWiki\Hook\UploadCompleteHook,
	\MediaWiki\Page\Hook\ArticleProtectCompleteHook,
	\MediaWiki\User\Hook\UserGroupsChangedHook,
	\MediaWiki\Hook\AfterImportPageHook
{

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var RevisionStore
	 */
	private $revisionStore;

	/**
	 * @param Config $config
	 * @param RevisionStore $revisionStore
	 */
	public function __construct(
		Config $config,
		RevisionStore $revisionStore
	) {
		$this->config = $config;
		$this->revisionStore = $revisionStore;
	}

	/**
	 * @inheritDoc
	 */
	public function onPageSaveComplete(
		$wikiPage,
		$user,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	) {
		global $wgDiscordNotificationEditedArticle, $wgDiscordIgnoreMinorEdits,
			$wgDiscordNotificationAddedArticle, $wgDiscordIncludeDiffSize;
		$isNew = (bool)( $flags & EDIT_NEW );

		if ( ( !$wgDiscordNotificationEditedArticle && !$isNew )
			|| ( !$wgDiscordNotificationAddedArticle && $isNew )
			|| Core::titleIsExcluded( $wikiPage->getTitle() ) ) {
			return true;
		}

		if ( $summary != "" ) {
			$summary = wfMessage( 'discordnotifications-summary', $summary )->inContentLanguage()->plain();
		}
		if ( $isNew ) {
			$message = wfMessage( 'discordnotifications-article-created',
				LinkRenderer::getDiscordUserText( $user ),
				LinkRenderer::getDiscordArticleText( $wikiPage ),
				$summary
				)->inContentLanguage()->text();
			if ( $wgDiscordIncludeDiffSize ) {
				$message .= " (" . Core::msg( 'discordnotifications-bytes', $revisionRecord->getSize() ) . ")";
			}
			Core::pushDiscordNotify( $message, $user, 'article_inserted' );
		} else {
			$isMinor = (bool)( $flags & EDIT_MINOR );
			// Skip minor edits if user wanted to ignore them
			if ( $isMinor && $wgDiscordIgnoreMinorEdits ) {return true;
			}

			$message = wfMessage(
				'discordnotifications-article-saved' )->plaintextParams(
				LinkRenderer::getDiscordUserText( $user ),
				Core::msg( $isMinor == true ? 'discordnotifications-article-saved-minor-edits' :
					'discordnotifications-article-saved-edit' ),
				LinkRenderer::getDiscordArticleText( $wikiPage, $revisionRecord->getId() ),
				$summary
					)->inContentLanguage()->text();
			if ( $wgDiscordIncludeDiffSize ) {
				$old = MediaWikiServices::getInstance()->getRevisionLookup()->getPreviousRevision( $revisionRecord );
				if ( $old ) {
					$message .= ' (' . Core::msg( 'discordnotifications-bytes',
						$revisionRecord->getSize() - $old->getSize() ) . ')';
				}
			}
			Core::pushDiscordNotify( $message, $user, 'article_saved' );
		}
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onArticleDeleteComplete( $wikiPage, $user, $reason, $id,
		$content, $logEntry, $archivedRevisionCount
	) {
		global $wgDiscordNotificationRemovedArticle;
		if ( !$wgDiscordNotificationRemovedArticle ) {return;
		}

		global $wgDiscordNotificationShowSuppressed;
		if ( !$wgDiscordNotificationShowSuppressed && $logEntry->getType() != 'delete' ) {return;
		}

		// Discard notifications from excluded pages
		global $wgDiscordExcludeNotificationsFrom;
		if ( is_array( $wgDiscordExcludeNotificationsFrom ) && count( $wgDiscordExcludeNotificationsFrom ) > 0 ) {
			foreach ( $wgDiscordExcludeNotificationsFrom as &$currentExclude ) {
				if ( strpos( $wikiPage->getTitle(), $currentExclude ) === 0 ) {return;
				}
			}
		}

		$message = wfMessage( 'discordnotifications-wikiPage-deleted' )->plaintextParams(
			LinkRenderer::getDiscordUserText( $user ),
			LinkRenderer::getDiscordArticleText( $wikiPage ),
			$reason
		)->inContentLanguage()->text();
		Core::pushDiscordNotify( $message, $user, 'article_deleted' );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onTitleMoveComplete( $old, $nt, $user, $pageid, $redirid,
		$reason, $revision
	) {
		global $wgDiscordNotificationMovedArticle;
		if ( !$wgDiscordNotificationMovedArticle ) {return;
		}

		$message = Core::msg( 'discordnotifications-article-moved',
			LinkRenderer::getDiscordUserText( $user ),
			LinkRenderer::getDiscordArticleText( $old ),
			LinkRenderer::getDiscordArticleText( $nt ),
			$reason );
		Core::pushDiscordNotify( $message, $user, 'article_moved' );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onAddNewAccount( $user, $byEmail ) {
		global $wgDiscordNotificationNewUser, $wgDiscordShowNewUserFullName;

		if ( !$wgDiscordNotificationNewUser ) {
			return;
		}

		$email = "";
		$realName = "";
		try {
			$email = $user->getEmail();
		} catch ( Exception $e ) {
		}
		try {
			$realName = $user->getRealName();
		} catch ( Exception $e ) {
		}

		$messageExtra = "";
		if ( $wgDiscordShowNewUserFullName ) {
			$messageExtra = "(";
			$messageExtra .= $realName . ", ";
			// Remove trailing ,
			$messageExtra = substr( $messageExtra, 0, -2 );
			$messageExtra .= ")";
		}

		$message = Core::msg( 'discordnotifications-new-user',
			LinkRenderer::getDiscordUserText( $user ),
			$messageExtra );
		Core::pushDiscordNotify( $message, $user, 'new_user_account' );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onBlockIpComplete( $block, $user, $priorBlock ) {
		global $wgDiscordNotificationBlockedUser;
		if ( !$wgDiscordNotificationBlockedUser ) {
			return;
		}

		global $wgDiscordNotificationWikiUrl, $wgDiscordNotificationWikiUrlEnding,
			$wgDiscordNotificationWikiUrlEndingBlockList;
		$mReason = $block->getReasonComment()->text;

		$message = Core::msg( 'discordnotifications-block-user',
			LinkRenderer::getDiscordUserText( $user ),
			LinkRenderer::getDiscordUserText( $block->getTarget() ),
			$mReason == "" ? "" : Core::msg( 'discordnotifications-block-user-reason' ) . " '" . $mReason . "'.",
			$block->mExpiry,
			LinkRenderer::makeLink( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding .
				$wgDiscordNotificationWikiUrlEndingBlockList, Core::msg( 'discordnotifications-block-user-list' ) ) );
		Core::pushDiscordNotify( $message, $user, 'user_blocked' );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onUploadComplete( $uploadBase ) {
		global $wgDiscordNotificationFileUpload;
		if ( !$wgDiscordNotificationFileUpload ) {
			return;
		}

		global $wgDiscordNotificationWikiUrl, $wgDiscordNotificationWikiUrlEnding, $wgUser;
		$localFile = $uploadBase->getLocalFile();

		# Use bytes, KiB, and MiB, rounded to two decimal places.
		$fSize = $localFile->size;
		$fUnits = '';
		if ( $localFile->size < 2048 ) {
			$fUnits = 'bytes';
		} elseif ( $localFile->size < 2048 * 1024 ) {
			$fSize /= 1024;
			$fSize = round( $fSize, 2 );
			$fUnits = 'KiB';
		} else {
			$fSize /= 1024 * 1024;
			$fSize = round( $fSize, 2 );
			$fUnits = 'MiB';
		}

		$message = Core::msg( 'discordnotifications-file-uploaded',
			LinkRenderer::getDiscordUserText( $wgUser ),
			LinkRenderer::parseUrl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding .
				$uploadBase->getLocalFile()->getTitle() ),
			$localFile->getTitle(),
			$localFile->getMimeType(),
			$fSize, $fUnits,
			$localFile->getDescription() );

		Core::pushDiscordNotify( $message, $wgUser, 'file_uploaded' );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onArticleProtectComplete( $wikiPage, $user, $protect, $reason ) {
		global $wgDiscordNotificationProtectedArticle;
		if ( !$wgDiscordNotificationProtectedArticle ) {return;
		}

		$message = Core::msg( 'discordnotifications-article-protected',
			LinkRenderer::getDiscordUserText( $user ),
			Core::msg( $protect ? 'discordnotifications-article-protected-change' :
				'discordnotifications-article-protected-remove' ),
			LinkRenderer::getDiscordArticleText( $wikiPage ),
			$reason );
		Core::pushDiscordNotify( $message, $user, 'article_protected' );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onUserGroupsChanged(
		$user,
		$added,
		$removed,
		$performer,
		$reason,
		$oldUGMs,
		$newUGMs
	) {
		global $wgDiscordNotificationUserGroupsChanged;
		if ( !$wgDiscordNotificationUserGroupsChanged ) {return;
		}

		global $wgDiscordNotificationWikiUrl, $wgDiscordNotificationWikiUrlEnding,
			$wgDiscordNotificationWikiUrlEndingUserRights;
		$message = Core::msg( 'discordnotifications-change-user-groups-with-old',
			LinkRenderer::getDiscordUserText( $performer ),
			LinkRenderer::getDiscordUserText( $user ),
			implode( ", ", array_keys( $oldUGMs ) ),
			implode( ", ", $user->getGroups() ),
			LinkRenderer::makeLink( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding .
				$wgDiscordNotificationWikiUrlEndingUserRights . LinkRenderer::getDiscordUserText( $performer ),
				Core::msg( 'discordnotifications-view-user-rights' ) ) );
		Core::pushDiscordNotify( $message, $user, 'user_groups_changed' );
		return true;
	}

	/**
	 * Occurs after the execute() method of an Flow API module
	 * @param APIBase $module
	 * @return void
	 */
	public static function onAPIFlowAfterExecute( APIBase $module ) {
		global $wgDiscordNotificationFlow;

		if ( !$wgDiscordNotificationFlow || !ExtensionRegistry::getInstance()->isLoaded( 'Flow' ) ) {
			return;
		}

		global $wgRequest;
		$action = $module->getModuleName();
		$request = $wgRequest->getValues();
		$result = $module->getResult()->getResultData()['flow'][$action];
		if ( $result['status'] != 'ok' ) {
			return;
		}

		if ( Core::titleIsExcluded( $request['page'] ) ) {
			return;
		}

		global $wgDiscordNotificationWikiUrl, $wgDiscordNotificationWikiUrlEnding, $wgUser;
		$prefix = $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding;
		switch ( $action ) {
			case 'edit-header':
				$message = Core::msg( "discordnotifications-flow-edit-header",
					LinkRenderer::getDiscordUserText( $wgUser ),
					LinkRenderer::makeLink( $prefix . $request['page'], $request['page'] ) );
				break;
			case 'edit-post':
				$message = Core::msg( "discordnotifications-flow-edit-post",
					LinkRenderer::getDiscordUserText( $wgUser ),
					LinkRenderer::makeLink( $prefix . "Topic:" . $result['workflow'],
						Core::flowUUIDToTitleText( $result['workflow'] ) ) );
				break;
			case 'edit-title':
				$message = Core::msg( "discordnotifications-flow-edit-title",
					LinkRenderer::getDiscordUserText( $wgUser ),
					$request['etcontent'],
					LinkRenderer::makeLink( $prefix . 'Topic:' . $result['workflow'],
						Core::flowUUIDToTitleText( $result['workflow'] ) ) );
				break;
			case 'edit-topic-summary':
				$message = Core::msg( "discordnotifications-flow-edit-topic-summary",
					LinkRenderer::getDiscordUserText( $wgUser ),
					LinkRenderer::makeLink( $prefix . 'Topic:' . $result['workflow'],
						Core::flowUUIDToTitleText( $result['workflow'] ) ) );
				break;
			case 'lock-topic':
				$message = Core::msg( "discordnotifications-flow-lock-topic",
					LinkRenderer::getDiscordUserText( $wgUser ),
					// Messages that can be used here:
					// * discordnotifications-flow-lock-topic-lock
					// * discordnotifications-flow-lock-topic-unlock
					Core::msg( "discordnotifications-flow-lock-topic-" . $request['cotmoderationState'] ),
					LinkRenderer::makeLink( $prefix . $request['page'],
						Core::flowUUIDToTitleText( $result['workflow'] ) ) );
				break;
			case 'moderate-post':
				$message = Core::msg( "discordnotifications-flow-moderate-post",
					LinkRenderer::getDiscordUserText( $wgUser ),
					// Messages that can be used here:
					// * discordnotifications-flow-moderate-hide
					// * discordnotifications-flow-moderate-unhide
					// * discordnotifications-flow-moderate-suppress
					// * discordnotifications-flow-moderate-unsuppress
					// * discordnotifications-flow-moderate-delete
					// * discordnotifications-flow-moderate-undelete
					Core::msg( "discordnotifications-flow-moderate-" . $request['mpmoderationState'] ),
					LinkRenderer::makeLink( $prefix . $request['page'],
						Core::flowUUIDToTitleText( $result['workflow'] ) ) );
				break;
			case 'moderate-topic':
				$message = Core::msg( "discordnotifications-flow-moderate-topic",
					LinkRenderer::getDiscordUserText( $wgUser ),
					// Messages that can be used here:
					// * discordnotifications-flow-moderate-hide
					// * discordnotifications-flow-moderate-unhide
					// * discordnotifications-flow-moderate-suppress
					// * discordnotifications-flow-moderate-unsuppress
					// * discordnotifications-flow-moderate-delete
					// * discordnotifications-flow-moderate-undelete
					Core::msg( "discordnotifications-flow-moderate-" . $request['mtmoderationState'] ),
					LinkRenderer::makeLink( $prefix . $request['page'],
						Core::flowUUIDToTitleText( $result['workflow'] ) ) );
				break;
			case 'new-topic':
				$message = Core::msg( "discordnotifications-flow-new-topic",
					LinkRenderer::getDiscordUserText( $wgUser ),
					LinkRenderer::makeLink( $prefix . "Topic:" . $result['committed']['topiclist']['topic-id'],
						$request['nttopic'] ),
					LinkRenderer::makeLink( $prefix . $request['page'], $request['page'] ) );
				break;
			case 'reply':
				$message = Core::msg( "discordnotifications-flow-reply",
					LinkRenderer::getDiscordUserText( $wgUser ),
					LinkRenderer::makeLink( $prefix . 'Topic:' . $result['workflow'],
						Core::flowUUIDToTitleText( $result['workflow'] ) ) );
				break;
			default:
				return;
		}
		Core::pushDiscordNotify( $message, $wgUser, 'flow' );
	}

	/**
	 * @inheritDoc
	 */
	public function onAfterImportPage( $title, $foreignTitle, $revCount,
		$sRevCount, $pageInfo
	) {
		global $wgDiscordNotificationAfterImportPage;
		if ( !$wgDiscordNotificationAfterImportPage ) {
			return;
		}

		$message = Core::msg( 'discordnotifications - import - complete',
			LinkRenderer::getDiscordArticleText( $title ) );
		Core::pushDiscordNotify( $message, null, 'import_complete' );
		return true;
	}
}
