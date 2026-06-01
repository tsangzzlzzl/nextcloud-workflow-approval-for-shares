<?php

declare(strict_types=1);

namespace OCA\Webhooks\Activity;

use OCA\Webhooks\AppInfo\Application;
use OCP\Activity\IFilter;
use OCP\IURLGenerator;
use OCP\L10N\IFactory as IL10NFactory;

class ShareApprovalDownloadFilter implements IFilter {
	/** @var IL10NFactory */
	private $l10nFactory;
	/** @var IURLGenerator */
	private $urlGenerator;

	public function __construct(IL10NFactory $l10nFactory, IURLGenerator $urlGenerator) {
		$this->l10nFactory = $l10nFactory;
		$this->urlGenerator = $urlGenerator;
	}

	public function getIdentifier() {
		return 'webhooks_share_approval_download';
	}

	public function getName() {
		return $this->l10nFactory->get(Application::APP_ID)->t('文件下载审批');
	}

	public function getPriority() {
		return 43;
	}

	public function getIcon() {
		try {
			return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'));
		} catch (\Throwable $e) {
			return '';
		}
	}

	public function filterTypes(array $types) {
		return [ShareApprovalProvider::TYPE_DOWNLOAD];
	}

	public function allowedApps() {
		return [Application::APP_ID];
	}
}
