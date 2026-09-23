<?php

declare(strict_types=1);

use OpenWiki\Http\Controllers\AccountPasswordController;
use OpenWiki\Http\Controllers\AccountSessionController;
use OpenWiki\Http\Controllers\Api\ApiV1Controller;
use OpenWiki\Http\Controllers\ApiTokenController;
use OpenWiki\Http\Controllers\AttachmentController;
use OpenWiki\Http\Controllers\BackupController;
use OpenWiki\Http\Controllers\AuthController;
use OpenWiki\Http\Controllers\CommentController;
use OpenWiki\Http\Controllers\DashboardController;
use OpenWiki\Http\Controllers\DirectoryAdminController;
use OpenWiki\Http\Controllers\HealthController;
use OpenWiki\Http\Controllers\InstallerController;
use OpenWiki\Http\Controllers\ImportExportController;
use OpenWiki\Http\Controllers\LdapAdminController;
use OpenWiki\Http\Controllers\MfaAdminController;
use OpenWiki\Http\Controllers\MfaController;
use OpenWiki\Http\Controllers\NotificationController;
use OpenWiki\Http\Controllers\PageAclController;
use OpenWiki\Http\Controllers\PageController;
use OpenWiki\Http\Controllers\PageDraftController;
use OpenWiki\Http\Controllers\PageEngagementController;
use OpenWiki\Http\Controllers\SearchController;
use OpenWiki\Http\Controllers\SpaceController;
use OpenWiki\Http\Controllers\SystemAdminController;
use OpenWiki\Http\Controllers\TemplateAdminController;
use OpenWiki\Http\Controllers\TrashController;
use OpenWiki\Http\Controllers\WikiMetadataController;
use OpenWiki\Http\Controllers\WebhookAdminController;

$router = $app->router();

$router->get('/install', [InstallerController::class, 'index']);
$router->post('/install', [InstallerController::class, 'store']);
$router->get('/health', [HealthController::class, 'show']);

