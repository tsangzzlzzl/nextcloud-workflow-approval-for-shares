<?php

declare(strict_types=1);

namespace OCA\Webhooks\Service;

use OCA\Webhooks\Activity\ShareApprovalProvider;
use OCA\Webhooks\AppInfo\Application;
use OCP\Activity\IManager as ActivityManager;
use Psr\Log\LoggerInterface;

class ActivityService {
	/** @var ActivityManager */
	private $activityManager;
	/** @var LoggerInterface */
	private $logger;

	public function __construct(ActivityManager $activityManager, LoggerInterface $logger) {
		$this->activityManager = $activityManager;
		$this->logger = $logger;
	}

	public function publishApprovalSubmitted(int $approvalId, array $approval): void {
		$this->publishToParticipants(
			ShareApprovalProvider::SUBJECT_SUBMITTED,
			$approvalId,
			$approval,
			(string)($approval['shared_by'] ?? ''),
			[
				'requester' => (string)($approval['shared_by'] ?? ''),
			]
		);
	}

	public function publishApprovalDecision(int $approvalId, array $approval, string $approver, string $decision): void {
		$this->publishToParticipants(
			ShareApprovalProvider::SUBJECT_DECISION,
			$approvalId,
			$approval,
			$approver,
			[
				'approver' => $approver,
				'decision' => $decision,
			]
		);
	}

	public function publishApprovalFinished(int $approvalId, array $approval, string $status, string $operator): void {
		$this->publishToParticipants(
			ShareApprovalProvider::SUBJECT_FINISHED,
			$approvalId,
			$approval,
			$operator,
			[
				'approver' => $operator,
				'status' => $status,
			]
		);
	}

	private function publishToParticipants(string $subject, int $approvalId, array $approval, string $author, array $extraParams): void {
		$file = (string)($approval['file_path'] ?? '');
		$isDownload = (int)($approval['share_type'] ?? 0) === -100;
		$activityType = $isDownload ? ShareApprovalProvider::TYPE_DOWNLOAD : ShareApprovalProvider::TYPE_SHARE;
		$params = array_merge([
			'approvalId' => $approvalId,
			'file' => $file,
			'requester' => (string)($approval['shared_by'] ?? ''),
			'strategy' => (string)($approval['strategy'] ?? 'any'),
				'action' => ShareApprovalService::getApprovalActionLabel($approval),
		], $extraParams);

		foreach ($this->getParticipants($approval) as $affectedUser) {
			try {
				$event = $this->activityManager->generateEvent();
				$event->setApp(Application::APP_ID)
					->setType($activityType)
					->setAuthor($author)
					->setAffectedUser($affectedUser)
					->setObject(ShareApprovalProvider::OBJECT_TYPE, $approvalId, $file)
					->setSubject($subject, $params);

				if (method_exists($event, 'setGenerateNotification')) {
					$event->setGenerateNotification(false);
				}

				$this->activityManager->publish($event);
			} catch (\Throwable $e) {
				$this->logger->error('写入文件审批动态失败：' . ($e->getMessage() !== '' ? $e->getMessage() : get_class($e)), [
					'exception' => $e,
					'approvalId' => $approvalId,
					'subject' => $subject,
					'affectedUser' => $affectedUser,
				]);
			}
		}
	}

	/**
	 * @return string[]
	 */
	private function getParticipants(array $approval): array {
		$participants = [
			(string)($approval['shared_by'] ?? ''),
			(string)($approval['share_owner'] ?? ''),
		];

		$approvers = json_decode($approval['approvers'] ?? '[]', true);
		if (is_array($approvers)) {
			foreach ($approvers as $approver) {
				$participants[] = (string)$approver;
			}
		}

		return array_values(array_unique(array_filter(array_map('trim', $participants))));
	}
}
