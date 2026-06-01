<?php

declare(strict_types=1);

namespace OCA\Webhooks\Flow;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IServerContainer;
use OCP\WorkflowEngine\Events\RegisterEntitiesEvent;

class RegisterFlowEntitiesListener implements IEventListener {
	/** @var IServerContainer */
	private $container;

	public function __construct(IServerContainer $container) {
		$this->container = $container;
	}

	public function handle(Event $event): void {
		if (!$event instanceof RegisterEntitiesEvent) {
			return;
		}

		$event->registerEntity($this->container->get(ShareApprovalEntity::class));
	}
}
