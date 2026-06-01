<?php

declare(strict_types=1);

namespace OCA\Webhooks\Controller;

use OCA\Webhooks\AppInfo\Application;
use OCA\Webhooks\Service\ShareApprovalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use RuntimeException;
use Psr\Log\LoggerInterface;

class ApprovalController extends Controller {
	/** @var ShareApprovalService */
	private $approvalService;
	/** @var IUserSession */
	private $userSession;
	/** @var IUserManager */
	private $userManager;
	/** @var IRootFolder */
	private $rootFolder;
	/** @var LoggerInterface */
	private $logger;
	/** @var ISystemTagManager */
	private $systemTagManager;

	public function __construct(
		string $AppName,
		IRequest $request,
		ShareApprovalService $approvalService,
		IUserSession $userSession,
		IUserManager $userManager,
		IRootFolder $rootFolder,
		LoggerInterface $logger,
		ISystemTagManager $systemTagManager
	) {
		parent::__construct($AppName, $request);
		$this->approvalService = $approvalService;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->rootFolder = $rootFolder;
		$this->logger = $logger;
		$this->systemTagManager = $systemTagManager;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function approve(int $id): JSONResponse {
		return $this->decision($id, 'approved');
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reject(int $id): JSONResponse {
		return $this->decision($id, 'rejected');
	}


	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function approveFromClient(int $id): TemplateResponse {
		return $this->clientDecision($id, 'approved');
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function rejectFromClient(int $id): TemplateResponse {
		return $this->clientDecision($id, 'rejected');
	}


	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadGrantForm(int $id): TemplateResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->approvalResultPage('需要登录', '请先登录 Nextcloud 后再处理该文件下载审批。', 'warning');
		}

		$approval = $this->approvalService->getApproval($id);
		if ($approval === []) {
			return $this->approvalResultPage('审批申请不存在', '该审批申请可能已被删除或已过期。', 'error');
		}
		if (!$this->approvalService->isDownloadApproval($approval)) {
			return $this->clientDecision($id, 'approved');
		}
		if (($approval['status'] ?? '') !== 'pending') {
			return $this->approvalResultPage('审批已处理', '该文件下载审批已处理，无需重复操作。', 'info');
		}
		$approvers = json_decode($approval['approvers'] ?? '[]', true);
		if (!is_array($approvers) || !in_array($user->getUID(), $approvers, true)) {
			return $this->approvalResultPage('无权审批', '当前用户不是该申请的审批人。', 'error');
		}

		return new TemplateResponse(Application::APP_ID, 'download_grant', [
			'id' => $id,
			'file' => (string)($approval['file_path'] ?? ''),
			'requester' => (string)($approval['shared_by'] ?? ''),
			'defaultLimit' => 1,
			'defaultMinutes' => 1440,
			// Submit to the long-existing client-approve route with GET parameters.
			// This avoids depending on a newly added route that may be absent in
			// Nextcloud route caches when info.xml version is intentionally unchanged.
			'actionUrl' => \OC::$server->getURLGenerator()->linkToRouteAbsolute('webhooks.approval.approvefromclient', ['id' => $id]),
			'rejectUrl' => \OC::$server->getURLGenerator()->linkToRouteAbsolute('webhooks.approval.rejectfromclient', ['id' => $id]),
		]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadGrantSubmit(int $id): TemplateResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->approvalResultPage('需要登录', '请先登录 Nextcloud 后再处理该文件下载审批。', 'warning');
		}

		$options = [
			'downloadLimit' => $this->request->getParam('downloadLimit', 1),
			'validMinutes' => $this->request->getParam('validMinutes', 1440),
		];

		try {
			$approval = $this->approvalService->decide($id, $user->getUID(), 'approved', $options);
			$status = (string)($approval['status'] ?? '');
			if ($status === 'pending') {
				return $this->approvalResultPage('审批意见已提交', '当前为会签流程，仍需等待其他审批人处理。', 'success');
			}
			return $this->approvalResultPage('已同意下载申请', '审批已通过。申请人可在有效期和次数限制内重新下载该文件。', 'success');
		} catch (RuntimeException $e) {
			$message = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
			$this->logger->warning('提交文件下载审批授权失败：' . $message, ['exception' => $e, 'approvalId' => $id, 'user' => $user->getUID()]);
			return $this->approvalResultPage('审批操作失败', $message, 'error');
		} catch (\Throwable $e) {
			$message = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
			$this->logger->error('提交文件下载审批授权异常：' . $message, ['exception' => $e, 'approvalId' => $id, 'user' => $user->getUID()]);
			return $this->approvalResultPage('审批操作失败', '处理审批决定失败：' . $message, 'error');
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function searchUsers(string $term = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['users' => []], 401);
		}

		$term = trim($term);
		if ($term === '') {
			return new JSONResponse(['users' => []]);
		}

		try {
			$users = $this->userManager->search($term, 25);
		} catch (\Throwable $e) {
			return new JSONResponse(['users' => []], 200);
		}

		$result = [];
		foreach ($users as $foundUser) {
			if (!$foundUser instanceof IUser) {
				continue;
			}

			$uid = $foundUser->getUID();
			$displayName = $foundUser->getDisplayName();
			$label = $displayName !== '' && $displayName !== $uid ? $displayName . ' (' . $uid . ')' : $uid;

			$result[] = [
				'id' => $uid,
				'displayName' => $displayName,
				'label' => $label,
			];
		}

		return new JSONResponse(['users' => $result]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function searchFolders(string $term = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['folders' => []], 401);
		}

		$term = trim($term);
		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$nodes = $term === '' ? $userFolder->getDirectoryListing() : $userFolder->search($term);
		} catch (\Throwable $e) {
			return new JSONResponse(['folders' => []], 200);
		}

		$folders = [];
		foreach ($nodes as $node) {
			if (!$node instanceof Folder) {
				continue;
			}

			$path = $this->relativePath($node, $userFolder);
			if ($path === '/') {
				continue;
			}

			$folders[] = [
				'path' => $path,
				'name' => $node->getName(),
			];

			if (count($folders) >= 25) {
				break;
			}
		}

		return new JSONResponse(['folders' => $folders]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function searchTags(string $term = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['tags' => []], 401);
		}

		try {
			$tags = $this->systemTagManager->getAllTags(null, trim($term));
		} catch (\Throwable $e) {
			return new JSONResponse(['tags' => []], 200);
		}

		$result = [];
		foreach ($tags as $tag) {
			if (!$tag instanceof ISystemTag) {
				continue;
			}
			try {
				if (method_exists($this->systemTagManager, 'canUserSeeTag') && !$this->systemTagManager->canUserSeeTag($tag, $user)) {
					continue;
				}
			} catch (\Throwable $e) {
				continue;
			}
			$result[] = [
				'id' => (string)$tag->getId(),
				'name' => $tag->getName(),
				'label' => $tag->getName() . ' (#' . $tag->getId() . ')',
			];
			if (count($result) >= 50) {
				break;
			}
		}

		return new JSONResponse(['tags' => $result]);
	}

