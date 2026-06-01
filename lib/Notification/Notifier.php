<?php

declare(strict_types=1);

namespace OCA\Webhooks\Notification;

use OCA\Webhooks\AppInfo\Application;
use OCA\Webhooks\Service\ShareApprovalService;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	/** @var IL10NFactory */
	private $l10nFactory;
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var ShareApprovalService */
	private $approvalService;
	/** @var IRequest */
	private $request;
	/** @var array<int, array> */
	private $approvalCache = [];

	public function __construct(
		IL10NFactory $l10nFactory,
		IURLGenerator $urlGenerator,
		ShareApprovalService $approvalService,
		IRequest $request
	) {
		$this->l10nFactory = $l10nFactory;
		$this->urlGenerator = $urlGenerator;
		$this->approvalService = $approvalService;
		$this->request = $request;
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('文件审批');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID || $notification->getObjectType() !== 'share_approval') {
			throw new UnknownNotificationException();
		}

		$approvalId = (int)$notification->getObjectId();
		$approval = $this->getApprovalCached($approvalId);
		if ($approval === []) {
			throw new AlreadyProcessedException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$subject = $notification->getSubject();
		$params = $notification->getSubjectParameters();
		$file = (string)($params['file'] ?? $approval['file_path'] ?? '');
		$operator = (string)($params['operator'] ?? '');
		$sharedBy = (string)($params['sharedBy'] ?? $approval['shared_by'] ?? '');
		$actionText = (string)($params['action'] ?? ShareApprovalService::getApprovalActionLabel($approval));
		$isDownloadApproval = $this->approvalService->isDownloadApproval($approval);

		try {
			$notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')));
		} catch (\Throwable $e) {
			$notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'actions/checkmark.svg')));
		}

		switch ($subject) {
			case 'share_approval_request':
				if (($approval['status'] ?? '') !== 'pending') {
					throw new AlreadyProcessedException();
				}
				$notification->setParsedSubject($l->t('%s 申请%s文件：%s', [$sharedBy, $actionText, $file]));

				// Nextcloud desktop/mobile clients fetch notifications through OCS and
				// may ignore normal HTTP action types in the native notification popup.
				// For those clients we expose WEB actions that open a browser fallback
				// page and complete the approval there. The web UI keeps direct HTTP
				// actions so the existing top-bar experience remains fast.
				$useClientWebActions = $this->shouldUseClientWebActions();
				foreach ($notification->getActions() as $action) {
					switch ($action->getLabel()) {
						case 'approve':
							$action->setParsedLabel($isDownloadApproval ? $l->t('同意并设置下载权限') : $l->t('同意'));
							$action->setPrimary(true);
							if ($isDownloadApproval) {
								$action->setLink($this->downloadGrantUrl($approvalId), 'WEB');
							} elseif ($useClientWebActions) {
								$action->setLink($this->clientActionUrl('approve', $approvalId), 'WEB');
							} else {
								$action->setLink($this->webActionUrl('approve', $approvalId), 'GET');
							}
							$notification->addParsedAction($action);
							break;
						case 'reject':
							$action->setParsedLabel($l->t('拒绝'));
							if ($useClientWebActions) {
								$action->setLink($this->clientActionUrl('reject', $approvalId), 'WEB');
							} else {
								$action->setLink($this->webActionUrl('reject', $approvalId), 'GET');
							}
							$notification->addParsedAction($action);
							break;
					}
				}
				return $notification;

			case 'share_approval_pending':
				$notification->setParsedSubject($l->t('你的文件%s申请已提交审批：%s', [$actionText, $file]));
				return $notification;

			case 'share_approval_approved':
				$notification->setParsedSubject($l->t('你的文件%s申请已通过：%s，审批人：%s', [$actionText, $file, $operator]));
				return $notification;

			case 'share_approval_rejected':
				$notification->setParsedSubject($l->t('你的文件%s申请已被拒绝：%s，审批人：%s', [$actionText, $file, $operator]));
				return $notification;
		}

		throw new UnknownNotificationException();
	}

	private function getApprovalCached(int $approvalId): array {
		if (!array_key_exists($approvalId, $this->approvalCache)) {
			$this->approvalCache[$approvalId] = $this->approvalService->getApproval($approvalId);
		}
		return $this->approvalCache[$approvalId];
	}

	private function webActionUrl(string $action, int $approvalId): string {
		$route = $action === 'reject' ? 'webhooks.approval.reject' : 'webhooks.approval.approve';
		return $this->urlGenerator->linkToRouteAbsolute($route, ['id' => $approvalId]);
	}

	private function downloadGrantUrl(int $approvalId): string {
		// Reuse the existing client-approve route instead of a newly introduced
		// /download-grant route. Some Nextcloud 32 deployments keep route caches
		// for app routes while the app version is intentionally kept at 0.5.9 to
		// avoid the global upgrade screen. The client-approve route already exists
		// in the stable share-approval flow, so it is the safest browser fallback.
		return $this->clientActionUrl('approve', $approvalId);
	}

	private function clientActionUrl(string $action, int $approvalId): string {
		$route = $action === 'reject' ? 'webhooks.approval.rejectfromclient' : 'webhooks.approval.approvefromclient';
		return $this->urlGenerator->linkToRouteAbsolute($route, ['id' => $approvalId]);
	}

	private function shouldUseClientWebActions(): bool {
		$userAgent = strtolower($this->request->getHeader('User-Agent'));
		if ($userAgent === '') {
			return false;
		}

		foreach (['mirall/', 'nextcloud-android', 'nextcloud-ios', 'nextcloud-client', 'clientarchitecture:'] as $needle) {
			if (strpos($userAgent, $needle) !== false) {
				return true;
			}
		}

		return false;
	}
}
