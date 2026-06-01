<?php
/** @var array $_ */
style('webhooks', 'style');
$title = (string)($_['title'] ?? '文件分享审批');
$message = (string)($_['message'] ?? '审批操作已完成。');
$status = (string)($_['status'] ?? 'info');
$symbol = '✓';
$iconClass = '';
if ($status === 'success') {
	$symbol = '✓';
	$iconClass = 'success';
} elseif ($status === 'error') {
	$symbol = '!';
	$iconClass = 'error';
} elseif ($status === 'warning') {
	$symbol = '!';
	$iconClass = 'warning';
}
?>
<div class="webhooks-page-shell webhooks-approval-result">
	<div class="webhooks-approval-card">
		<div class="webhooks-approval-card-header">
			<div class="webhooks-approval-icon <?php p($iconClass); ?>"><?php p($symbol); ?></div>
			<div>
				<h2 class="webhooks-approval-title"><?php p($title); ?></h2>
				<p class="webhooks-approval-message"><?php p($message); ?></p>
			</div>
		</div>
		<div class="webhooks-approval-actions">
			<a href="<?php p(\OC::$server->getURLGenerator()->linkToDefaultPageUrl()); ?>" class="primary">返回 Nextcloud</a>
		</div>
	</div>
</div>
