<?php

declare(strict_types=1);

namespace OCA\Webhooks\Flow;

use OCA\Webhooks\AppInfo\Application;
use OCA\Webhooks\Service\ShareApprovalService;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\BeforeDirectFileDownloadEvent;
use OCP\Files\Events\BeforeZipCreatedEvent;
use OCP\Files\Events\Node\BeforeNodeReadEvent;
use OCP\Files\Node;
use OCP\HintException;
use OCP\IURLGenerator;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\WorkflowEngine\IManager as FlowManager;
use OCP\WorkflowEngine\IOperation;
use OCP\WorkflowEngine\IRuleMatcher;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

class ShareApprovalOperation implements IOperation {
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var ShareApprovalService */
	private $approvalService;
	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		IURLGenerator $urlGenerator,
		ShareApprovalService $approvalService,
		LoggerInterface $logger
	) {
		$this->urlGenerator = $urlGenerator;
		$this->approvalService = $approvalService;
		$this->logger = $logger;
	}

	public function getDisplayName(): string {
		return '文件审批';
	}

	public function getDescription(): string {
		return '文件分享或下载需要先提交审批，审批通过后才会生效';
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
	}

	public function isAvailableForScope(int $scope): bool {
		return $scope === FlowManager::SCOPE_ADMIN;
	}

	public function validateOperation(string $name, array $checks, string $operation): void {
		$options = json_decode($operation, true);
		if (!is_array($options)) {
			throw new UnexpectedValueException('文件审批配置无效');
		}

		$actions = $this->approvalService->normalizeTriggerActions($options['triggerActions'] ?? ['share']);
		if ($actions === []) {
			throw new UnexpectedValueException('至少需要选择一个审批触发动作');
		}

		$approvers = $this->approvalService->normalizeApprovers($options['approvers'] ?? []);
		if ($approvers === []) {
			throw new UnexpectedValueException('至少需要选择一名审批人');
		}

		$strategy = $options['strategy'] ?? 'any';
		if (!in_array($strategy, ['any', 'all'], true)) {
			throw new UnexpectedValueException('审批策略无效');
		}

		$pathMode = $options['pathMode'] ?? 'all';
		if (!in_array($pathMode, ['all', 'include', 'exclude'], true)) {
			throw new UnexpectedValueException('文件夹匹配模式无效');
		}
	}

	public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void {
		if ($event instanceof BeforeShareCreatedEvent) {
			$this->handleShareEvent($event, $ruleMatcher);
			return;
		}

		if ($event instanceof BeforeNodeReadEvent || $event instanceof BeforeDirectFileDownloadEvent || $event instanceof BeforeZipCreatedEvent) {
			$this->handleDownloadEvent($eventName, $event, $ruleMatcher);
		}
	}

	private function handleShareEvent(BeforeShareCreatedEvent $event, IRuleMatcher $ruleMatcher): void {
		if (ShareApprovalService::isRestoringShare()) {
			return;
		}

		ShareApprovalService::setCurrentShare($event->getShare());
		try {
			$flows = $ruleMatcher->getFlows(false);
			foreach ($flows as $flow) {
				try {
					$options = json_decode($flow['operation'] ?? '', true);
					if (!is_array($options) || !$this->approvalService->shouldHandleAction($options, 'share')) {
						continue;
					}

					if (!$this->approvalService->matchesPath($event->getShare(), $options)) {
						continue;
					}

					$approvalId = $this->approvalService->createPendingApproval($event->getShare(), $options, $flow);
					$event->setError('分享申请已提交审批，审批通过后文件才会真正分享。审批编号：' . $approvalId);
					$event->stopPropagation();
					break;
				} catch (\Throwable $e) {
					$this->logger->error('启动文件分享审批流程失败：' . ($e->getMessage() !== '' ? $e->getMessage() : get_class($e)), ['exception' => $e]);
				}
			}
		} finally {
			ShareApprovalService::setCurrentShare(null);
		}
	}

	private function handleDownloadEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void {
		$node = $this->approvalService->getNodeFromDownloadEvent($event);
		$requester = $this->approvalService->getDownloadRequester();
		ShareApprovalService::setCurrentNode($node instanceof Node ? $node : null, 'download', $requester);
		if (!$node instanceof Node || $requester === '') {
			return;
		}

		try {
			$flows = $ruleMatcher->getFlows(false);
			foreach ($flows as $flow) {
				$options = json_decode($flow['operation'] ?? '', true);
				if (!is_array($options) || !$this->approvalService->shouldHandleAction($options, 'download')) {
					continue;
				}
				if (!$this->approvalService->matchesNodePath($node, $options)) {
					continue;
				}

				$existing = $this->approvalService->getDownloadApprovalState($node, $requester, $flow);
				if (($existing['status'] ?? '') === 'approved' && $this->approvalService->consumeDownloadGrantIfValid($existing)) {
					return;
				}

				$approvalId = (int)($existing['id'] ?? 0);
				if ($approvalId <= 0 || (($existing['status'] ?? '') === 'approved') || (($existing['status'] ?? '') === 'pending' && !$this->approvalService->isApprovalRecent($existing, 30))) {
					$approvalId = $this->approvalService->createPendingDownloadApproval($node, $requester, $options, $flow, $eventName);
				}

				$this->abortDownload($event, '下载申请已提交审批，审批通过后请重新下载文件。审批编号：' . $approvalId);
				return;
			}
		} finally {
			ShareApprovalService::setCurrentNode(null, 'download', '');
		}
	}

	private function abortDownload(Event $event, string $message): void {
		if ($event instanceof BeforeDirectFileDownloadEvent || $event instanceof BeforeZipCreatedEvent) {
			$event->setSuccessful(false);
			$event->setErrorMessage($message);
			return;
		}

		// Ordinary file downloads are emitted through OC_Filesystem::read. Nextcloud's
		// legacy hook bridge rethrows OCP\HintException, so this is the reliable way
		// to stop the storage read before the file stream is returned.
		throw new HintException('文件下载审批待处理', $message);
	}
}
