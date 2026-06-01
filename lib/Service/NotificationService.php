<?php

declare(strict_types=1);

namespace OCA\Webhooks\Service;

use DateTime;
use OCA\Webhooks\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;

class NotificationService {
	/** @var INotificationManager */
	private $notificationManager;
	/** @var IURLGenerator */
	private $urlGenerator;

	public function __construct(INotificationManager $notificationManager, IURLGenerator $urlGenerator) {
		$this->notificationManager = $notificationManager;
		$this->urlGenerator = $urlGenerator;
	}

	public function notifyApprovalStarted(int $approvalId, array $approval): void {
		$this->withDeferredNotifications(function () use ($approvalId, $approval): void {
			$this->sendApprovalRequested($approvalId, $approval);
			$this->sendRequesterPending($approvalId, $approval);
		});
	}

	public function notifyApprovalRequested(int $approvalId, array $approval): void {
		$this->withDeferredNotifications(function () use ($approvalId, $approval): void {
			$this->sendApprovalRequested($approvalId, $approval);
		});
	}

	public function notifyRequesterPending(int $approvalId, array $approval): void {
		$this->withDeferredNotifications(function () use ($approvalId, $approval): void {
			$this->sendRequesterPending($approvalId, $approval);
		});
	}

	public function notifyRequesterCompleted(int $approvalId, array $approval, string $status, string $operator): void {
		$this->withDeferredNotifications(function () use ($approvalId, $approval, $status, $operator): void {
			$this->sendRequesterCompleted($approvalId, $approval, $status, $operator);
		});
	}

	public function notifyApprovalFinished(int $approvalId, array $approval, string $status, string $operator): void {
		$this->withDeferredNotifications(function () use ($approvalId, $approval, $status, $operator): void {
			$this->markApprovalProcessed($approvalId);
			$this->sendRequesterCompleted($approvalId, $approval, $status, $operator);
		});
	}

	public function markApprovalProcessed(int $approvalId, ?string $userId = null): void {
		$notification = $this->notificationManager->createNotification()
			->setApp(Application::APP_ID)
			->setObject('share_approval', (string)$approvalId);

		if ($userId !== null && $userId !== '') {
			$notification->setUser($userId);
		}

		$this->notificationManager->markProcessed($notification);
	}

	private function sendApprovalRequested(int $approvalId, array $approval): void {
		$approvers = json_decode($approval['approvers'] ?? '[]', true);
		if (!is_array($approvers)) {
			return;
		}

		foreach ($approvers as $approver) {
			$approver = trim((string)$approver);
			if ($approver === '') {
				continue;
			}

			$notification = $this->baseNotification($approvalId, $approver)
				->setSubject('share_approval_request', [
					'file' => (string)($approval['file_path'] ?? ''),
					'sharedBy' => (string)($approval['shared_by'] ?? ''),
					'strategy' => (string)($approval['strategy'] ?? 'any'),
					'action' => ShareApprovalService::getApprovalActionLabel($approval),
				]);

			$approveAction = $notification->createAction();
			$approveAction->setLabel('approve')->setLink($this->actionUrl('approve', $approvalId), 'GET');

			$rejectAction = $notification->createAction();
			$rejectAction->setLabel('reject')->setLink($this->actionUrl('reject', $approvalId), 'GET');

			$notification->addAction($approveAction)->addAction($rejectAction);
			$this->notificationManager->notify($notification);
		}
	}

	private function sendRequesterPending(int $approvalId, array $approval): void {
		$requester = (string)($approval['shared_by'] ?? '');
		if ($requester === '') {
			return;
		}

		$notification = $this->baseNotification($approvalId, $requester)
			->setSubject('share_approval_pending', [
				'file' => (string)($approval['file_path'] ?? ''),
				'action' => ShareApprovalService::getApprovalActionLabel($approval),
			]);
		$this->notificationManager->notify($notification);
	}

	private function sendRequesterCompleted(int $approvalId, array $approval, string $status, string $operator): void {
		$requester = (string)($approval['shared_by'] ?? '');
		if ($requester === '') {
			return;
		}

		$subject = $status === 'approved' ? 'share_approval_approved' : 'share_approval_rejected';
		$notification = $this->baseNotification($approvalId, $requester)
			->setSubject($subject, [
				'file' => (string)($approval['file_path'] ?? ''),
				'action' => ShareApprovalService::getApprovalActionLabel($approval),
				'operator' => $operator,
			]);
		$this->notificationManager->notify($notification);
	}

	private function baseNotification(int $approvalId, string $userId): INotification {
		return $this->notificationManager->createNotification()
			->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject('share_approval', (string)$approvalId);
	}

	private function actionUrl(string $action, int $approvalId): string {
		$route = $action === 'reject' ? 'webhooks.approval.reject' : 'webhooks.approval.approve';
		return $this->urlGenerator->linkToRouteAbsolute($route, ['id' => $approvalId]);
	}

	private function withDeferredNotifications(callable $callback): void {
		$shouldFlush = false;
		if (method_exists($this->notificationManager, 'defer')) {
			$shouldFlush = (bool)$this->notificationManager->defer();
		}

		try {
			$callback();
		} finally {
			if ($shouldFlush && method_exists($this->notificationManager, 'flush')) {
				$this->notificationManager->flush();
			}
		}
	}
}
