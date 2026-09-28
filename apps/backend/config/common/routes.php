<?php

declare(strict_types=1);

use App\Api;
use App\Core\Account\Api\CreateAccountAction;
use App\Core\Account\Api\AccountLifecycleAction;
use App\Core\Account\Api\GetAccountAction;
use App\Core\Account\Api\ListAccountsAction;
use App\Core\AccountSettings\Api\GetAccountSettingsAction;
use App\Core\AccountSettings\Api\PatchAccountSettingsAction;
use App\Core\Consent\Api\ListOwnConsentsAction;
use App\Core\Consent\Api\GrantOwnConsentAction;
use App\Core\Consent\Api\WithdrawOwnConsentAction;
use App\Core\Presence\Api\HeartbeatAction;
use App\Core\Privacy\Api\ManageRepresentationAction;
use App\Core\Privacy\Api\OwnPrivacyRequestsAction;
use App\Core\Privacy\Api\ManagePrivacyRequestsAction;
use App\Core\Privacy\Api\ManageProcessingBlocksAction;
use App\Core\Auth\Api\ListSessionsAction;
use App\Core\Auth\Api\ActivationAction;
use App\Core\Auth\Api\ConfirmPasswordResetAction;
use App\Core\Auth\Api\ChangeOwnPasswordAction;
use App\Core\Auth\Api\CompleteRequiredPasswordChangeAction;
use App\Core\Auth\Api\ExternalIdentitiesAction;
use App\Core\Auth\Api\ExternalIdentityLinkCallbackAction;
use App\Core\Auth\Api\ExternalIdentityLinkStartAction;
use App\Core\Auth\Api\ExternalIdentityUnlinkAction;
use App\Core\Auth\Api\ExternalLoginCallbackAction;
use App\Core\Auth\Api\ExternalLoginStartAction;
use App\Core\Auth\Api\InviteAccountAction;
use App\Core\Auth\Api\LoginAction;
use App\Core\Auth\Api\LogoutAction;
use App\Core\Auth\Api\MeAction;
use App\Core\Auth\Api\MfaStatusAction;
use App\Core\Auth\Api\MfaVerifyAction;
use App\Core\Auth\Api\MfaRecoveryConfirmAction;
use App\Core\Auth\Api\MfaRecoveryExternalCallbackAction;
use App\Core\Auth\Api\MfaRecoveryExternalStartAction;
use App\Core\Auth\Api\MfaRecoveryRequestAction;
use App\Core\Auth\Api\RefreshAction;
use App\Core\Auth\Api\ProvidersAction;
use App\Core\Auth\Api\RecoveryCodesRegenerateAction;
use App\Core\Auth\Api\RequestPasswordResetAction;
use App\Core\Auth\Api\RevokeOtherSessionsAction;
use App\Core\Auth\Api\RevokeSessionAction;
use App\Core\Auth\Api\UpdatePreferencesAction;
use App\Core\Auth\Api\TotpConfirmAction;
use App\Core\Auth\Api\TotpDisableAction;
use App\Core\Auth\Api\TotpSetupAction;
use App\Core\Auth\Api\PasskeyRegistrationStartAction;
use App\Core\Auth\Api\PasskeyRegistrationCompleteAction;
use App\Core\Auth\Api\PasskeyAuthenticationStartAction;
use App\Core\Auth\Api\PasskeyAuthenticationCompleteAction;
use App\Core\Auth\Api\PasskeyListAction;
use App\Core\Auth\Api\PasskeyRenameAction;
use App\Core\Auth\Api\PasskeyRevokeAction;
use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Core\Module\Api\GetModuleAction;
use App\Core\Module\Api\ListModulesAction;
use App\Core\Module\Api\ModuleLifecycleAction;
use App\Core\Person\Api\ArchivePersonAction;
use App\Core\Person\Api\CreatePersonAction;
use App\Core\Person\Api\CreatePersonContactAction;
use App\Core\Person\Api\GetPersonAction;
use App\Core\Person\Api\ListPersonContactsAction;
use App\Core\Person\Api\ListPersonsAction;
use App\Core\Person\Api\UpdatePersonAction;
use App\Core\Rbac\Api\ListPermissionsAction;
use App\Core\Rbac\Api\AccountRolesAction;
use App\Core\Rbac\Api\ListRolesAction;
use App\Core\Rbac\Api\CustomRoleAction;
use App\Core\Rbac\Api\RolePermissionsAction;
use App\Core\Rbac\Infrastructure\RequirePermission;
use App\Core\Settings\Api\FeatureFlagsAction;
use App\Core\Settings\Api\ModuleSettingsAction;
use App\Core\Settings\Api\PublicConfigAction;
use App\Core\Settings\Api\SystemSettingsAction;
use Yiisoft\Router\Route;

