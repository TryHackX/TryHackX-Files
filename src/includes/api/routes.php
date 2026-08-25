<?php
/**
 * Declarative browser-API route table.
 *
 * Every action names its allowed methods and security policy. There is no method fallback:
 * adding a controller method without adding a complete descriptor leaves it unreachable.
 */

$read = static fn(array $handler, bool $auth = false, ?string $permission = null, ?string $rate = 'read'): array =>
	ApiRoutePolicy::route($handler, ['GET'], false, $auth, $permission, $rate);
$write = static fn(array $handler, bool $auth = false, ?string $permission = null, ?string $rate = 'write'): array =>
	ApiRoutePolicy::route($handler, ['POST'], true, $auth, $permission, $rate);
$capabilityGet = static fn(array $handler, bool $auth = false, ?string $permission = null, ?string $rate = 'read'): array =>
	ApiRoutePolicy::route($handler, ['GET'], false, $auth, $permission, $rate);
$externalPost = static fn(array $handler, ?string $rate = 'write'): array =>
	ApiRoutePolicy::route($handler, ['POST'], false, false, null, $rate);
$checkout = static fn(array $handler): array =>
	ApiRoutePolicy::route($handler, ['POST'], true, true, null, 'write', true, 303);

$routes = [
	// Upload rollback has no public action name; public/api.php selects it only for a
	// no-action DELETE. It still participates in the same method and CSRF policy.
	'__revert' => ApiRoutePolicy::route(
		[FileController::class, 'handleRevert'],
		['DELETE'],
		true,
		false,
		null,
		'write'
	),

	// ---- Files: streaming, capabilities, previews, info/stats/health ----
	// These three GETs are capability/resource reads by design. `download` and `embed` may
	// account a transfer, but cannot be converted to POST without breaking browser navigation
	// and <img> embedding.
	'download' => $capabilityGet([FileController::class, 'handleDownload'], false, null, null),
	'preview' => $capabilityGet([FileController::class, 'handlePreview']),
	'embed' => $capabilityGet([FileController::class, 'handleEmbed']),
	'get_download_token' => $write([FileController::class, 'handleGetDownloadToken']),
	'preview_auth' => $write([FileController::class, 'handlePreviewAuth']),
	'info' => $read([FileController::class, 'handleInfo']),
	'delete' => $write([FileController::class, 'handleDelete']),
	// GET renders a no-store form. POST is authorised by a one-time capability-bound nonce in
	// FileController, so this route intentionally does not use the session CSRF token.
	'delete_link' => ApiRoutePolicy::route(
		[FileController::class, 'handleDeleteLink'],
		['GET', 'POST'],
		false,
		false,
		null,
		'write'
	),
	'list' => $read([FileController::class, 'handleList'], true, 'files.view_all'),
	'file_facets' => $read([FileController::class, 'handleFileFacets'], true, 'files.advanced_filters'),
	'stats' => $read([FileController::class, 'handleStats']),
	'health' => $read([FileController::class, 'handleHealth'], false, null, null),
	'verify_captcha' => $write([FileController::class, 'handleVerifyCaptcha'], false, null, 'auth'),
	'verify_token' => $read([FileController::class, 'handleVerifyToken']),
	'upload_status' => $read([FileController::class, 'handleUploadStatus']),
	'token_info' => $read([FileController::class, 'handleTokenInfo']),
	'increment_token' => $write([FileController::class, 'handleIncrementToken']),

	// ---- A user's own files, options, collections, keys and webhooks ----
	'user_files' => $read([FileController::class, 'handleUserFiles'], true),
	'my_file_facets' => $read([FileController::class, 'handleMyFileFacets'], true),
	'user_delete_files' => $write([FileController::class, 'handleUserDeleteFiles'], true),
	'user_set_file_options' => $write([FileController::class, 'handleUserSetFileOptions'], true),
	'set_file_password' => $write([FileController::class, 'handleSetFilePassword']),
	'user_create_collection' => $write([FileController::class, 'handleUserCreateCollection'], true, 'myfiles.coll_create'),
	'check_files_protected' => $write([FileController::class, 'handleCheckFilesProtected'], true, 'files.collection_all'),
	'create_collection_from_all' => $write([FileController::class, 'handleCreateCollectionFromAll'], true, 'files.collection_all'),
	'user_collections' => $read([FileController::class, 'handleUserCollections'], true),
	'verify_collection_password' => $write([FileController::class, 'handleVerifyCollectionPassword']),
	'collection_zip_token' => $write([FileController::class, 'handleCollectionZipToken']),
	'user_rename_collection' => $write([FileController::class, 'handleUserRenameCollection'], true),
	'user_update_collection' => $write([FileController::class, 'handleUserUpdateCollection'], true),
	'user_collection_files' => $read([FileController::class, 'handleUserCollectionFiles'], true),
	'user_collection_remove_file' => $write([FileController::class, 'handleUserCollectionRemoveFile'], true),
	'user_collection_reorder' => $write([FileController::class, 'handleUserCollectionReorder'], true),
	'user_delete_collection' => $write([FileController::class, 'handleUserDeleteCollection'], true),
	'add_file_to_collection' => $write([FileController::class, 'handleAddFileToCollection'], true),
	'user_create_api_key' => $write([FileController::class, 'handleUserCreateApiKey'], true),
	'user_api_keys' => $read([FileController::class, 'handleUserApiKeys'], true),
	'user_revoke_api_key' => $write([FileController::class, 'handleUserRevokeApiKey'], true),
	'user_create_webhook' => $write([FileController::class, 'handleUserCreateWebhook'], true),
	'user_webhooks' => $read([FileController::class, 'handleUserWebhooks'], true),
	'user_delete_webhook' => $write([FileController::class, 'handleUserDeleteWebhook'], true),

	// ---- Auth / account / session ----
	'user_login' => $write([AuthController::class, 'handleUserLogin'], false, null, 'auth'),
	'user_register' => $write([AuthController::class, 'handleUserRegister'], false, null, 'auth'),
	'user_check' => $read([AuthController::class, 'handleUserCheck']),
	'user_logout' => $write([AuthController::class, 'handleUserLogout'], true),
	'config' => $read([AuthController::class, 'handleConfig']),
	'captcha_config' => $read([AuthController::class, 'handleConfig']),
	'check_username' => $read([AuthController::class, 'handleCheckUsername'], false, null, 'auth'),
	'check_email' => $read([AuthController::class, 'handleCheckEmail'], false, null, 'auth'),
	// E-mail links carry high-entropy, one-purpose capabilities and cannot attach a browser
	// CSRF header. Their token lifecycle is handled by the authentication repository.
	'verify_email' => $capabilityGet([AuthController::class, 'handleVerifyEmail'], false, null, 'auth'),
	'resend_verification' => $write([AuthController::class, 'handleResendVerification'], false, null, 'auth'),
	'user_stats' => $read([AuthController::class, 'handleUserStats'], true),
	'get_user_stats' => $read([AuthController::class, 'handleGetUserStats'], true),
	'user_change_password' => $write([AuthController::class, 'handleUserChangePassword'], true, null, 'auth'),
	'user_request_email_change' => $write([AuthController::class, 'handleUserRequestEmailChange'], true, null, 'auth'),
	'user_verify_email_change' => $capabilityGet([AuthController::class, 'handleUserVerifyEmailChange'], false, null, 'auth'),
	'user_delete_account' => $write([AuthController::class, 'handleUserDeleteAccount'], true, null, 'auth'),
	'recover_password' => $write([AuthController::class, 'handleRecoverPassword'], false, null, 'auth'),
	'reset_password_submit' => $write([AuthController::class, 'handleResetPasswordSubmit'], false, null, 'auth'),
	'user_2fa_status' => $read([AuthController::class, 'handleUser2faStatus'], true, null, 'auth'),
	'user_2fa_setup' => $write([AuthController::class, 'handleUser2faSetup'], true, null, 'auth'),
	'user_2fa_confirm' => $write([AuthController::class, 'handleUser2faConfirm'], true, null, 'auth'),
	'user_2fa_disable' => $write([AuthController::class, 'handleUser2faDisable'], true, null, 'auth'),
	'user_2fa_login' => $write([AuthController::class, 'handleUser2faLogin'], false, null, 'auth'),
	'user_2fa_recovery_codes' => $write([AuthController::class, 'handleUser2faRecoveryCodes'], true, null, 'auth'),
	'user_remember_devices' => $read([AuthController::class, 'handleUserRememberDevices'], true, null, 'auth'),
	'user_remember_revoke' => $write([AuthController::class, 'handleUserRememberRevoke'], true, null, 'auth'),
	'user_set_language' => $write([AuthController::class, 'handleUserSetLanguage'], true),

	// ---- Admin panel ----
	'admin_users' => $read([AdminController::class, 'handleAdminUsers'], true, 'admin'),
	'admin_create_user' => $write([AdminController::class, 'handleAdminCreateUser'], true, 'admin'),
	'admin_user_action' => $write([AdminController::class, 'handleAdminUserAction'], true, 'admin'),
	'admin_update_user' => $write([AdminController::class, 'handleAdminUpdateUser'], true, 'admin'),
	'admin_active_downloads' => $read([AdminController::class, 'handleAdminActiveDownloads'], true, 'admin'),
	'admin_kill_download' => $write([AdminController::class, 'handleAdminKillDownload'], true, 'admin'),
	'admin_kill_upload' => $write([AdminController::class, 'handleAdminKillUpload'], true, 'admin'),
	'admin_groups' => $read([AdminController::class, 'handleAdminGroups'], true, 'admin'),
	'admin_group_save' => $write([AdminController::class, 'handleAdminGroupSave'], true, 'admin'),
	'admin_group_delete' => $write([AdminController::class, 'handleAdminGroupDelete'], true, 'admin'),
	'admin_set_user_group' => $write([AdminController::class, 'handleAdminSetUserGroup'], true, 'admin'),
	'admin_dashboard' => $read([AdminController::class, 'handleAdminDashboard'], true, 'admin'),
	'admin_top_files' => $read([AdminController::class, 'handleAdminTopFiles'], true, 'admin'),
	'admin_traffic' => $read([AdminController::class, 'handleAdminTraffic'], true, 'moderation.traffic.view'),
	'admin_audit_log' => $read([AdminController::class, 'handleAdminAuditLog'], true, 'moderation.audit.view'),
	'admin_cleanup_preview' => $write([AdminController::class, 'handleAdminCleanupPreview'], true, 'admin'),
	'admin_cleanup_execute' => $write([AdminController::class, 'handleAdminCleanupExecute'], true, 'admin'),

	// ---- Premium / payments ----
	'premium_plans' => $read([PremiumController::class, 'handlePremiumPlans']),
	'premium_activate' => $externalPost([PremiumController::class, 'handlePremiumActivate']),
	'admin_plans' => $read([PremiumController::class, 'handleAdminPlans'], true, 'admin'),
	'admin_plan_save' => $write([PremiumController::class, 'handleAdminPlanSave'], true, 'admin'),
	'admin_plan_delete' => $write([PremiumController::class, 'handleAdminPlanDelete'], true, 'admin'),
	'admin_plan_grant' => $write([PremiumController::class, 'handleAdminPlanGrant'], true, 'premium.grants'),
	'admin_premium_settings' => $write([PremiumController::class, 'handleAdminPremiumSettings'], true, 'admin'),
	'admin_premium_token' => $write([PremiumController::class, 'handleAdminPremiumToken'], true, 'admin'),
	'admin_payment_plugins' => $read([PremiumController::class, 'handleAdminPaymentPlugins'], true, 'admin'),
	'admin_payment_plugin_save' => $write([PremiumController::class, 'handleAdminPaymentPluginSave'], true, 'admin'),
	'admin_payment_plugin_test' => $write([PremiumController::class, 'handleAdminPaymentPluginTest'], true, 'admin'),
	'premium_overview' => $read([PremiumController::class, 'handlePremiumOverview'], true, 'premium.metrics'),
	'premium_payments' => $read([PremiumController::class, 'handlePremiumPayments'], true, 'premium.payments'),
	'premium_subscribers' => $read([PremiumController::class, 'handlePremiumSubscribers'], true, 'premium.subscribers'),
	'premium_bulk_preview' => $write([PremiumController::class, 'handleBulkPlanPreview'], true, 'premium.bulk_grants'),
	'premium_bulk_execute' => $write([PremiumController::class, 'handleBulkPlanExecute'], true, 'premium.bulk_grants'),
	'admin_premium_refund' => $write([PremiumController::class, 'handleAdminPremiumRefund'], true, 'premium.refunds'),
	'promo_check' => $read([PremiumController::class, 'handlePromoCheck'], true),
	'invoice' => $read([PremiumController::class, 'handleInvoice'], true),
	'admin_promo_codes' => $read([PremiumController::class, 'handleAdminPromoCodes'], true, 'admin'),
	'admin_promo_code_save' => $write([PremiumController::class, 'handleAdminPromoCodeSave'], true, 'admin'),
	'admin_promo_code_delete' => $write([PremiumController::class, 'handleAdminPromoCodeDelete'], true, 'admin'),
	'my_premium' => $read([PremiumController::class, 'handleMyPremium'], true),
	'checkout' => $checkout([PaymentController::class, 'handleCheckout']),
	'payu_notify' => $externalPost([PaymentController::class, 'handlePayuNotify'], null),
	'p24_notify' => $externalPost([P24Controller::class, 'handleNotify'], null),
	'p24_refund_notify' => $externalPost([P24Controller::class, 'handleRefundNotify'], null),

	// ---- Advertising ----
	// Public tracking is deliberately not session-CSRF protected; the opaque active-creative
	// id and per-session damping are its authority. It is nevertheless method-locked to POST.
	'ad_track' => $externalPost([AdsController::class, 'handleAdTrack']),
	'ad_click' => $capabilityGet([AdsController::class, 'handleAdClick']),
	'ad_banner' => $capabilityGet([AdsController::class, 'handleAdBanner']),
	'ad_packages' => $read([AdsController::class, 'handleAdPackages'], true),
	'ad_checkout' => $checkout([AdsController::class, 'handleAdCheckout']),
	'my_ads' => $read([AdsController::class, 'handleMyAds'], true, 'ads.buy'),
	'my_ad_save' => $write([AdsController::class, 'handleMyAdSave'], true, 'ads.buy'),
	'my_ad_metrics' => $read([AdsController::class, 'handleMyAdMetrics'], true, 'ads.buy'),
	'my_ad_toggle' => $write([AdsController::class, 'handleMyAdToggle'], true, 'ads.buy'),
	'admin_ads' => $read([AdsController::class, 'handleAdminAds'], true),
	'admin_ad_save' => $write([AdsController::class, 'handleAdminAdSave'], true, 'ads.manage'),
	'admin_ad_delete' => $write([AdsController::class, 'handleAdminAdDelete'], true, 'ads.manage'),
	'admin_ad_action' => $write([AdsController::class, 'handleAdminAdAction'], true),
	'admin_ad_queue' => $read([AdsController::class, 'handleAdminAdQueue'], true, 'ads.approve'),
	// Buyers upload their own image creative through the same owner-scoped handler.
	'admin_ad_upload' => $write([AdsController::class, 'handleAdUpload'], true),
	'admin_ad_packages' => $read([AdsController::class, 'handleAdminAdPackages'], true),
	'admin_ad_package_save' => $write([AdsController::class, 'handleAdminAdPackageSave'], true, 'ads.packages'),
	'admin_ad_package_delete' => $write([AdsController::class, 'handleAdminAdPackageDelete'], true, 'ads.packages'),
	'admin_ads_settings' => $write([AdsController::class, 'handleAdminAdsSettings'], true, 'admin'),
	'admin_ads_stats' => $read([AdsController::class, 'handleAdminAdsStats'], true, 'ads.metrics'),

	// ---- Collections, language and bans (admin) ----
	'admin_collections' => $read([AdminController::class, 'handleAdminCollections'], true, 'admin'),
	'collection_facets' => $read([AdminController::class, 'handleCollectionFacets'], true, 'admin'),
	'admin_delete_collections' => $write([AdminController::class, 'handleAdminDeleteCollections'], true, 'admin'),
	'admin_doc' => $read([AdminController::class, 'handleAdminDoc'], true, 'admin'),
	'admin_languages' => $read([AdminController::class, 'handleAdminLanguages'], true, 'admin'),
	'admin_language_toggle' => $write([AdminController::class, 'handleAdminLanguageToggle'], true, 'admin'),
	'admin_language_upload' => $write([AdminController::class, 'handleAdminLanguageUpload'], true, 'admin'),
	'admin_language_duplicate' => $write([AdminController::class, 'handleAdminLanguageDuplicate'], true, 'admin'),
	'admin_language_delete' => $write([AdminController::class, 'handleAdminLanguageDelete'], true, 'admin'),
	'admin_language_export' => $read([AdminController::class, 'handleAdminLanguageExport'], true, 'admin'),
	'admin_ip_bans' => $read([AdminController::class, 'handleAdminIPBans'], true, 'admin'),
	'admin_ban_ip' => $write([AdminController::class, 'handleAdminBanIP'], true, 'admin'),
	'admin_unban_ip' => $write([AdminController::class, 'handleAdminUnbanIP'], true, 'admin'),

	// ---- Notifications ----
	'notifications' => $read([NotificationController::class, 'handleNotifications'], true),
	'notification_count' => $read([NotificationController::class, 'handleNotificationCount'], true),
	'notification_seen' => $write([NotificationController::class, 'handleNotificationSeen'], true),
	'notification_read' => $write([NotificationController::class, 'handleNotificationRead'], true),
	'notification_delete' => $write([NotificationController::class, 'handleNotificationDelete'], true),
	'notification_prefs' => $read([NotificationController::class, 'handleNotificationPrefs'], true),
	'notification_prefs_save' => $write([NotificationController::class, 'handleNotificationPrefsSave'], true),
	'admin_notification_defaults' => $read([NotificationController::class, 'handleAdminNotificationDefaults'], true, 'admin'),
	'admin_notification_defaults_save' => $write([NotificationController::class, 'handleAdminNotificationDefaultsSave'], true, 'admin'),
	'admin_notification_broadcast' => $write([NotificationController::class, 'handleAdminNotificationBroadcast'], true, 'admin'),

	// ---- Abuse reports ----
	'report_file' => $write([ReportController::class, 'handleReportFile']),
	'get_report_config' => $read([ReportController::class, 'handleGetReportConfig']),
	'get_reported_files' => $read([ReportController::class, 'handleGetReportedFiles'], true, 'moderation.reports.view'),
	'get_report_details' => $read([ReportController::class, 'handleGetReportDetails'], true, 'moderation.reports.view'),
	'reject_report' => $write([ReportController::class, 'handleRejectReport'], true, 'moderation.reports.resolve'),
	'delete_reported_file' => $write([ReportController::class, 'handleDeleteReportedFile'], true, 'moderation.files.delete'),
];

ApiRoutePolicy::assertTable($routes);
return $routes;