	private function decision(int $id, string $decision): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'error', 'message' => '未登录'], 401);
		}

		try {
			$options = $decision === 'approved' ? [
				'downloadLimit' => $this->request->getParam('downloadLimit', 1),
				'validMinutes' => $this->request->getParam('validMinutes', 1440),
			] : [];
			$approval = $this->approvalService->decide($id, $user->getUID(), $decision, $options);
			return new JSONResponse([
				'status' => 'ok',
				'approvalStatus' => $approval['status'] ?? '',
			]);
		} catch (RuntimeException $e) {
			$message = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
			$this->logger->warning('处理文件分享审批决定失败：' . $message, ['exception' => $e, 'approvalId' => $id, 'decision' => $decision, 'user' => $user->getUID()]);

			// Notification actions are optimistic in the web UI. If the notification is stale
			// and the approval has already been processed by another approver, return 200 so
			// the stale notification is removed instead of showing a generic operation error.
			if (strpos($message, '已处理') !== false) {
				return new JSONResponse(['status' => 'ok', 'message' => $message, 'approvalStatus' => 'processed']);
			}

			$statusCode = strpos($message, '不是该申请的审批人') !== false ? 403 : 400;
			return new JSONResponse(['status' => 'error', 'message' => $message], $statusCode);
		} catch (\Throwable $e) {
			$message = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
			$this->logger->error('处理文件分享审批决定异常：' . $message, ['exception' => $e, 'approvalId' => $id, 'decision' => $decision, 'user' => $user->getUID()]);
			return new JSONResponse(['status' => 'error', 'message' => '处理审批决定失败：' . $message], 500);
		}
	}


	private function clientDecision(int $id, string $decision): TemplateResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->approvalResultPage('需要登录', '请先登录 Nextcloud 后再处理该文件分享审批。', 'warning');
		}

		try {
			if ($decision === 'approved') {
				$existing = $this->approvalService->getApproval($id);
				if ($existing !== [] && $this->approvalService->isDownloadApproval($existing)) {
					$grantRequested = (string)$this->request->getParam('grant', '') === '1'
						|| $this->request->getParam('downloadLimit', null) !== null
						|| $this->request->getParam('validMinutes', null) !== null;
					if (!$grantRequested) {
						return $this->downloadGrantForm($id);
					}

					$approval = $this->approvalService->decide($id, $user->getUID(), 'approved', [
						'downloadLimit' => $this->request->getParam('downloadLimit', 1),
						'validMinutes' => $this->request->getParam('validMinutes', 1440),
					]);
					$status = (string)($approval['status'] ?? '');
					$message = $status === 'pending'
						? '你的审批意见已提交，当前为会签流程，仍需等待其他审批人处理。'
						: '审批已通过。申请人可在有效期和次数限制内重新下载该文件。';
					return $this->approvalResultPage('已同意下载申请', $message, 'success');
				}
			}
			$approval = $this->approvalService->decide($id, $user->getUID(), $decision);
			$status = (string)($approval['status'] ?? '');
			$title = $decision === 'approved' ? '已同意分享申请' : '已拒绝分享申请';
			$message = $decision === 'approved'
				? '审批已完成，文件分享将在审批规则通过后自动生效。'
				: '审批已完成，该文件分享申请已被拒绝。';
			if ($status === 'pending') {
				$message = '你的审批意见已提交，当前为会签流程，仍需等待其他审批人处理。';
			}
			return $this->approvalResultPage($title, $message, 'success');
		} catch (RuntimeException $e) {
			$message = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
			$this->logger->warning('客户端处理文件分享审批决定失败：' . $message, ['exception' => $e, 'approvalId' => $id, 'decision' => $decision, 'user' => $user->getUID()]);

			if (strpos($message, '已处理') !== false) {
				return $this->approvalResultPage('审批已处理', $message, 'info');
			}

			return $this->approvalResultPage('审批操作失败', $message, 'error');
		} catch (\Throwable $e) {
			$message = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
			$this->logger->error('客户端处理文件分享审批决定异常：' . $message, ['exception' => $e, 'approvalId' => $id, 'decision' => $decision, 'user' => $user->getUID()]);
			return $this->approvalResultPage('审批操作失败', '处理审批决定失败：' . $message, 'error');
		}
	}

	private function approvalResultPage(string $title, string $message, string $status): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'approval_result', [
			'title' => $title,
			'message' => $message,
			'status' => $status,
		]);
	}

	private function relativePath(Node $node, Folder $userFolder): string {
		$base = rtrim($userFolder->getPath(), '/');
		$path = $node->getPath();
		if (strpos($path, $base) === 0) {
			$path = substr($path, strlen($base));
		}
		$path = preg_replace('#/+#', '/', $path) ?: '/';
		if ($path === '') {
			return '/';
		}
		return $path[0] === '/' ? $path : '/' . $path;
	}
}
