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
use OCP\IURLGenerator;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\WorkflowEngine\GenericEntityEvent;
use OCP\WorkflowEngine\IEntity;
use OCP\WorkflowEngine\IRuleMatcher;

class ShareApprovalEntity implements IEntity {
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var ShareApprovalService */
	private $approvalService;
	/** @var Event|null */
	private $event;

	public function __construct(IURLGenerator $urlGenerator, ShareApprovalService $approvalService) {
		$this->urlGenerator = $urlGenerator;
		$this->approvalService = $approvalService;
		$this->event = null;
	}

	public function getName(): string {
		return '文件审批';
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
	}

	public function getEvents(): array {
		return [
			new GenericEntityEvent('文件被分享', BeforeShareCreatedEvent::class),
			new GenericEntityEvent('文件被下载', BeforeNodeReadEvent::class),
			new GenericEntityEvent('文件被直接下载', BeforeDirectFileDownloadEvent::class),
			new GenericEntityEvent('文件夹或多文件被下载', BeforeZipCreatedEvent::class),
		];
	}

	public function prepareRuleMatcher(IRuleMatcher $ruleMatcher, string $eventName, Event $event): void {
		$this->event = $event;

		if ($event instanceof BeforeShareCreatedEvent) {
			$share = $event->getShare();
			ShareApprovalService::setCurrentShare($share);
			$node = method_exists($share, 'getNode') ? $share->getNode() : null;
			if ($node instanceof Node) {
				$ruleMatcher->setEntitySubject($this, $node);
			}
			return;
		}

		$node = $this->approvalService->getNodeFromDownloadEvent($event);
		$requester = $this->approvalService->getDownloadRequester();
		ShareApprovalService::setCurrentNode($node instanceof Node ? $node : null, 'download', $requester);
		if ($node instanceof Node) {
			$ruleMatcher->setEntitySubject($this, $node);
		}
	}

	public function isLegitimatedForUserId(string $userId): bool {
		if ($this->event instanceof BeforeShareCreatedEvent) {
			$share = $this->event->getShare();
			return $userId === $share->getSharedBy() || $userId === $share->getShareOwner();
		}

		if ($this->event instanceof BeforeNodeReadEvent || $this->event instanceof BeforeDirectFileDownloadEvent || $this->event instanceof BeforeZipCreatedEvent) {
			$requester = ShareApprovalService::getCurrentRequester();
			return $requester === '' || $requester === $userId;
		}

		return true;
	}
}
