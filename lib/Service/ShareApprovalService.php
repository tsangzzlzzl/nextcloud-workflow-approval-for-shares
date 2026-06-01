<?php

declare(strict_types=1);

namespace OCA\Webhooks\Service;

use DateTime;
use OCP\Files\Events\BeforeDirectFileDownloadEvent;
use OCP\Files\Events\BeforeZipCreatedEvent;
use OCP\Files\Events\Node\BeforeNodeReadEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ShareApprovalService {
	private const TABLE = 'webhooks_sa';
	private const DEBOUNCE_SECONDS = 30;

	/** @var array<string, bool> */
	private static $downloadGrantConsumedInRequest = [];

	/** @var bool */
	private static $restoringShare = false;

	/** @var IShare|null */
	private static $currentShare = null;

	/** @var Node|null */
	private static $currentNode = null;

	/** @var string */
	private static $currentAction = 'share';

	/** @var string */
	private static $currentRequester = '';

	/** @var IDBConnection */
	private $db;
	/** @var IShareManager */
	private $shareManager;
	/** @var IRootFolder */
	private $rootFolder;
	/** @var IUserSession */
	private $userSession;
	/** @var IUserManager */
	private $userManager;
	/** @var NotificationService */
	private $notificationService;
	/** @var ActivityService */
	private $activityService;
	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		IDBConnection $db,
		IShareManager $shareManager,
		IRootFolder $rootFolder,
		IUserSession $userSession,
		IUserManager $userManager,
		NotificationService $notificationService,
		ActivityService $activityService,
		LoggerInterface $logger
	) {
		$this->db = $db;
		$this->shareManager = $shareManager;
		$this->rootFolder = $rootFolder;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->notificationService = $notificationService;
		$this->activityService = $activityService;
		$this->logger = $logger;
	}

	public static function isRestoringShare(): bool {
		return self::$restoringShare;
	}

	public static function setCurrentShare(?IShare $share): void {
		self::$currentShare = $share;
		if ($share instanceof IShare) {
			$node = method_exists($share, 'getNode') ? $share->getNode() : null;
			self::setCurrentNode($node instanceof Node ? $node : null, 'share', (string)(method_exists($share, 'getSharedBy') ? ($share->getSharedBy() ?? '') : ''));
		} elseif (self::$currentAction === 'share') {
			self::setCurrentNode(null, 'share', '');
		}
	}

	public static function setCurrentNode(?Node $node, string $action = 'share', string $requester = ''): void {
		self::$currentNode = $node;
		self::$currentAction = $action;
		self::$currentRequester = $requester;
	}

	public static function getCurrentNode(): ?Node {
		return self::$currentNode;
	}

	public static function getCurrentAction(): string {
		return self::$currentAction;
	}

	public static function getCurrentRequester(): string {
		return self::$currentRequester;
	}

	public static function getCurrentFileRelativePath(): string {
		if (self::$currentNode instanceof Node) {
			return self::nodeToRelativePath(self::$currentNode);
		}

		return self::getCurrentShareRelativePath();
	}

	public static function getCurrentFileName(): string {
		$node = self::getCurrentNode();
		return $node instanceof Node ? $node->getName() : '';
	}

	public static function getCurrentFileMimeType(): string {
		$node = self::getCurrentNode();
		if ($node instanceof Folder) {
			return 'httpd/unix-directory';
		}
		if ($node instanceof File) {
			try {
				return (string)$node->getMimetype();
			} catch (\Throwable $e) {
				return '';
			}
		}

		return '';
	}

	public static function getCurrentShareRelativePath(): string {
		if (!self::$currentShare instanceof IShare) {
			return '';
		}

		return self::shareToRelativePath(self::$currentShare);
	}

	public static function normalizePathList($rawPaths): array {
		if (is_string($rawPaths)) {
			$json = json_decode($rawPaths, true);
			if (is_array($json)) {
				$rawPaths = $json;
			} else {
				$rawPaths = preg_split('/[,;\n\r]+/', $rawPaths);
			}
		}

		if (!is_array($rawPaths)) {
			return [];
		}

		$paths = [];
		foreach ($rawPaths as $path) {
			if (is_array($path)) {
				$path = (string)($path['path'] ?? $path['value'] ?? '');
			}
			$path = self::normalizePathValue((string)$path);
			if ($path !== '' && !in_array($path, $paths, true)) {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	public static function isPathWithinAny(string $path, array $parents): bool {
		foreach ($parents as $parent) {
			if (self::isSameOrChildPathValue($path, (string)$parent)) {
				return true;
			}
		}

		return false;
	}

	public function normalizeApprovers($rawApprovers): array {
		if (is_string($rawApprovers)) {
			$rawApprovers = preg_split('/[,;\n\r]+/', $rawApprovers);
		}

		if (!is_array($rawApprovers)) {
			return [];
		}

		$approvers = [];
		foreach ($rawApprovers as $approver) {
			$approver = trim((string)$approver);
			if ($approver !== '' && !in_array($approver, $approvers, true)) {
				$approvers[] = $approver;
			}
		}

		return $approvers;
	}

	public function normalizePaths($rawPaths): array {
		return self::normalizePathList($rawPaths);
	}

	public function matchesPath(IShare $share, array $options): bool {
		$pathMode = (string)($options['pathMode'] ?? 'all');
		if ($pathMode === 'all') {
			return true;
		}

		$configuredPaths = $this->normalizePaths($options['paths'] ?? []);
		if ($configuredPaths === []) {
			return true;
		}

		$sharePath = $this->getShareRelativePath($share);
		if ($sharePath === '') {
			return $pathMode === 'exclude';
		}

		$inside = false;
		foreach ($configuredPaths as $configuredPath) {
			if (self::isSameOrChildPathValue($sharePath, $configuredPath)) {
				$inside = true;
				break;
			}
		}

		return $pathMode === 'include' ? $inside : !$inside;
	}

	public function normalizeTriggerActions($rawActions): array {
		if (is_string($rawActions)) {
			$json = json_decode($rawActions, true);
			$rawActions = is_array($json) ? $json : preg_split('/[,;\n\r]+/', $rawActions);
		}
		if (!is_array($rawActions)) {
			$rawActions = ['share'];
		}

		$actions = [];
		foreach ($rawActions as $action) {
			$action = trim((string)$action);
			if (in_array($action, ['share', 'download'], true) && !in_array($action, $actions, true)) {
				$actions[] = $action;
			}
		}

		return $actions === [] ? ['share'] : $actions;
	}

	public function shouldHandleAction(array $options, string $action): bool {
		return in_array($action, $this->normalizeTriggerActions($options['triggerActions'] ?? ['share']), true);
	}

	public function getDownloadRequester(): string {
		return $this->getCurrentUserId();
	}

	public function getNodeFromDownloadEvent($event): ?Node {
		try {
			if ($event instanceof BeforeNodeReadEvent) {
				$node = $event->getNode();
				return $node instanceof Node ? $node : null;
			}
			if ($event instanceof BeforeZipCreatedEvent) {
				if (method_exists($event, 'getFolder')) {
					$folder = $event->getFolder();
					if ($folder instanceof Node) {
						return $folder;
					}
				}
				$user = $this->userSession->getUser();
				if ($user !== null) {
					$userFolder = $this->rootFolder->getUserFolder($user->getUID());
					$path = method_exists($event, 'getDirectory') ? (string)$event->getDirectory() : '/';
					$node = $userFolder->get(ltrim($path, '/'));
					return $node instanceof Node ? $node : null;
				}
			}
			if ($event instanceof BeforeDirectFileDownloadEvent) {
				$user = $this->userSession->getUser();
				if ($user !== null) {
					$userFolder = $this->rootFolder->getUserFolder($user->getUID());
					$node = $userFolder->get(ltrim($event->getPath(), '/'));
					return $node instanceof Node ? $node : null;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('解析下载审批文件节点失败：' . ($e->getMessage() !== '' ? $e->getMessage() : get_class($e)));
		}

		return null;
	}

	public function matchesNodePath(Node $node, array $options): bool {
		$pathMode = (string)($options['pathMode'] ?? 'all');
		if ($pathMode === 'all') {
			return true;
		}

		$configuredPaths = $this->normalizePaths($options['paths'] ?? []);
		if ($configuredPaths === []) {
			return true;
		}

		$filePath = self::nodeToRelativePath($node);
		if ($filePath === '') {
			return $pathMode === 'exclude';
		}

		$inside = self::isPathWithinAny($filePath, $configuredPaths);
		return $pathMode === 'include' ? $inside : !$inside;
	}

	public function getDownloadApprovalState(Node $node, string $requester, array $flow): array {
		$flowId = (string)($flow['id'] ?? '');
		$fileId = (int)$node->getId();
		if ($fileId <= 0 || $requester === '') {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('shared_by', $qb->createNamedParameter($requester)))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(-100, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->in('status', [$qb->createNamedParameter('pending'), $qb->createNamedParameter('approved')]))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		if ($flowId !== '') {
			$qb->andWhere($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowId)));
		}

		$result = $this->executeQuery($qb);
		$row = $result->fetch();
		if (method_exists($result, 'closeCursor')) {
			$result->closeCursor();
		}

		return is_array($row) ? $row : [];
	}

	public function createPendingDownloadApproval(Node $node, string $requester, array $options, array $flow, string $eventName): int {
		$existing = $this->getDownloadApprovalState($node, $requester, $flow);
		if (($existing['status'] ?? '') === 'pending' && $this->isApprovalRecent($existing, self::DEBOUNCE_SECONDS)) {
			return (int)$existing['id'];
		}
		if (($existing['status'] ?? '') === 'approved' && $this->isDownloadGrantCurrentlyValid($existing)) {
			return (int)$existing['id'];
		}

		$approvers = $this->normalizeApprovers($options['approvers'] ?? []);
		if ($approvers === []) {
			throw new RuntimeException('未配置审批人');
		}

		$strategy = in_array(($options['strategy'] ?? 'any'), ['all', 'any'], true) ? $options['strategy'] : 'any';
		$payload = $this->serializeDownload($node, $requester, $eventName);
		$filePath = (string)($payload['filePath'] ?? '');

		$recentThrottle = $this->findRecentApprovalForThrottle(
			(int)($payload['nodeId'] ?? 0),
			$filePath,
			$requester,
			'download',
			self::DEBOUNCE_SECONDS
		);
		if ($recentThrottle !== []) {
			return (int)$recentThrottle['id'];
		}

		$recent = $this->findRecentPendingApproval(
			(int)($payload['nodeId'] ?? 0),
			$requester,
			'download',
			(string)($flow['id'] ?? ''),
			self::DEBOUNCE_SECONDS
		);
		if ($recent !== []) {
			return (int)$recent['id'];
		}

		$approvalKey = hash('sha256', json_encode([
			'action' => 'download',
			'flow' => $flow['id'] ?? '',
			'nodeId' => $payload['nodeId'] ?? 0,
			'requester' => $requester,
			'time' => microtime(true),
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		$now = (new DateTime())->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'approval_key' => $qb->createNamedParameter($approvalKey),
			'flow_id' => $qb->createNamedParameter((string)($flow['id'] ?? '')),
			'status' => $qb->createNamedParameter('pending'),
			'strategy' => $qb->createNamedParameter($strategy),
			'approvers' => $qb->createNamedParameter(json_encode($approvers, JSON_UNESCAPED_UNICODE)),
			'decisions' => $qb->createNamedParameter(json_encode([], JSON_UNESCAPED_UNICODE)),
			'share_payload' => $qb->createNamedParameter(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
			'file_id' => $qb->createNamedParameter((int)($payload['nodeId'] ?? 0), \PDO::PARAM_INT),
			'file_path' => $qb->createNamedParameter($filePath),
			'shared_by' => $qb->createNamedParameter($requester),
			'share_owner' => $qb->createNamedParameter((string)($payload['shareOwner'] ?? $requester)),
			'share_type' => $qb->createNamedParameter(-100, \PDO::PARAM_INT),
			'shared_with' => $qb->createNamedParameter(''),
			'restored_share_id' => $qb->createNamedParameter(null),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
			'decided_at' => $qb->createNamedParameter(null),
		]);
		$this->executeStatement($qb);

		$approvalId = (int)$this->db->lastInsertId(self::TABLE);
		$approval = [
			'id' => $approvalId,
			'approval_key' => $approvalKey,
			'flow_id' => (string)($flow['id'] ?? ''),
			'status' => 'pending',
			'strategy' => $strategy,
			'approvers' => json_encode($approvers, JSON_UNESCAPED_UNICODE),
			'decisions' => json_encode([], JSON_UNESCAPED_UNICODE),
			'share_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'file_id' => (int)($payload['nodeId'] ?? 0),
			'file_path' => $filePath,
			'shared_by' => $requester,
			'share_owner' => (string)($payload['shareOwner'] ?? $requester),
			'share_type' => -100,
			'shared_with' => '',
			'restored_share_id' => null,
			'created_at' => $now,
			'updated_at' => $now,
			'decided_at' => null,
		];

		try {
			$this->notificationService->notifyApprovalStarted($approvalId, $approval);
		} catch (\Throwable $e) {
			$this->logger->error('下载审批记录已创建，但发送通知失败：' . ($e->getMessage() !== '' ? $e->getMessage() : get_class($e)), ['exception' => $e]);
		}

		$this->activityService->publishApprovalSubmitted($approvalId, $approval);

		return $approvalId;
	}

	public function isDownloadApproval(array $approval): bool {
		$payload = json_decode($approval['share_payload'] ?? '{}', true);
		return (int)($approval['share_type'] ?? 0) === -100 || (is_array($payload) && ($payload['action'] ?? '') === 'download');
	}

	public static function getApprovalActionLabel(array $approval): string {
		$payload = json_decode($approval['share_payload'] ?? '{}', true);
		$action = is_array($payload) ? (string)($payload['action'] ?? '') : '';
		return $action === 'download' || (int)($approval['share_type'] ?? 0) === -100 ? '下载' : '分享';
	}

	public function createPendingApproval(IShare $share, array $options, array $flow): int {
		$approvers = $this->normalizeApprovers($options['approvers'] ?? []);
		if ($approvers === []) {
			throw new RuntimeException('未配置审批人');
		}

		$strategy = in_array(($options['strategy'] ?? 'any'), ['all', 'any'], true) ? $options['strategy'] : 'any';
		$payload = $this->serializeShare($share);
		$payload['action'] = 'share';
		$filePath = (string)($payload['filePath'] ?? '');

		$recentThrottle = $this->findRecentApprovalForThrottle(
			(int)($payload['nodeId'] ?? 0),
			$filePath,
			(string)($payload['sharedBy'] ?? ''),
			'share',
			self::DEBOUNCE_SECONDS
		);
		if ($recentThrottle !== []) {
			return (int)$recentThrottle['id'];
		}

		$recent = $this->findRecentPendingApproval(
			(int)($payload['nodeId'] ?? 0),
			(string)($payload['sharedBy'] ?? ''),
			'share',
			(string)($flow['id'] ?? ''),
			self::DEBOUNCE_SECONDS
		);
		if ($recent !== []) {
			return (int)$recent['id'];
		}

		$approvalKey = hash('sha256', json_encode([
			'flow' => $flow['id'] ?? '',
			'payload' => $payload,
			'time' => microtime(true),
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		$now = (new DateTime())->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'approval_key' => $qb->createNamedParameter($approvalKey),
			'flow_id' => $qb->createNamedParameter((string)($flow['id'] ?? '')),
			'status' => $qb->createNamedParameter('pending'),
			'strategy' => $qb->createNamedParameter($strategy),
			'approvers' => $qb->createNamedParameter(json_encode($approvers, JSON_UNESCAPED_UNICODE)),
			'decisions' => $qb->createNamedParameter(json_encode([], JSON_UNESCAPED_UNICODE)),
			'share_payload' => $qb->createNamedParameter(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
			'file_id' => $qb->createNamedParameter((int)($payload['nodeId'] ?? 0), \PDO::PARAM_INT),
			'file_path' => $qb->createNamedParameter($filePath),
			'shared_by' => $qb->createNamedParameter((string)($payload['sharedBy'] ?? '')),
			'share_owner' => $qb->createNamedParameter((string)($payload['shareOwner'] ?? '')),
			'share_type' => $qb->createNamedParameter((int)($payload['shareType'] ?? 0), \PDO::PARAM_INT),
			'shared_with' => $qb->createNamedParameter((string)($payload['sharedWith'] ?? '')),
			'restored_share_id' => $qb->createNamedParameter(null),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
			'decided_at' => $qb->createNamedParameter(null),
		]);
		$this->executeStatement($qb);

		$approvalId = (int)$this->db->lastInsertId(self::TABLE);
		$approval = [
			'id' => $approvalId,
			'approval_key' => $approvalKey,
			'flow_id' => (string)($flow['id'] ?? ''),
			'status' => 'pending',
			'strategy' => $strategy,
			'approvers' => json_encode($approvers, JSON_UNESCAPED_UNICODE),
			'decisions' => json_encode([], JSON_UNESCAPED_UNICODE),
			'share_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'file_id' => (int)($payload['nodeId'] ?? 0),
			'file_path' => $filePath,
			'shared_by' => (string)($payload['sharedBy'] ?? ''),
			'share_owner' => (string)($payload['shareOwner'] ?? ''),
			'share_type' => (int)($payload['shareType'] ?? 0),
			'shared_with' => (string)($payload['sharedWith'] ?? ''),
			'restored_share_id' => null,
			'created_at' => $now,
			'updated_at' => $now,
			'decided_at' => null,
		];

		try {
			$this->notificationService->notifyApprovalStarted($approvalId, $approval);
		} catch (\Throwable $e) {
			$this->logger->error('分享审批记录已创建，但发送通知失败：' . ($e->getMessage() !== '' ? $e->getMessage() : get_class($e)), ['exception' => $e]);
		}

		$this->activityService->publishApprovalSubmitted($approvalId, $approval);

		return $approvalId;
	}

	public function decide(int $approvalId, string $approver, string $decision, array $decisionOptions = []): array {
		$approval = $this->getApproval($approvalId);
		if ($approval === []) {
			throw new RuntimeException('审批申请不存在');
		}

		if (($approval['status'] ?? '') !== 'pending') {
			throw new RuntimeException('审批申请已处理');
		}

		$approvers = json_decode($approval['approvers'] ?? '[]', true);
		if (!is_array($approvers) || !in_array($approver, $approvers, true)) {
			throw new RuntimeException('当前用户不是该申请的审批人');
		}

		$decisions = json_decode($approval['decisions'] ?? '[]', true);
		if (!is_array($decisions)) {
			$decisions = [];
		}

		$decisionRecord = [
			'decision' => $decision,
			'time' => (new DateTime())->format(DateTime::ATOM),
		];

		if ($decision === 'approved' && $this->isDownloadApproval($approval)) {
			$decisionRecord = array_merge($decisionRecord, $this->normalizeDownloadGrantOptions($decisionOptions));
		}

		$decisions[$approver] = $decisionRecord;

		$status = 'pending';
		$restoredShareId = (string)($approval['restored_share_id'] ?? '');

		if ($decision === 'rejected') {
			$status = 'rejected';
		} else {
			$strategy = (string)($approval['strategy'] ?? 'any');
			$approvedUsers = array_filter($decisions, static function ($item) {
				return is_array($item) && ($item['decision'] ?? '') === 'approved';
			});

			if ($strategy === 'any' && count($approvedUsers) >= 1) {
				$status = 'approved';
			} elseif ($strategy === 'all' && count($approvedUsers) >= count($approvers)) {
				$status = 'approved';
			}
		}

		if ($status === 'approved') {
			if ($this->isDownloadApproval($approval)) {
				$decisions['_downloadGrant'] = $this->buildDownloadGrant($decisions);
				$restoredShareId = 'download-approved';
			} else {
				$restoredShareId = $this->restoreShare($approval);
			}
		}

		$this->updateApprovalStatus($approvalId, $status, $decisions, $restoredShareId);
		$approval = $this->getApproval($approvalId);

		$this->activityService->publishApprovalDecision($approvalId, $approval, $approver, $decision);
		if ($status !== 'pending') {
			$this->activityService->publishApprovalFinished($approvalId, $approval, $status, $approver);
		}

		try {
			if ($status === 'pending') {
				$this->notificationService->markApprovalProcessed($approvalId, $approver);
			} else {
				$this->notificationService->notifyApprovalFinished($approvalId, $approval, $status, $approver);
			}
		} catch (\Throwable $e) {
			$this->logger->error('更新分享审批通知失败：' . ($e->getMessage() !== '' ? $e->getMessage() : get_class($e)), ['exception' => $e]);
		}

		return $approval;
	}

	public function normalizeDownloadGrantOptions(array $options): array {
		$downloadLimit = (int)($options['downloadLimit'] ?? $options['download_limit'] ?? 1);
		if ($downloadLimit < 1) {
			$downloadLimit = 1;
		}
		if ($downloadLimit > 999) {
			$downloadLimit = 999;
		}

		$validMinutes = (int)($options['validMinutes'] ?? $options['valid_minutes'] ?? 1440);
		if ($validMinutes < 1) {
			$validMinutes = 1;
		}
		// Keep the upper bound reasonable while still allowing long approvals.
		if ($validMinutes > 525600) {
			$validMinutes = 525600;
		}

		$expiresAt = (new DateTime('+' . $validMinutes . ' minutes'))->format(DateTime::ATOM);
		return [
			'downloadLimit' => $downloadLimit,
			'downloadValidMinutes' => $validMinutes,
			'downloadExpiresAt' => $expiresAt,
		];
	}

	public function isDownloadGrantCurrentlyValid(array $approval): bool {
		if (!$this->isDownloadApproval($approval) || ($approval['status'] ?? '') !== 'approved') {
			return false;
		}

		$grant = $this->getDownloadGrant($approval);
		if ($grant === []) {
			return false;
		}

		$remaining = (int)($grant['remaining'] ?? 0);
		$expiresAt = (string)($grant['expiresAt'] ?? '');
		if ($remaining <= 0 || $expiresAt === '') {
			return false;
		}

		try {
			return new DateTime($expiresAt) > new DateTime();
		} catch (\Throwable $e) {
			return false;
		}
	}

	public function consumeDownloadGrantIfValid(array $approval): bool {
		if (!$this->isDownloadGrantCurrentlyValid($approval)) {
			return false;
		}

		$approvalId = (int)($approval['id'] ?? 0);
		$requestKey = $approvalId . ':' . (string)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
		if (isset(self::$downloadGrantConsumedInRequest[$requestKey])) {
			return true;
		}

		$decisions = json_decode($approval['decisions'] ?? '[]', true);
		if (!is_array($decisions)) {
			return false;
		}
		$grant = $decisions['_downloadGrant'] ?? [];
		if (!is_array($grant)) {
			return false;
		}

		$remaining = (int)($grant['remaining'] ?? 0);
		if ($remaining <= 0) {
			return false;
		}

		$grant['remaining'] = $remaining - 1;
		$grant['consumed'] = (int)($grant['consumed'] ?? 0) + 1;
		$grant['lastConsumedAt'] = (new DateTime())->format(DateTime::ATOM);
		$decisions['_downloadGrant'] = $grant;

		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('decisions', $qb->createNamedParameter(json_encode($decisions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
			->set('updated_at', $qb->createNamedParameter((new DateTime())->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($approvalId, \PDO::PARAM_INT)));
		$this->executeStatement($qb);

		self::$downloadGrantConsumedInRequest[$requestKey] = true;
		return true;
	}

	private function getDownloadGrant(array $approval): array {
		$decisions = json_decode($approval['decisions'] ?? '[]', true);
		if (!is_array($decisions)) {
			return [];
		}
		$grant = $decisions['_downloadGrant'] ?? [];
		return is_array($grant) ? $grant : [];
	}

	private function buildDownloadGrant(array $decisions): array {
		$limits = [];
		$validMinutes = [];
		foreach ($decisions as $key => $decision) {
			if ($key === '_downloadGrant' || !is_array($decision) || ($decision['decision'] ?? '') !== 'approved') {
				continue;
			}
			$limits[] = max(1, (int)($decision['downloadLimit'] ?? 1));
			$validMinutes[] = max(1, (int)($decision['downloadValidMinutes'] ?? 1440));
		}

		if ($limits === []) {
			$limits[] = 1;
		}
		if ($validMinutes === []) {
			$validMinutes[] = 1440;
		}

		$limit = min($limits);
		$minutes = min($validMinutes);
		$approvedAt = new DateTime();
		$expiresAt = (clone $approvedAt)->modify('+' . $minutes . ' minutes')->format(DateTime::ATOM);
		return [
			'limit' => $limit,
			'remaining' => $limit,
			'consumed' => 0,
			'validMinutes' => $minutes,
			'expiresAt' => $expiresAt,
			'approvedAt' => $approvedAt->format(DateTime::ATOM),
		];
	}

	private function findRecentApprovalForThrottle(int $fileId, string $filePath, string $requester, string $action, int $seconds): array {
		if (($fileId <= 0 && trim($filePath) === '') || $requester === '') {
			return [];
		}

		$threshold = (new DateTime('-' . max(1, $seconds) . ' seconds'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		if ($fileId > 0 && trim($filePath) !== '') {
			$fileMatcher = $qb->expr()->orX(
				$qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, \PDO::PARAM_INT)),
				$qb->expr()->eq('file_path', $qb->createNamedParameter($filePath))
			);
		} elseif ($fileId > 0) {
			$fileMatcher = $qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, \PDO::PARAM_INT));
		} else {
			$fileMatcher = $qb->expr()->eq('file_path', $qb->createNamedParameter($filePath));
		}

		$qb->select('*')
			->from(self::TABLE)
			->where($fileMatcher)
			->andWhere($qb->expr()->eq('shared_by', $qb->createNamedParameter($requester)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->gte('created_at', $qb->createNamedParameter($threshold)),
				$qb->expr()->gte('updated_at', $qb->createNamedParameter($threshold))
			))
			->orderBy('id', 'DESC')
			->setMaxResults(10);

		if ($action === 'download') {
			$qb->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(-100, \PDO::PARAM_INT)));
		} else {
			$qb->andWhere($qb->expr()->neq('share_type', $qb->createNamedParameter(-100, \PDO::PARAM_INT)));
		}

		$result = $this->executeQuery($qb);
		while (($row = $result->fetch()) !== false) {
			if (!is_array($row)) {
				continue;
			}
			$payload = json_decode($row['share_payload'] ?? '{}', true);
			if (is_array($payload)) {
				$payloadAction = (string)($payload['action'] ?? 'share');
				if ($action === 'download' && $payloadAction !== 'download') {
					continue;
				}
				if ($action === 'share' && $payloadAction === 'download') {
					continue;
				}
			}
			if (method_exists($result, 'closeCursor')) {
				$result->closeCursor();
			}
			return $row;
		}
		if (method_exists($result, 'closeCursor')) {
			$result->closeCursor();
		}

		return [];
	}

	private function findRecentPendingApproval(int $fileId, string $requester, string $action, string $flowId, int $seconds): array {
		if ($fileId <= 0 || $requester === '') {
			return [];
		}

		$threshold = (new DateTime('-' . max(1, $seconds) . ' seconds'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('shared_by', $qb->createNamedParameter($requester)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($threshold)))
			->orderBy('id', 'DESC')
			->setMaxResults(5);
		if ($flowId !== '') {
			$qb->andWhere($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowId)));
		}
		if ($action === 'download') {
			$qb->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(-100, \PDO::PARAM_INT)));
		} else {
			$qb->andWhere($qb->expr()->neq('share_type', $qb->createNamedParameter(-100, \PDO::PARAM_INT)));
		}

		$result = $this->executeQuery($qb);
		while (($row = $result->fetch()) !== false) {
			if (!is_array($row)) {
				continue;
			}
			if ($action === 'share') {
				$payload = json_decode($row['share_payload'] ?? '{}', true);
				if (is_array($payload) && ($payload['action'] ?? 'share') !== 'share') {
					continue;
				}
			}
			if (method_exists($result, 'closeCursor')) {
				$result->closeCursor();
			}
			return $row;
		}
		if (method_exists($result, 'closeCursor')) {
			$result->closeCursor();
		}

		return [];
	}

	public function isApprovalRecent(array $approval, int $seconds): bool {
		$createdAt = (string)($approval['created_at'] ?? '');
		if ($createdAt === '') {
			return false;
		}
		try {
			return (new DateTime($createdAt))->getTimestamp() >= time() - max(1, $seconds);
		} catch (\Throwable $e) {
			return false;
		}
	}

	public function getApproval(int $approvalId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($approvalId, \PDO::PARAM_INT)));
		$result = $this->executeQuery($qb);
		$row = $result->fetch();
		if (method_exists($result, 'closeCursor')) {
			$result->closeCursor();
		}

		return is_array($row) ? $row : [];
	}

	private function updateApprovalStatus(int $approvalId, string $status, array $decisions, string $restoredShareId): void {
		$now = (new DateTime())->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter($status))
			->set('decisions', $qb->createNamedParameter(json_encode($decisions, JSON_UNESCAPED_UNICODE)))
			->set('restored_share_id', $qb->createNamedParameter($restoredShareId !== '' ? $restoredShareId : null))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($approvalId, \PDO::PARAM_INT)));

		if ($status !== 'pending') {
			$qb->set('decided_at', $qb->createNamedParameter($now));
		}

		$this->executeStatement($qb);
	}

	private function restoreShare(array $approval): string {
		$payload = json_decode($approval['share_payload'] ?? '{}', true);
		if (!is_array($payload)) {
			throw new RuntimeException('分享参数无效');
		}

		$sharedBy = (string)($payload['sharedBy'] ?? $approval['shared_by'] ?? '');
		$shareOwner = (string)($payload['shareOwner'] ?? $approval['share_owner'] ?? '');
		if ($shareOwner === '') {
			$shareOwner = $sharedBy;
		}
		if ($sharedBy === '') {
			$sharedBy = $shareOwner;
		}
		if ($sharedBy === '') {
			throw new RuntimeException('无法恢复分享：原始分享发起人为空');
		}

		$node = $this->findNodeForShareRestore(
			(int)($payload['nodeId'] ?? $approval['file_id'] ?? 0),
			$sharedBy,
			$shareOwner,
			(string)($payload['filePath'] ?? $approval['file_path'] ?? '')
		);
		if (!$node instanceof Node) {
			throw new RuntimeException('原始分享文件已不存在或发起人已无权访问');
		}

		$previousUser = null;
		try {
			$previousUser = $this->userSession->getUser();
		} catch (\Throwable $e) {
			$previousUser = null;
		}

		$shareUser = $this->userManager->get($sharedBy);
		if ($shareUser === null) {
			throw new RuntimeException('无法恢复分享：原始分享发起人不存在：' . $sharedBy);
		}

		self::$restoringShare = true;
		try {
			// ShareManager and some sharing hooks still read the active user from IUserSession.
			// Notification actions are executed as the approver, so we temporarily switch the
			// request-scoped active user back to the original sharer while recreating the share.
			if (method_exists($this->userSession, 'setVolatileActiveUser')) {
				$this->userSession->setVolatileActiveUser($shareUser);
			}

			$newShare = $this->shareManager->newShare();
			$this->callShareSetter($newShare, 'setNode', $node, true);
			$this->callShareSetter($newShare, 'setShareType', (int)($payload['shareType'] ?? $approval['share_type'] ?? 0), true);
			$this->callShareSetter($newShare, 'setSharedBy', $sharedBy, true);
			$this->callShareSetter($newShare, 'setShareOwner', $shareOwner, false);

			if (!empty($payload['password'])) {
				$this->callShareSetter($newShare, 'setPassword', (string)$payload['password']);
			}
			if (array_key_exists('passwordExpirationTime', $payload) && !empty($payload['passwordExpirationTime'])) {
				$this->callShareSetter($newShare, 'setPasswordExpirationTime', new DateTime((string)$payload['passwordExpirationTime']));
			}
			if (array_key_exists('mailSend', $payload) && $payload['mailSend'] !== null) {
				$this->callShareSetter($newShare, 'setMailSend', (bool)$payload['mailSend']);
			}
			if (array_key_exists('sendPasswordByTalk', $payload) && $payload['sendPasswordByTalk'] !== null) {
				$this->callShareSetter($newShare, 'setSendPasswordByTalk', (bool)$payload['sendPasswordByTalk']);
			}

			if (($payload['sharedWith'] ?? null) !== null && (string)$payload['sharedWith'] !== '') {
				$this->callShareSetter($newShare, 'setSharedWith', (string)$payload['sharedWith'], true);
			}

			$this->callShareSetter($newShare, 'setPermissions', (int)($payload['permissions'] ?? 1), true);

			if (!empty($payload['note'])) {
				$this->callShareSetter($newShare, 'setNote', (string)$payload['note']);
			}
			if (!empty($payload['label'])) {
				$this->callShareSetter($newShare, 'setLabel', (string)$payload['label']);
			}
			if (!empty($payload['expirationDate'])) {
				$this->callShareSetter($newShare, 'setExpirationDate', new DateTime((string)$payload['expirationDate']));
			} elseif (array_key_exists('noExpirationDate', $payload) && $payload['noExpirationDate'] !== null) {
				$this->callShareSetter($newShare, 'setNoExpirationDate', (bool)$payload['noExpirationDate']);
			}
			if (isset($payload['hideDownload'])) {
				$this->callShareSetter($newShare, 'setHideDownload', (bool)$payload['hideDownload']);
			}

			$this->restoreShareAttributes($newShare, $payload['attributes'] ?? null);

			$createdShare = $this->shareManager->createShare($newShare);
			return $this->safeShareIdentifier($createdShare);
		} catch (\Throwable $e) {
			$message = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
			$this->logger->error('审批通过后恢复文件分享失败：' . $message, [
				'exception' => $e,
				'approvalId' => (int)($approval['id'] ?? 0),
				'sharedBy' => $sharedBy,
				'shareOwner' => $shareOwner,
				'shareType' => (int)($payload['shareType'] ?? $approval['share_type'] ?? 0),
				'sharedWith' => (string)($payload['sharedWith'] ?? $approval['shared_with'] ?? ''),
				'filePath' => (string)($payload['filePath'] ?? $approval['file_path'] ?? ''),
			]);
			throw new RuntimeException('审批已通过，但恢复分享失败：' . $message, 0, $e);
		} finally {
			if (method_exists($this->userSession, 'setVolatileActiveUser')) {
				try {
					$this->userSession->setVolatileActiveUser($previousUser);
				} catch (\Throwable $e) {
					// Ignore request-scoped user reset failures.
				}
			}
			self::$restoringShare = false;
		}
	}

	private function serializeShare(IShare $share): array {
		$node = $this->safeShareGetter($share, 'getNode');
		$expiration = $this->safeShareGetter($share, 'getExpirationDate');
		$sharedBy = (string)($this->safeShareGetter($share, 'getSharedBy') ?? '');
		if ($sharedBy === '') {
			$sharedBy = $this->getCurrentUserId();
		}
		$shareOwner = (string)($this->safeShareGetter($share, 'getShareOwner') ?? '');
		if ($shareOwner === '' && $node instanceof Node) {
			$shareOwner = $this->getNodeOwnerId($node);
		}
		if ($shareOwner === '') {
			$shareOwner = $sharedBy;
		}
		$password = $this->safeShareGetter($share, 'getPassword');
		$passwordExpirationTime = $this->safeShareGetter($share, 'getPasswordExpirationTime');

		// In BeforeShareCreatedEvent the share object has not been persisted yet.
		// Nextcloud 32's OC\Share20\Share::getFullId() throws UnexpectedValueException
		// before the provider/id are assigned, so every getter that may depend on
		// persistence must be read defensively.
		$payload = [
			'action' => 'share',
			'id' => $this->safeShareGetter($share, 'getId'),
			'fullId' => $this->safeShareGetter($share, 'getFullId'),
			'nodeId' => $this->safeShareGetter($share, 'getNodeId') ?? ($node instanceof Node ? $node->getId() : null),
			'nodeType' => $this->safeShareGetter($share, 'getNodeType'),
			'shareType' => $this->safeShareGetter($share, 'getShareType'),
			'sharedWith' => $this->safeShareGetter($share, 'getSharedWith'),
			'permissions' => $this->safeShareGetter($share, 'getPermissions'),
			'note' => $this->safeShareGetter($share, 'getNote'),
			'expirationDate' => $expiration instanceof DateTime ? $expiration->format(DateTime::ATOM) : null,
			'label' => $this->safeShareGetter($share, 'getLabel'),
			'sharedBy' => $sharedBy,
			'shareOwner' => $shareOwner,
			'target' => $this->safeShareGetter($share, 'getTarget'),
			'hideDownload' => $this->safeShareGetter($share, 'getHideDownload'),
			'password' => is_string($password) && $password !== '' ? $password : null,
			'mailSend' => $this->safeShareGetter($share, 'getMailSend'),
			'sendPasswordByTalk' => $this->safeShareGetter($share, 'getSendPasswordByTalk'),
			'passwordExpirationTime' => $passwordExpirationTime instanceof \DateTimeInterface ? $passwordExpirationTime->format(DateTime::ATOM) : null,
			'noExpirationDate' => $this->safeShareGetter($share, 'getNoExpirationDate'),
			'filePath' => $this->getShareRelativePath($share),
		];

		if (method_exists($share, 'getAttributes')) {
			try {
				$attributes = $share->getAttributes();
				$payload['attributes'] = is_object($attributes) && method_exists($attributes, 'toArray') ? $attributes->toArray() : null;
			} catch (\Throwable $e) {
				// Some share attribute implementations are not directly serializable.
				$this->logger->debug('分享属性序列化失败：' . $e->getMessage());
			}
		}

		return $payload;
	}

	private function serializeDownload(Node $node, string $requester, string $eventName): array {
		$owner = $this->getNodeOwnerId($node);
		if ($owner === '') {
			$owner = $requester;
		}

		return [
			'action' => 'download',
			'eventName' => $eventName,
			'nodeId' => $node->getId(),
			'nodeType' => $node instanceof Folder ? 'folder' : 'file',
			'fileName' => $node->getName(),
			'filePath' => self::nodeToRelativePath($node),
			'sharedBy' => $requester,
			'shareOwner' => $owner,
			'shareType' => -100,
			'sharedWith' => '',
			'mimeType' => self::getCurrentFileMimeType(),
		];
	}

	private function getCurrentUserId(): string {
		try {
			$user = $this->userSession->getUser();
			return $user !== null ? $user->getUID() : '';
		} catch (\Throwable $e) {
			return '';
		}
	}

	private function getNodeOwnerId(Node $node): string {
		try {
			$owner = $node->getOwner();
			return $owner !== null ? $owner->getUID() : '';
		} catch (\Throwable $e) {
			return '';
		}
	}

	private function safeShareGetter(IShare $share, string $method) {
		if (!method_exists($share, $method)) {
			return null;
		}

		try {
			return $share->{$method}();
		} catch (\Throwable $e) {
			$this->logger->debug('读取分享字段 ' . $method . ' 失败：' . ($e->getMessage() !== '' ? $e->getMessage() : get_class($e)));
			return null;
		}
	}

	private function safeShareIdentifier(IShare $share): string {
		$fullId = $this->safeShareGetter($share, 'getFullId');
		if ($fullId !== null && (string)$fullId !== '') {
			return (string)$fullId;
		}

		$id = $this->safeShareGetter($share, 'getId');
		return $id !== null ? (string)$id : '';
	}

	private function findNode(int $nodeId, string $owner, string $relativePath): ?Node {
		return $this->findNodeForShareRestore($nodeId, $owner, $owner, $relativePath);
	}

	private function findNodeForShareRestore(int $nodeId, string $sharedBy, string $shareOwner, string $relativePath): ?Node {
		foreach (array_unique(array_filter([$sharedBy, $shareOwner])) as $uid) {
			try {
				$userFolder = $this->rootFolder->getUserFolder($uid);
				if ($nodeId > 0) {
					$nodes = $userFolder->getById($nodeId);
					foreach ($nodes as $node) {
						if ($node instanceof Node) {
							return $node;
						}
					}
				}
				if ($relativePath !== '') {
					$node = $userFolder->get(ltrim($relativePath, '/'));
					if ($node instanceof Node) {
						return $node;
					}
				}
			} catch (\Throwable $e) {
				// Try the next identity/fallback.
			}
		}

		if ($nodeId > 0 && method_exists($this->rootFolder, 'getFirstNodeById')) {
			try {
				$node = $this->rootFolder->getFirstNodeById($nodeId);
				if ($node instanceof Node) {
					return $node;
				}
			} catch (\Throwable $e) {
				return null;
			}
		}

		return null;
	}

	private function getShareRelativePath(IShare $share): string {
		return self::shareToRelativePath($share);
	}

	private static function nodeToRelativePath(Node $node): string {
		$path = $node->getPath();
		$marker = '/files/';
		$position = strpos($path, $marker);
		if ($position !== false) {
			return self::normalizePathValue(substr($path, $position + strlen($marker)));
		}

		return self::normalizePathValue($path);
	}

	private static function shareToRelativePath(IShare $share): string {
		$node = method_exists($share, 'getNode') ? $share->getNode() : null;
		if (!$node instanceof Node) {
			return '';
		}

		$path = $node->getPath();
		$marker = '/files/';
		$position = strpos($path, $marker);
		if ($position !== false) {
			return self::normalizePathValue(substr($path, $position + strlen($marker)));
		}

		return self::normalizePathValue($path);
	}

	public static function normalizePathValue(string $path): string {
		$path = trim($path);
		$path = preg_replace('#/+#', '/', $path) ?: '';
		if ($path === '' || $path === '/') {
			return '/';
		}

		if ($path[0] !== '/') {
			$path = '/' . $path;
		}

		return rtrim($path, '/');
	}

	public static function isSameOrChildPathValue(string $path, string $parent): bool {
		$path = self::normalizePathValue($path);
		$parent = self::normalizePathValue($parent);
		return $path === $parent || strpos($path . '/', $parent . '/') === 0;
	}

	private function restoreShareAttributes(IShare $share, $rawAttributes): void {
		if (!is_array($rawAttributes) || $rawAttributes === [] || !method_exists($share, 'newAttributes') || !method_exists($share, 'setAttributes')) {
			return;
		}

		try {
			$attributes = $share->newAttributes();
			if (!method_exists($attributes, 'setAttribute')) {
				return;
			}

			$hasAttributes = false;
			foreach ($rawAttributes as $attribute) {
				if (!is_array($attribute)) {
					continue;
				}
				$scope = (string)($attribute['scope'] ?? '');
				$key = (string)($attribute['key'] ?? '');
				if ($scope === '' || $key === '') {
					continue;
				}
				$value = $attribute['value'] ?? ($attribute['enabled'] ?? null);
				$attributes->setAttribute($scope, $key, $value);
				$hasAttributes = true;
			}

			if ($hasAttributes) {
				$share->setAttributes($attributes);
			}
		} catch (\Throwable $e) {
			$this->logger->debug('恢复分享属性失败：' . ($e->getMessage() !== '' ? $e->getMessage() : get_class($e)));
		}
	}

	private function callShareSetter(IShare $share, string $method, $value, bool $required = false): void {
		if (!method_exists($share, $method)) {
			if ($required) {
				throw new RuntimeException('当前 Nextcloud 分享对象缺少必要方法：' . $method);
			}
			return;
		}

		try {
			$share->{$method}($value);
		} catch (\Throwable $e) {
			if ($required) {
				throw $e;
			}
			$this->logger->debug('设置分享字段 ' . $method . ' 失败：' . ($e->getMessage() !== '' ? $e->getMessage() : get_class($e)));
		}
	}

	private function executeStatement($qb): void {
		if (method_exists($qb, 'executeStatement')) {
			$qb->executeStatement();
			return;
		}
		$qb->execute();
	}

	private function executeQuery($qb) {
		if (method_exists($qb, 'executeQuery')) {
			return $qb->executeQuery();
		}
		return $qb->execute();
	}
}
