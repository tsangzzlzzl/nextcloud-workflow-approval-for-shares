<?php

declare(strict_types=1);

namespace OCA\Webhooks\Controller;

use OCA\Webhooks\Service\ShareApprovalService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ApprovalApiController extends OCSController {
	/** @var ShareApprovalService */
	private $approvalService;
	/** @var IUserSession */
	private $userSession;
	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		string $AppName,
		IRequest $request,
		ShareApprovalService $approvalService,
		IUserSession $userSession,
		LoggerInterface $logger
	) {
		parent::__construct($AppName, $request);
		$this->approvalService = $approvalService;
		$this->userSession = $userSession;
		$this->logger = $logger;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function approve(int $id): DataResponse {
		return $this->decision($id, 'approved');
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reject(int $id): DataResponse {
		return $this->decision($id, 'rejected');
	}

	private function decision(int $id, string $decision): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['status' => 'error', 'message' => '未登录'], 401);
		}

		try {
			$options = $decision === 'approved' ? [
				'downloadLimit' => $this->request->getParam('downloadLimit', 1),
				'validMinutes' => $this->request->getParam('validMinutes', 1440),
			] : [];
			$approval = $this->approvalService->decide($id, $user->getUID(), $decision, $options);
			return new DataResponse([
				'status' => 'ok',
				'approvalStatus' => $approval['status'] ?? '',
			]);
		} catch (RuntimeException $e) {
			$message = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
			$this->logger->warning('OCS 处理文件分享审批决定失败：' . $message, ['exception' => $e, 'approvalId' => $id, 'decision' => $decision, 'user' => $user->getUID()]);

			if (strpos($message, '已处理') !== false) {
				return new DataResponse(['status' => 'ok', 'message' => $message, 'approvalStatus' => 'processed']);
			}

			$statusCode = strpos($message, '不是该申请的审批人') !== false ? 403 : 400;
			return new DataResponse(['status' => 'error', 'message' => $message], $statusCode);
		} catch (\Throwable $e) {
			$message = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
			$this->logger->error('OCS 处理文件分享审批决定异常：' . $message, ['exception' => $e, 'approvalId' => $id, 'decision' => $decision, 'user' => $user->getUID()]);
			return new DataResponse(['status' => 'error', 'message' => '处理审批决定失败：' . $message], 500);
		}
	}
}
