<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2021 Paweł Kuffel <pawel@kuffel.io>
 *
 * @author Paweł Kuffel <pawel@kuffel.io>
 *
 * @license GNU AGPL version 3 or any later version
 */
namespace OCA\Webhooks\AppInfo;

use OCA\DAV\Events\CalendarObjectCreatedEvent;
use OCA\DAV\Events\CalendarObjectDeletedEvent;
use OCA\DAV\Events\CalendarObjectMovedToTrashEvent;
use OCA\DAV\Events\CalendarObjectUpdatedEvent;
use OCA\Webhooks\Activity\ShareApprovalDownloadFilter;
use OCA\Webhooks\Activity\ShareApprovalProvider;
use OCA\Webhooks\Activity\ShareApprovalShareFilter;
use OCA\Webhooks\Flow\RegisterFlowChecksListener;
use OCA\Webhooks\Flow\RegisterFlowEntitiesListener;
use OCA\Webhooks\Flow\RegisterFlowOperationsListener;
use OCA\Webhooks\Listeners\CalendarObjectCreatedListener;
use OCA\Webhooks\Listeners\CalendarObjectDeletedListener;
use OCA\Webhooks\Listeners\CalendarObjectMovedToTrashListener;
use OCA\Webhooks\Listeners\CalendarObjectUpdatedListener;
use OCA\Webhooks\Listeners\LoginFailedListener;
use OCA\Webhooks\Listeners\PasswordUpdatedListener;
use OCA\Webhooks\Listeners\ShareCreatedListener;
use OCA\Webhooks\Listeners\UserChangedListener;
use OCA\Webhooks\Listeners\UserCreatedListener;
use OCA\Webhooks\Listeners\UserDeletedListener;
use OCA\Webhooks\Listeners\UserLiveStatusListener;
use OCA\Webhooks\Listeners\UserLoggedInListener;
use OCA\Webhooks\Listeners\UserLoggedOutListener;
use OCA\Webhooks\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Activity\IManager as ActivityManager;
use OCP\Authentication\Events\LoginFailedEvent;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\User\Events\PasswordUpdatedEvent;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\User\Events\UserLiveStatusEvent;
use OCP\User\Events\UserLoggedInEvent;
use OCP\User\Events\UserLoggedOutEvent;
use OCP\WorkflowEngine\Events\RegisterChecksEvent;
use OCP\WorkflowEngine\Events\RegisterEntitiesEvent;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;

/**
 * Class Application
 *
 * @package OCA\Webhooks\AppInfo
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'webhooks';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context):void {
		// Existing webhook event listeners. Keep these untouched so the original plugin
		// still works exactly as before.
		$context->registerEventListener(CalendarObjectCreatedEvent::class, CalendarObjectCreatedListener::class);
		$context->registerEventListener(CalendarObjectUpdatedEvent::class, CalendarObjectUpdatedListener::class);
		$context->registerEventListener(CalendarObjectDeletedEvent::class, CalendarObjectDeletedListener::class);
		$context->registerEventListener(CalendarObjectMovedToTrashEvent::class, CalendarObjectMovedToTrashListener::class);
		$context->registerEventListener(LoginFailedEvent::class, LoginFailedListener::class);
		$context->registerEventListener(PasswordUpdatedEvent::class, PasswordUpdatedListener::class);
		$context->registerEventListener(ShareCreatedEvent::class, ShareCreatedListener::class);
		$context->registerEventListener(UserChangedEvent::class, UserChangedListener::class);
		$context->registerEventListener(UserCreatedEvent::class, UserCreatedListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(UserLiveStatusEvent::class, UserLiveStatusListener::class);
		$context->registerEventListener(UserLoggedInEvent::class, UserLoggedInListener::class);
		$context->registerEventListener(UserLoggedOutEvent::class, UserLoggedOutListener::class);

		// Existing Flow operation + the new additive share-approval Flow extension.
		$context->registerEventListener(RegisterOperationsEvent::class, RegisterFlowOperationsListener::class);
		$context->registerEventListener(RegisterEntitiesEvent::class, RegisterFlowEntitiesListener::class);
		$context->registerEventListener(RegisterChecksEvent::class, RegisterFlowChecksListener::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn([$this, 'registerActivityProvider']);
	}

	public function registerActivityProvider(ActivityManager $activityManager): void {
		$activityManager->registerProvider(ShareApprovalProvider::class);
		$activityManager->registerFilter(ShareApprovalShareFilter::class);
		$activityManager->registerFilter(ShareApprovalDownloadFilter::class);
	}

	public static function getAllConfigNames() {
		return array(
			"Calendar Object Created" => CalendarObjectCreatedListener::CONFIG_NAME,
			"Calendar Object Updated" => CalendarObjectUpdatedListener::CONFIG_NAME,
			"Calendar Object Deleted" => CalendarObjectDeletedListener::CONFIG_NAME,
			"Calendar Object Moved to Trash" => CalendarObjectMovedToTrashListener::CONFIG_NAME,
			"Login Failed" => LoginFailedListener::CONFIG_NAME,
			"Password Updated" => PasswordUpdatedListener::CONFIG_NAME,
			"Share Created" => ShareCreatedListener::CONFIG_NAME,
			"User Changed" => UserChangedListener::CONFIG_NAME,
			"User Created" => UserCreatedListener::CONFIG_NAME,
			"User Deleted" => UserDeletedListener::CONFIG_NAME,
			"User Live Status" => UserLiveStatusListener::CONFIG_NAME,
			"User Logged In" => UserLoggedInListener::CONFIG_NAME,
			"User Logged Out" => UserLoggedOutListener::CONFIG_NAME,
		);
	}
}
