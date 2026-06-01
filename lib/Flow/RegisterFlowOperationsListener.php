<?php

/**
 * @copyright Copyright (c) 2021 Paweł Kuffel <pawel@kuffel.io>
 *
 * @author Paweł Kuffel <pawel@kuffel.io>
 *
 * @license GNU AGPL version 3 or any later version
 */
namespace OCA\Webhooks\Flow;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IServerContainer;
use OCP\Util;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;

class RegisterFlowOperationsListener implements IEventListener {

	/** @var IServerContainer */
	private $container;

	public function __construct(IServerContainer $container) {
		$this->container = $container;
	}

	/**
	 * @inheritDoc
	 */
	public function handle(Event $event): void {
		if (!$event instanceof RegisterOperationsEvent) {
			return;
		}

		// Original webhook Flow operation: preserved.
		$event->registerOperation($this->container->get(Operation::class));

		// New additive operation: share approval before publishing.
		$event->registerOperation($this->container->get(ShareApprovalOperation::class));

		Util::addScript('webhooks', 'webhooks-main');
	}
}