$permission = static fn(string $code): array => [
    'class' => RequirePermission::class,
    '__construct()' => ['permission' => $code],
];

return [
    Route::get('/')
        ->action(Api\IndexAction::class)
        ->name('app/index'),

    Route::get('/health')
        ->action(Api\HealthAction::class)
        ->name('app/health'),

    Route::get('/api/public/config')
        ->action(PublicConfigAction::class)
        ->name('public/config'),

    Route::post('/api/auth/login')
        ->action(LoginAction::class)
        ->name('auth/login'),

    Route::post('/api/auth/password/change-required')
        ->action(CompleteRequiredPasswordChangeAction::class)
        ->name('auth/password/change-required'),

    Route::post('/api/auth/passkeys/authentication/start')
        ->action(PasskeyAuthenticationStartAction::class)
        ->name('auth/passkeys/authentication/start'),

    Route::post('/api/auth/passkeys/authentication/complete')
        ->action(PasskeyAuthenticationCompleteAction::class)
        ->name('auth/passkeys/authentication/complete'),

    Route::post('/api/auth/passkeys/registration/start')
        ->middleware(RequireAuthentication::class)
        ->action(PasskeyRegistrationStartAction::class)
        ->name('auth/passkeys/registration/start'),

    Route::post('/api/auth/passkeys/registration/complete')
        ->middleware(RequireAuthentication::class)
        ->action(PasskeyRegistrationCompleteAction::class)
        ->name('auth/passkeys/registration/complete'),

    Route::get('/api/auth/providers')
        ->action(ProvidersAction::class)
        ->name('auth/providers'),

    Route::post('/api/auth/external/{provider}/start')
        ->action(ExternalLoginStartAction::class)
        ->name('auth/external/start'),

    Route::get('/api/auth/external/{provider}/callback')
        ->action(ExternalLoginCallbackAction::class)
        ->name('auth/external/callback'),

    Route::post('/api/auth/refresh')
        ->action(RefreshAction::class)
        ->name('auth/refresh'),

    Route::post('/api/auth/mfa/verify')
        ->action(MfaVerifyAction::class)
        ->name('auth/mfa/verify'),

    Route::post('/api/auth/mfa/recovery/request')
        ->action(MfaRecoveryRequestAction::class)
        ->name('auth/mfa/recovery/request'),

    Route::post('/api/auth/mfa/recovery/confirm')
        ->action(MfaRecoveryConfirmAction::class)
        ->name('auth/mfa/recovery/confirm'),

    Route::post('/api/auth/mfa/recovery/external/{provider}/start')
        ->action(MfaRecoveryExternalStartAction::class)
        ->name('auth/mfa/recovery/external/start'),

    Route::get('/api/auth/mfa/recovery/external/{provider}/callback')
        ->action(MfaRecoveryExternalCallbackAction::class)
        ->name('auth/mfa/recovery/external/callback'),

    Route::post('/api/auth/activate')
        ->action(ActivationAction::class)
        ->name('auth/activate'),

    Route::post('/api/auth/password-reset/request')
        ->action(RequestPasswordResetAction::class)
        ->name('auth/password-reset/request'),

    Route::post('/api/auth/password-reset/confirm')
        ->action(ConfirmPasswordResetAction::class)
        ->name('auth/password-reset/confirm'),

    Route::post('/api/auth/logout')
        ->middleware(RequireAuthentication::class)
        ->action(LogoutAction::class)
        ->name('auth/logout'),

    Route::get('/api/auth/me')
        ->middleware(RequireAuthentication::class)
        ->action(MeAction::class)
        ->name('auth/me'),

    Route::patch('/api/auth/me/preferences')
        ->middleware(RequireAuthentication::class)
        ->action(UpdatePreferencesAction::class)
        ->name('auth/me/preferences'),

    Route::post('/api/auth/me/password/change')
        ->middleware(RequireAuthentication::class)
        ->action(ChangeOwnPasswordAction::class)
        ->name('auth/me/password/change'),

    Route::get('/api/auth/me/settings')
        ->middleware(RequireAuthentication::class)
        ->action(GetAccountSettingsAction::class)
        ->name('auth/me/settings/get'),

    Route::patch('/api/auth/me/settings')
        ->middleware(RequireAuthentication::class)
        ->action(PatchAccountSettingsAction::class)
        ->name('auth/me/settings/patch'),

    Route::get('/api/auth/me/consents')
        ->middleware(RequireAuthentication::class)
        ->action(ListOwnConsentsAction::class)
        ->name('auth/me/consents/index'),

    Route::post('/api/auth/me/consents/{code}')
        ->middleware(RequireAuthentication::class)
        ->action(GrantOwnConsentAction::class)
        ->name('auth/me/consents/grant'),

    Route::delete('/api/auth/me/consents/{code}')
        ->middleware(RequireAuthentication::class)
        ->action(WithdrawOwnConsentAction::class)
        ->name('auth/me/consents/withdraw'),

    Route::post('/api/core/privacy/representations')
        ->defaults(['operation' => 'declare'])
        ->middleware(RequireAuthentication::class, $permission('core.privacy.representations.manage'))
        ->action(ManageRepresentationAction::class)
        ->name('core/privacy/representations/declare'),

    Route::post('/api/core/privacy/representations/{id}/verify')
        ->defaults(['operation' => 'verify'])
        ->middleware(RequireAuthentication::class, $permission('core.privacy.representations.manage'))
        ->action(ManageRepresentationAction::class)
        ->name('core/privacy/representations/verify'),

    Route::post('/api/core/privacy/representations/{id}/revoke')
        ->defaults(['operation' => 'revoke'])
        ->middleware(RequireAuthentication::class, $permission('core.privacy.representations.manage'))
        ->action(ManageRepresentationAction::class)
        ->name('core/privacy/representations/revoke'),

    Route::post('/api/auth/me/privacy/requests')
        ->defaults(['operation' => 'submit'])
        ->middleware(RequireAuthentication::class)
        ->action(OwnPrivacyRequestsAction::class)
        ->name('auth/me/privacy/requests/submit'),

    Route::get('/api/auth/me/privacy/requests')
        ->defaults(['operation' => 'list'])
        ->middleware(RequireAuthentication::class)
        ->action(OwnPrivacyRequestsAction::class)
        ->name('auth/me/privacy/requests/list'),

    Route::get('/api/auth/me/privacy/requests/{id}')
        ->defaults(['operation' => 'get'])
        ->middleware(RequireAuthentication::class)
        ->action(OwnPrivacyRequestsAction::class)
        ->name('auth/me/privacy/requests/get'),

    Route::post('/api/auth/me/privacy/representations/{representationId}/requests')
        ->defaults(['operation' => 'submit_representative'])
        ->middleware(RequireAuthentication::class)
        ->action(OwnPrivacyRequestsAction::class)
        ->name('auth/me/privacy/representations/requests/submit'),

    Route::get('/api/core/privacy/requests')
        ->defaults(['operation' => 'list'])
        ->middleware(RequireAuthentication::class, $permission('core.privacy.requests.read'))
        ->action(ManagePrivacyRequestsAction::class)
        ->name('core/privacy/requests/list'),

    Route::get('/api/core/privacy/requests/{id}')
        ->defaults(['operation' => 'get'])
        ->middleware(RequireAuthentication::class, $permission('core.privacy.requests.read'))
        ->action(ManagePrivacyRequestsAction::class)
        ->name('core/privacy/requests/get'),

    Route::post('/api/core/privacy/requests/{id}/transition')
        ->defaults(['operation' => 'transition'])
        ->middleware(RequireAuthentication::class, $permission('core.privacy.requests.manage'))
        ->action(ManagePrivacyRequestsAction::class)
        ->name('core/privacy/requests/transition'),

    Route::post('/api/core/privacy/requests/{id}/deadlines/{deadlineCode}/extend')
        ->defaults(['operation' => 'extend'])
        ->middleware(RequireAuthentication::class, $permission('core.privacy.requests.manage'))
        ->action(ManagePrivacyRequestsAction::class)
        ->name('core/privacy/requests/deadlines/extend'),

    Route::post('/api/core/privacy/requests/{id}/blocks')
        ->defaults(['operation' => 'create'])
        ->middleware(RequireAuthentication::class, $permission('core.privacy.requests.manage'))
        ->action(ManageProcessingBlocksAction::class)
        ->name('core/privacy/requests/blocks/create'),

    Route::get('/api/core/privacy/requests/{id}/blocks')
        ->defaults(['operation' => 'list'])
        ->middleware(RequireAuthentication::class, $permission('core.privacy.requests.read'))
        ->action(ManageProcessingBlocksAction::class)
        ->name('core/privacy/requests/blocks/list'),

    Route::post('/api/core/privacy/blocks/{id}/release')
        ->defaults(['operation' => 'release'])
        ->middleware(RequireAuthentication::class, $permission('core.privacy.requests.manage'))
        ->action(ManageProcessingBlocksAction::class)
        ->name('core/privacy/blocks/release'),

    Route::post('/api/auth/me/presence/heartbeat')
        ->middleware(RequireAuthentication::class)
        ->action(HeartbeatAction::class)
        ->name('auth/me/presence/heartbeat'),

    Route::get('/api/auth/me/identities')
        ->middleware(RequireAuthentication::class)
        ->action(ExternalIdentitiesAction::class)
        ->name('auth/me/identities'),

    Route::post('/api/auth/me/identities/{provider}/link/start')
        ->middleware(RequireAuthentication::class)
        ->action(ExternalIdentityLinkStartAction::class)
        ->name('auth/me/identities/link/start'),

    Route::get('/api/auth/me/identities/{provider}/link/callback')
        ->middleware(RequireAuthentication::class)
        ->action(ExternalIdentityLinkCallbackAction::class)
        ->name('auth/me/identities/link/callback'),

    Route::delete('/api/auth/me/identities/{provider}')
        ->middleware(RequireAuthentication::class)
        ->action(ExternalIdentityUnlinkAction::class)
        ->name('auth/me/identities/unlink'),

    Route::get('/api/auth/me/passkeys')
        ->middleware(RequireAuthentication::class)
        ->action(PasskeyListAction::class)
        ->name('auth/me/passkeys/index'),

    Route::patch('/api/auth/me/passkeys/{id}')
        ->middleware(RequireAuthentication::class)
        ->action(PasskeyRenameAction::class)
        ->name('auth/me/passkeys/rename'),

    Route::delete('/api/auth/me/passkeys/{id}')
        ->middleware(RequireAuthentication::class)
        ->action(PasskeyRevokeAction::class)
        ->name('auth/me/passkeys/revoke'),

    Route::get('/api/auth/mfa')
        ->middleware(RequireAuthentication::class)
        ->action(MfaStatusAction::class)
        ->name('auth/mfa/status'),

    Route::post('/api/auth/mfa/totp/setup')
        ->middleware(RequireAuthentication::class)
        ->action(TotpSetupAction::class)
        ->name('auth/mfa/totp/setup'),

    Route::post('/api/auth/mfa/totp/confirm')
        ->middleware(RequireAuthentication::class)
        ->action(TotpConfirmAction::class)
        ->name('auth/mfa/totp/confirm'),

    Route::delete('/api/auth/mfa/totp')
        ->middleware(RequireAuthentication::class)
        ->action(TotpDisableAction::class)
        ->name('auth/mfa/totp/disable'),

    Route::post('/api/auth/mfa/recovery-codes/regenerate')
        ->middleware(RequireAuthentication::class)
        ->action(RecoveryCodesRegenerateAction::class)
        ->name('auth/mfa/recovery-codes/regenerate'),

    Route::get('/api/auth/sessions')
        ->middleware(RequireAuthentication::class)
        ->action(ListSessionsAction::class)
        ->name('auth/sessions/index'),

    Route::delete('/api/auth/sessions/{id}')
        ->middleware(RequireAuthentication::class)
        ->action(RevokeSessionAction::class)
        ->name('auth/sessions/revoke'),

    Route::post('/api/auth/sessions/revoke-others')
        ->middleware(RequireAuthentication::class)
        ->action(RevokeOtherSessionsAction::class)
        ->name('auth/sessions/revoke-others'),

    Route::get('/api/core/persons')
        ->middleware(RequireAuthentication::class, $permission('core.persons.read'))
        ->action(ListPersonsAction::class)
        ->name('core/persons/index'),

    Route::post('/api/core/persons')
        ->middleware(RequireAuthentication::class, $permission('core.persons.manage'))
        ->action(CreatePersonAction::class)
        ->name('core/persons/create'),

    Route::get('/api/core/persons/{id}')
        ->middleware(RequireAuthentication::class, $permission('core.persons.read'))
        ->action(GetPersonAction::class)
        ->name('core/persons/view'),

    Route::patch('/api/core/persons/{id}')
        ->middleware(RequireAuthentication::class, $permission('core.persons.manage'))
        ->action(UpdatePersonAction::class)
        ->name('core/persons/update'),

    Route::post('/api/core/persons/{id}/archive')
        ->middleware(RequireAuthentication::class, $permission('core.persons.manage'))
        ->action(ArchivePersonAction::class)
        ->name('core/persons/archive'),

    Route::get('/api/core/persons/{id}/contacts')
        ->middleware(RequireAuthentication::class, $permission('core.persons.read'))
        ->action(ListPersonContactsAction::class)
        ->name('core/person-contacts/index'),

    Route::post('/api/core/persons/{id}/contacts')
        ->middleware(RequireAuthentication::class, $permission('core.persons.manage'))
        ->action(CreatePersonContactAction::class)
        ->name('core/person-contacts/create'),

    Route::get('/api/core/accounts')
        ->middleware(RequireAuthentication::class, $permission('core.accounts.read'))
        ->action(ListAccountsAction::class)
        ->name('core/accounts/index'),

    Route::post('/api/core/accounts')
        ->middleware(RequireAuthentication::class, $permission('core.accounts.manage'))
        ->action(CreateAccountAction::class)
        ->name('core/accounts/create'),

    Route::get('/api/core/accounts/{id}')
        ->middleware(RequireAuthentication::class, $permission('core.accounts.read'))
        ->action(GetAccountAction::class)
        ->name('core/accounts/view'),

    Route::post('/api/core/accounts/{id}/approve')
        ->defaults(['operation' => 'approve'])
        ->middleware(RequireAuthentication::class, $permission('core.accounts.manage'))
        ->action(AccountLifecycleAction::class)
        ->name('core/accounts/approve'),

    Route::post('/api/core/accounts/{id}/disable')
        ->defaults(['operation' => 'disable'])
        ->middleware(RequireAuthentication::class, $permission('core.accounts.manage'))
        ->action(AccountLifecycleAction::class)
        ->name('core/accounts/disable'),

    Route::post('/api/core/accounts/{id}/enable')
        ->defaults(['operation' => 'enable'])
        ->middleware(RequireAuthentication::class, $permission('core.accounts.manage'))
        ->action(AccountLifecycleAction::class)
        ->name('core/accounts/enable'),

    Route::post('/api/core/accounts/{id}/archive')
        ->defaults(['operation' => 'archive'])
        ->middleware(RequireAuthentication::class, $permission('core.accounts.manage'))
        ->action(AccountLifecycleAction::class)
        ->name('core/accounts/archive'),

    Route::post('/api/core/accounts/{id}/invite')
        ->middleware(RequireAuthentication::class, $permission('core.accounts.manage'))
        ->action(InviteAccountAction::class)
        ->name('core/accounts/invite'),

    Route::get('/api/core/accounts/{id}/roles')
        ->middleware(RequireAuthentication::class, $permission('core.rbac.manage'))
        ->action(AccountRolesAction::class)
        ->name('core/account-roles/index'),

    Route::post('/api/core/accounts/{id}/roles/{roleId}')
        ->middleware(RequireAuthentication::class, $permission('core.rbac.manage'))
        ->action(AccountRolesAction::class)
        ->name('core/account-roles/assign'),

    Route::delete('/api/core/accounts/{id}/roles/{roleId}')
        ->middleware(RequireAuthentication::class, $permission('core.rbac.manage'))
        ->action(AccountRolesAction::class)
        ->name('core/account-roles/revoke'),

    Route::get('/api/core/roles')
        ->middleware(RequireAuthentication::class, $permission('core.rbac.read'))
        ->action(ListRolesAction::class)
        ->name('core/roles/index'),

    Route::post('/api/core/roles')
        ->middleware(RequireAuthentication::class, $permission('core.rbac.manage'))
        ->action(CustomRoleAction::class)
        ->name('core/roles/create'),

    Route::patch('/api/core/roles/{id}')
        ->middleware(RequireAuthentication::class, $permission('core.rbac.manage'))
        ->action(CustomRoleAction::class)
        ->name('core/roles/update'),

    Route::delete('/api/core/roles/{id}')
        ->middleware(RequireAuthentication::class, $permission('core.rbac.manage'))
        ->action(CustomRoleAction::class)
        ->name('core/roles/delete'),

    Route::get('/api/core/roles/{id}/permissions')
        ->middleware(RequireAuthentication::class, $permission('core.rbac.manage'))
        ->action(RolePermissionsAction::class)
        ->name('core/roles/permissions/get'),

    Route::put('/api/core/roles/{id}/permissions')
        ->middleware(RequireAuthentication::class, $permission('core.rbac.manage'))
        ->action(RolePermissionsAction::class)
        ->name('core/roles/permissions/put'),

    Route::get('/api/core/permissions')
        ->middleware(RequireAuthentication::class, $permission('core.rbac.read'))
        ->action(ListPermissionsAction::class)
        ->name('core/permissions/index'),

    Route::get('/api/core/modules')
        ->middleware(RequireAuthentication::class, $permission('core.modules.read'))
        ->action(ListModulesAction::class)
        ->name('core/modules/index'),

    Route::get('/api/core/modules/{code}')
        ->middleware(RequireAuthentication::class, $permission('core.modules.read'))
        ->action(GetModuleAction::class)
        ->name('core/modules/view'),

    Route::post('/api/core/modules/{code}/enable')
        ->defaults(['operation' => 'enable'])
        ->middleware(RequireAuthentication::class, $permission('core.modules.manage'))
        ->action(ModuleLifecycleAction::class)
        ->name('core/modules/enable'),

    Route::post('/api/core/modules/{code}/disable')
        ->defaults(['operation' => 'disable'])
        ->middleware(RequireAuthentication::class, $permission('core.modules.manage'))
        ->action(ModuleLifecycleAction::class)
        ->name('core/modules/disable'),

    Route::get('/api/core/settings/system')
        ->defaults(['operation' => 'list'])
        ->middleware(RequireAuthentication::class, $permission('core.settings.read'))
        ->action(SystemSettingsAction::class)
        ->name('core/system-settings/index'),

    Route::get('/api/core/settings/system/{key}')
        ->defaults(['operation' => 'get'])
        ->middleware(RequireAuthentication::class, $permission('core.settings.read'))
        ->action(SystemSettingsAction::class)
        ->name('core/system-settings/view'),

    Route::put('/api/core/settings/system/{key}')
        ->defaults(['operation' => 'put'])
        ->middleware(RequireAuthentication::class, $permission('core.settings.manage'))
        ->action(SystemSettingsAction::class)
        ->name('core/system-settings/upsert'),

    Route::delete('/api/core/settings/system/{key}')
        ->defaults(['operation' => 'delete'])
        ->middleware(RequireAuthentication::class, $permission('core.settings.manage'))
        ->action(SystemSettingsAction::class)
        ->name('core/system-settings/delete'),

    Route::get('/api/core/modules/{moduleCode}/settings')
        ->defaults(['operation' => 'list'])
        ->middleware(RequireAuthentication::class, $permission('core.settings.read'))
        ->action(ModuleSettingsAction::class)
        ->name('core/module-settings/index'),

    Route::get('/api/core/modules/{moduleCode}/settings/{key}')
        ->defaults(['operation' => 'get'])
        ->middleware(RequireAuthentication::class, $permission('core.settings.read'))
        ->action(ModuleSettingsAction::class)
        ->name('core/module-settings/view'),

    Route::put('/api/core/modules/{moduleCode}/settings/{key}')
        ->defaults(['operation' => 'put'])
        ->middleware(RequireAuthentication::class, $permission('core.settings.manage'))
        ->action(ModuleSettingsAction::class)
        ->name('core/module-settings/upsert'),

    Route::delete('/api/core/modules/{moduleCode}/settings/{key}')
        ->defaults(['operation' => 'delete'])
        ->middleware(RequireAuthentication::class, $permission('core.settings.manage'))
        ->action(ModuleSettingsAction::class)
        ->name('core/module-settings/delete'),

    Route::get('/api/core/feature-flags')
        ->defaults(['operation' => 'list'])
        ->middleware(RequireAuthentication::class, $permission('core.feature_flags.read'))
        ->action(FeatureFlagsAction::class)
        ->name('core/feature-flags/index'),

    Route::get('/api/core/feature-flags/{code}')
        ->defaults(['operation' => 'get'])
        ->middleware(RequireAuthentication::class, $permission('core.feature_flags.read'))
        ->action(FeatureFlagsAction::class)
        ->name('core/feature-flags/view'),

    Route::put('/api/core/feature-flags/{code}')
        ->defaults(['operation' => 'put'])
        ->middleware(RequireAuthentication::class, $permission('core.feature_flags.manage'))
        ->action(FeatureFlagsAction::class)
        ->name('core/feature-flags/upsert'),

    Route::delete('/api/core/feature-flags/{code}')
        ->defaults(['operation' => 'delete'])
        ->middleware(RequireAuthentication::class, $permission('core.feature_flags.manage'))
        ->action(FeatureFlagsAction::class)
        ->name('core/feature-flags/delete'),
];