$router->get('/login', [AuthController::class, 'loginForm']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/mfa/challenge', [MfaController::class, 'challenge']);
$router->post('/mfa/challenge', [MfaController::class, 'verify']);
$router->get('/account/mfa/setup', [MfaController::class, 'setup']);
$router->post('/account/mfa/confirm', [MfaController::class, 'confirm']);

$router->get('/', [DashboardController::class, 'index']);
$router->get('/search', [SearchController::class, 'index']);

$router->get('/spaces/create', [SpaceController::class, 'create']);
$router->post('/spaces', [SpaceController::class, 'store']);
$router->get('/spaces/{spaceKey}', [SpaceController::class, 'show']);

$router->get('/spaces/{spaceKey}/pages/create', [PageController::class, 'create']);
$router->post('/spaces/{spaceKey}/pages', [PageController::class, 'store']);
$router->get('/spaces/{spaceKey}/pages/{slug}', [PageController::class, 'show']);
$router->get('/spaces/{spaceKey}/pages/{slug}/edit', [PageController::class, 'edit']);
$router->post('/spaces/{spaceKey}/pages/{slug}', [PageController::class, 'update']);
$router->get('/spaces/{spaceKey}/pages/{slug}/history', [PageController::class, 'history']);
$router->post('/spaces/{spaceKey}/pages/{slug}/restore', [PageController::class, 'restore']);
$router->get('/spaces/{spaceKey}/pages/{slug}/export/{format}', [ImportExportController::class, 'exportPage']);
$router->get('/spaces/{spaceKey}/export', [ImportExportController::class, 'exportSpace']);
$router->get('/spaces/{spaceKey}/import', [ImportExportController::class, 'importForm']);
$router->post('/spaces/{spaceKey}/import', [ImportExportController::class, 'import']);
$router->get('/spaces/{spaceKey}/pages/{slug}/permissions', [PageAclController::class, 'index']);
$router->post('/spaces/{spaceKey}/pages/{slug}/permissions/inheritance', [PageAclController::class, 'inheritance']);
$router->post('/spaces/{spaceKey}/pages/{slug}/permissions/rules', [PageAclController::class, 'addRule']);
$router->post('/spaces/{spaceKey}/pages/{slug}/permissions/rules/{id}/delete', [PageAclController::class, 'deleteRule']);

$router->post('/spaces/{spaceKey}/pages/{slug}/edit-lock', [PageDraftController::class, 'lock']);
$router->post('/spaces/{spaceKey}/pages/{slug}/edit-unlock', [PageDraftController::class, 'unlock']);
$router->post('/spaces/{spaceKey}/pages/{slug}/autosave', [PageDraftController::class, 'autosave']);

$router->post('/spaces/{spaceKey}/watch', [PageEngagementController::class, 'watchSpace']);
$router->post('/spaces/{spaceKey}/pages/{slug}/favorite', [PageEngagementController::class, 'favorite']);
$router->post('/spaces/{spaceKey}/pages/{slug}/watch', [PageEngagementController::class, 'watch']);
$router->get('/notifications', [NotificationController::class, 'index']);
$router->post('/notifications/read-all', [NotificationController::class, 'readAll']);
$router->post('/notifications/{id}/read', [NotificationController::class, 'read']);

$router->get('/account/api-tokens', [ApiTokenController::class, 'index']);
$router->post('/account/api-tokens', [ApiTokenController::class, 'create']);
$router->post('/account/api-tokens/{id}/revoke', [ApiTokenController::class, 'revoke']);

$router->get('/api/v1/spaces', [ApiV1Controller::class, 'spaces']);
$router->post('/api/v1/spaces', [ApiV1Controller::class, 'createSpace']);
$router->get('/api/v1/spaces/{id}', [ApiV1Controller::class, 'space']);
$router->put('/api/v1/spaces/{id}', [ApiV1Controller::class, 'updateSpace']);
$router->patch('/api/v1/spaces/{id}', [ApiV1Controller::class, 'updateSpace']);
$router->delete('/api/v1/spaces/{id}', [ApiV1Controller::class, 'deleteSpace']);
$router->get('/api/v1/pages', [ApiV1Controller::class, 'pages']);
$router->post('/api/v1/pages', [ApiV1Controller::class, 'createPage']);
$router->get('/api/v1/pages/{id}', [ApiV1Controller::class, 'page']);
$router->put('/api/v1/pages/{id}', [ApiV1Controller::class, 'updatePage']);
$router->patch('/api/v1/pages/{id}', [ApiV1Controller::class, 'updatePage']);
$router->delete('/api/v1/pages/{id}', [ApiV1Controller::class, 'deletePage']);
$router->get('/api/v1/comments', [ApiV1Controller::class, 'comments']);
$router->post('/api/v1/comments', [ApiV1Controller::class, 'createComment']);
$router->put('/api/v1/comments/{id}', [ApiV1Controller::class, 'updateComment']);
$router->patch('/api/v1/comments/{id}', [ApiV1Controller::class, 'updateComment']);
$router->delete('/api/v1/comments/{id}', [ApiV1Controller::class, 'deleteComment']);
$router->get('/api/v1/search', [ApiV1Controller::class, 'search']);
$router->get('/api/v1/users', [ApiV1Controller::class, 'users']);
$router->get('/api/v1/users/{id}', [ApiV1Controller::class, 'user']);
$router->post('/api/v1/users', [ApiV1Controller::class, 'createUser']);
$router->put('/api/v1/users/{id}', [ApiV1Controller::class, 'updateUser']);
$router->patch('/api/v1/users/{id}', [ApiV1Controller::class, 'updateUser']);
$router->delete('/api/v1/users/{id}', [ApiV1Controller::class, 'deleteUser']);
$router->get('/api/v1/groups', [ApiV1Controller::class, 'groups']);
$router->get('/api/v1/groups/{id}', [ApiV1Controller::class, 'group']);
$router->get('/api/v1/roles', [ApiV1Controller::class, 'roles']);
$router->get('/api/v1/roles/{id}', [ApiV1Controller::class, 'role']);
$router->post('/api/v1/roles', [ApiV1Controller::class, 'createRole']);
$router->put('/api/v1/roles/{id}', [ApiV1Controller::class, 'updateRole']);
$router->patch('/api/v1/roles/{id}', [ApiV1Controller::class, 'updateRole']);
$router->delete('/api/v1/roles/{id}', [ApiV1Controller::class, 'deleteRole']);
$router->get('/api/v1/permissions', [ApiV1Controller::class, 'permissions']);
$router->post('/api/v1/groups', [ApiV1Controller::class, 'createGroup']);
$router->put('/api/v1/groups/{id}', [ApiV1Controller::class, 'updateGroup']);
$router->patch('/api/v1/groups/{id}', [ApiV1Controller::class, 'updateGroup']);
$router->delete('/api/v1/groups/{id}', [ApiV1Controller::class, 'deleteGroup']);
$router->get('/api/v1/tags', [ApiV1Controller::class, 'tags']);
$router->get('/api/v1/tags/{id}', [ApiV1Controller::class, 'tag']);
$router->get('/api/v1/templates', [ApiV1Controller::class, 'templates']);
$router->post('/api/v1/templates', [ApiV1Controller::class, 'createTemplate']);
$router->get('/api/v1/templates/{id}', [ApiV1Controller::class, 'template']);
$router->put('/api/v1/templates/{id}', [ApiV1Controller::class, 'updateTemplate']);
$router->patch('/api/v1/templates/{id}', [ApiV1Controller::class, 'updateTemplate']);
$router->delete('/api/v1/templates/{id}', [ApiV1Controller::class, 'deleteTemplate']);

$router->get('/api/v1/webhooks', [ApiV1Controller::class, 'webhooks']);
$router->post('/api/v1/webhooks', [ApiV1Controller::class, 'createWebhook']);
$router->get('/api/v1/webhooks/{id}', [ApiV1Controller::class, 'webhook']);
$router->put('/api/v1/webhooks/{id}', [ApiV1Controller::class, 'updateWebhook']);
$router->patch('/api/v1/webhooks/{id}', [ApiV1Controller::class, 'updateWebhook']);
$router->delete('/api/v1/webhooks/{id}', [ApiV1Controller::class, 'deleteWebhook']);
$router->get('/api/v1/webhooks/{id}/deliveries', [ApiV1Controller::class, 'webhookDeliveries']);

$router->get('/api/v1/attachments', [ApiV1Controller::class, 'attachments']);
$router->get('/api/v1/attachments/{id}', [ApiV1Controller::class, 'attachment']);
$router->patch('/api/v1/attachments/{id}', [ApiV1Controller::class, 'renameAttachment']);
$router->get('/api/v1/attachments/{id}/preview', [ApiV1Controller::class, 'previewAttachment']);
$router->get('/api/v1/attachments/{id}/thumbnail', [ApiV1Controller::class, 'thumbnailAttachment']);
$router->get('/api/v1/attachments/{id}/download', [ApiV1Controller::class, 'downloadAttachment']);
$router->get('/api/v1/attachments/{id}/versions/{version}/download', [ApiV1Controller::class, 'downloadAttachmentVersion']);
$router->post('/api/v1/attachments', [ApiV1Controller::class, 'uploadAttachment']);
$router->post('/api/v1/attachments/{id}/version', [ApiV1Controller::class, 'uploadAttachmentVersion']);
$router->delete('/api/v1/attachments/{id}', [ApiV1Controller::class, 'deleteAttachment']);

$router->post('/spaces/{spaceKey}/pages/{slug}/comments', [CommentController::class, 'create']);
$router->post('/spaces/{spaceKey}/pages/{slug}/comments/{id}/edit', [CommentController::class, 'update']);
$router->post('/spaces/{spaceKey}/pages/{slug}/comments/{id}/delete', [CommentController::class, 'delete']);

$router->post('/spaces/{spaceKey}/pages/{slug}/attachments', [AttachmentController::class, 'upload']);
$router->post('/spaces/{spaceKey}/pages/{slug}/images', [AttachmentController::class, 'imageUpload']);
$router->post('/spaces/{spaceKey}/pages/{slug}/attachments/{id}/version', [AttachmentController::class, 'version']);
$router->post('/spaces/{spaceKey}/pages/{slug}/attachments/{id}/rename', [AttachmentController::class, 'rename']);
$router->post('/spaces/{spaceKey}/pages/{slug}/attachments/{id}/delete', [AttachmentController::class, 'delete']);
$router->get('/attachments/{id}/download', [AttachmentController::class, 'download']);
$router->get('/attachments/{id}/preview', [AttachmentController::class, 'preview']);
$router->get('/attachments/{id}/thumbnail', [AttachmentController::class, 'thumbnail']);
$router->get('/attachments/{id}/versions/{version}/download', [AttachmentController::class, 'downloadVersion']);

$router->get('/spaces/{spaceKey}/wiki/{reference}', [WikiMetadataController::class, 'resolve']);
$router->get('/tags/{slug}', [WikiMetadataController::class, 'tag']);
$router->get('/admin/broken-links', [WikiMetadataController::class, 'brokenLinks']);

$router->get('/trash', [TrashController::class, 'index']);
$router->post('/spaces/{spaceKey}/pages/{slug}/delete', [TrashController::class, 'delete']);
$router->post('/trash/{id}/restore', [TrashController::class, 'restore']);
$router->post('/trash/{id}/delete', [TrashController::class, 'destroy']);

$router->get('/account/change-password', [AccountPasswordController::class, 'edit']);
$router->post('/account/change-password', [AccountPasswordController::class, 'update']);
$router->get('/account/sessions', [AccountSessionController::class, 'index']);
$router->post('/account/sessions/logout-all', [AccountSessionController::class, 'revokeAll']);
$router->post('/account/sessions/{fingerprint}/revoke', [AccountSessionController::class, 'revoke']);

$router->get('/admin', [DirectoryAdminController::class, 'dashboard']);
$router->get('/admin/backups', [BackupController::class, 'index']);
$router->post('/admin/backups', [BackupController::class, 'create']);
$router->get('/admin/backups/{name}/download', [BackupController::class, 'download']);

$router->get('/admin/users', [DirectoryAdminController::class, 'users']);
$router->get('/admin/users/create', [DirectoryAdminController::class, 'createUserForm']);
$router->post('/admin/users', [DirectoryAdminController::class, 'createUser']);
$router->get('/admin/users/{id}/edit', [DirectoryAdminController::class, 'editUser']);
$router->post('/admin/users/{id}', [DirectoryAdminController::class, 'updateUser']);
$router->post('/admin/users/{id}/reset-password', [DirectoryAdminController::class, 'resetPassword']);
$router->post('/admin/users/{id}/reset-mfa', [DirectoryAdminController::class, 'resetMfa']);
$router->post('/admin/users/{id}/delete', [DirectoryAdminController::class, 'deleteUser']);

$router->get('/admin/groups', [DirectoryAdminController::class, 'groups']);
$router->get('/admin/groups/create', [DirectoryAdminController::class, 'groupForm']);
$router->post('/admin/groups', [DirectoryAdminController::class, 'saveGroup']);
$router->get('/admin/groups/{id}/edit', [DirectoryAdminController::class, 'groupForm']);
$router->post('/admin/groups/{id}', [DirectoryAdminController::class, 'saveGroup']);
$router->post('/admin/groups/{id}/delete', [DirectoryAdminController::class, 'deleteGroup']);

$router->get('/admin/roles', [DirectoryAdminController::class, 'roles']);
$router->get('/admin/roles/create', [DirectoryAdminController::class, 'roleForm']);
$router->post('/admin/roles', [DirectoryAdminController::class, 'saveRole']);
$router->get('/admin/roles/{id}/edit', [DirectoryAdminController::class, 'roleForm']);
$router->post('/admin/roles/{id}', [DirectoryAdminController::class, 'saveRole']);
$router->post('/admin/roles/{id}/delete', [DirectoryAdminController::class, 'deleteRole']);

$router->get('/admin/webhooks', [WebhookAdminController::class, 'index']);
$router->post('/admin/webhooks', [WebhookAdminController::class, 'create']);
$router->post('/admin/webhooks/{id}/status', [WebhookAdminController::class, 'status']);
$router->post('/admin/webhooks/{id}/delete', [WebhookAdminController::class, 'delete']);

$router->get('/admin/mfa', [MfaAdminController::class, 'index']);
$router->post('/admin/mfa', [MfaAdminController::class, 'update']);

$router->get('/admin/ldap', [LdapAdminController::class, 'index']);
$router->post('/admin/ldap', [LdapAdminController::class, 'save']);
$router->post('/admin/ldap/test', [LdapAdminController::class, 'test']);

$router->get('/admin/system', [SystemAdminController::class, 'index']);

$router->get('/admin/templates', [TemplateAdminController::class, 'index']);
$router->get('/admin/templates/create', [TemplateAdminController::class, 'create']);
$router->post('/admin/templates', [TemplateAdminController::class, 'save']);
$router->get('/admin/templates/{id}/edit', [TemplateAdminController::class, 'edit']);
$router->post('/admin/templates/{id}', [TemplateAdminController::class, 'save']);
$router->post('/admin/templates/{id}/delete', [TemplateAdminController::class, 'delete']);
