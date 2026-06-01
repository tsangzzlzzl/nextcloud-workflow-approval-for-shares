<?php

declare(strict_types=1);

namespace OCA\Webhooks\Flow;

use OCA\Webhooks\Flow\Check\ApprovalFileMimeTypeCheck;
use OCA\Webhooks\Flow\Check\ApprovalFileNameCheck;
use OCA\Webhooks\Flow\Check\ApprovalSystemTagsCheck;
use OCA\Webhooks\Flow\Check\SharePathCheck;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IServerContainer;
use OCP\Util;
use OCP\WorkflowEngine\Events\RegisterChecksEvent;

class RegisterFlowChecksListener implements IEventListener {
	/** @var IServerContainer */
	private $container;

	public function __construct(IServerContainer $container) {
		$this->container = $container;
	}

	public function handle(Event $event): void {
		if (!$event instanceof RegisterChecksEvent) {
			return;
		}

		$event->registerCheck($this->container->get(SharePathCheck::class));
			$event->registerCheck($this->container->get(ApprovalFileNameCheck::class));
			$event->registerCheck($this->container->get(ApprovalFileMimeTypeCheck::class));
			$event->registerCheck($this->container->get(ApprovalSystemTagsCheck::class));

		// The same bundle registers the frontend metadata for operators and checks.
		Util::addScript('webhooks', 'webhooks-main');
	}
}
