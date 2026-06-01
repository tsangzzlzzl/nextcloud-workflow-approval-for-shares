<?php
/**
 * @copyright Copyright (c) 2021 Paweł Kuffel <pawel@kuffel.io>
 *
 * @author Paweł Kuffel <pawel@kuffel.io>
 *
 * @license GNU AGPL version 3 or any later version
 */
return [
	'routes' => [
		// Web notification menu / browser fallback routes. GET is kept because
		// some Nextcloud notification UIs open action links directly.
		['name' => 'approval#approve', 'url' => '/share-approval/{id}/approve', 'verb' => 'POST'],
		['name' => 'approval#approve', 'url' => '/share-approval/{id}/approve', 'verb' => 'GET'],
		['name' => 'approval#reject', 'url' => '/share-approval/{id}/reject', 'verb' => 'POST'],
		['name' => 'approval#reject', 'url' => '/share-approval/{id}/reject', 'verb' => 'GET'],

		// Dedicated WEB-action fallback for desktop/mobile clients. Native
		// clients may open WEB actions in a browser instead of executing HTTP
		// actions in the background, so return a small result page.
		['name' => 'approval#approveFromClient', 'url' => '/share-approval/{id}/client-approve', 'verb' => 'GET'],
		['name' => 'approval#rejectFromClient', 'url' => '/share-approval/{id}/client-reject', 'verb' => 'GET'],
		['name' => 'approval#downloadGrantForm', 'url' => '/share-approval/{id}/download-grant', 'verb' => 'GET'],
		['name' => 'approval#downloadGrantSubmit', 'url' => '/share-approval/{id}/download-grant', 'verb' => 'POST'],

		['name' => 'approval#searchFolders', 'url' => '/share-approval/folders', 'verb' => 'GET'],
		['name' => 'approval#searchUsers', 'url' => '/share-approval/users', 'verb' => 'GET'],
		['name' => 'approval#searchTags', 'url' => '/share-approval/tags', 'verb' => 'GET'],
	],
	'ocs' => [
		// OCS routes are provided for clients that execute notification actions
		// directly from the OCS notification payload. They are reachable at:
		// /ocs/v2.php/apps/webhooks/api/v1/share-approval/{id}/approve
		['name' => 'approval_api#approve', 'url' => '/api/v1/share-approval/{id}/approve', 'verb' => 'POST'],
		['name' => 'approval_api#approve', 'url' => '/api/v1/share-approval/{id}/approve', 'verb' => 'GET'],
		['name' => 'approval_api#reject', 'url' => '/api/v1/share-approval/{id}/reject', 'verb' => 'DELETE'],
		['name' => 'approval_api#reject', 'url' => '/api/v1/share-approval/{id}/reject', 'verb' => 'POST'],
		['name' => 'approval_api#reject', 'url' => '/api/v1/share-approval/{id}/reject', 'verb' => 'GET'],
	]
];
