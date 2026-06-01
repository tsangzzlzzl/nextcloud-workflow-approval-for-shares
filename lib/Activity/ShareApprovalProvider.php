<?php

declare(strict_types=1);

namespace OCA\Webhooks\Activity;

use OCA\Webhooks\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory as IL10NFactory;

class ShareApprovalProvider implements IProvider {
	public const TYPE = 'share_approval';
	public const TYPE_SHARE = 'share_approval_share';
	public const TYPE_DOWNLOAD = 'share_approval_download';
	public const OBJECT_TYPE = 'share_approval';
	public const SUBJECT_SUBMITTED = 'share_approval_submitted';
	public const SUBJECT_DECISION = 'share_approval_decision';
	public const SUBJECT_FINISHED = 'share_approval_finished';

	/** @var IL10NFactory */
	private $l10nFactory;
	/** @var IURLGenerator */
	private $urlGenerator;

	public function __construct(IL10NFactory $l10nFactory, IURLGenerator $urlGenerator) {
		$this->l10nFactory = $l10nFactory;
		$this->urlGenerator = $urlGenerator;
	}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null) {
		if ($event->getApp() !== Application::APP_ID || !in_array($event->getType(), [self::TYPE, self::TYPE_SHARE, self::TYPE_DOWNLOAD], true)) {
			throw new UnknownActivityException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $language);
		$params = $event->getSubjectParameters();
		$file = (string)($params['file'] ?? $event->getObjectName() ?? '');
		$requester = (string)($params['requester'] ?? '');
		$approver = (string)($params['approver'] ?? '');
		$decision = (string)($params['decision'] ?? '');
		$status = (string)($params['status'] ?? '');
		$action = (string)($params['action'] ?? '分享');

		switch ($event->getSubject()) {
			case self::SUBJECT_SUBMITTED:
				$event->setParsedSubject($l->t('%s 提交了文件%s审批申请：%s', [$requester, $action, $file]));
				break;

			case self::SUBJECT_DECISION:
				$decisionText = $decision === 'rejected' ? $l->t('拒绝') : $l->t('同意');
				$event->setParsedSubject($l->t('%s 已%s文件%s审批申请：%s', [$approver, $decisionText, $action, $file]));
				break;

			case self::SUBJECT_FINISHED:
				$statusText = $status === 'rejected' ? $l->t('已拒绝') : $l->t('已通过');
				$event->setParsedSubject($l->t('文件%s审批%s：%s，处理人：%s', [$action, $statusText, $file, $approver]));
				break;

			default:
				throw new UnknownActivityException();
		}

		try {
			$event->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')));
		} catch (\Throwable $e) {
			$event->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'actions/checkmark.svg')));
		}
		return $event;
	}
}
