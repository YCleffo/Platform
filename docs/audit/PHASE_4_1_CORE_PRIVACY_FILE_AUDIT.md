# Phase 4.1 Core privacy file audit

Baseline: `feature/symfony-commercial-core`, `612805c3389ac017ae9965f1c67c77ac74e5c713`, clean before branch creation. Initial inventory read the complete contents of 534 files (42,253 lines) in the requested paths. This second pass re-read the complete contents of every path below after implementation. `Full-text scan` records a per-file structural and privacy-term inspection; `manual + full-text scan` records targeted semantic review or authoring as well. The distinction is intentional.

Findings: consent was Account-only and had no subject/purpose/authority; AuditService had no common payload policy; existing auth cleanup is bounded; AccountSettings is a preference store; Presence has a TTL visibility gate; old migrations remain untouched. Baseline full regression had three order-sensitive API failures (399 tests, 2 errors, 1 failure), and each failed test passed in isolation before changes.

| Path | Reviewed | Changed / unchanged | Privacy impact | Reason |
| --- | --- | --- | --- | --- |
| `.env.example` | full-text scan | unchanged | audit,privacy,retention,external,outbox,setting | Existing repository or deployment contract retained. |
| `.github/workflows/ci.yml` | full-text scan | changed | consent,account,audit,privacy,outbox,migration,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `README.md` | full-text scan | changed | consent,person,account,audit,privacy,retention,erasure,external,outbox,inbox,setting,presence,permission,migration,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/config/common/aliases.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/application.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/di/application.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/di/auth.php` | full-text scan | unchanged | external | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/di/client.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/di/communication.php` | full-text scan | unchanged | outbox | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/di/core.php` | manual + full-text scan | changed | consent,person,account,audit,privacy,retention,external,outbox,inbox,setting,permission,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/config/common/di/db-pgsql.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/di/error-handler.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/di/geo.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/di/hydrator.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/di/internationalization.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/di/logger.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/di/router.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/di/time.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/params.php` | full-text scan | unchanged | audit,retention,external,outbox | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/common/routes.php` | manual + full-text scan | changed | consent,person,account,privacy,external,setting,presence,permission | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/config/console/commands.php` | full-text scan | unchanged | outbox | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/console/params.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/environments/test/params.php` | full-text scan | changed | consent,person,privacy,retention | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/config/web/di/application.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/web/di/error-handler.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/web/di/psr17.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/config/web/params.php` | full-text scan | unchanged | indirect | Existing configuration retained; new definitions are wired separately. |
| `apps/backend/migrations/down/017_core_account_settings.sql` | manual + full-text scan | unchanged | account,setting | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/down/018_core_consent_runtime.sql` | manual + full-text scan | unchanged | consent,account | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/down/019_core_privacy_foundation.sql` | manual + full-text scan | new | consent,person,account,privacy,retention | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/migrations/down/020_core_privacy_invariants.sql` | manual + full-text scan | new | consent,privacy,permission | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/migrations/sql/001_core_schema_and_types.sql` | manual + full-text scan | unchanged | person,account,audit,outbox,inbox,setting,schema | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/002_core_people.sql` | manual + full-text scan | unchanged | person,account | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/003_core_accounts_auth.sql` | manual + full-text scan | unchanged | person,account,external,claims | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/004_core_rbac_modules.sql` | manual + full-text scan | unchanged | account,setting,permission,schema | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/005_core_settings.sql` | manual + full-text scan | unchanged | account,setting | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/006_core_audit_events.sql` | manual + full-text scan | unchanged | account,audit,outbox,inbox | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/007_core_indexes.sql` | manual + full-text scan | unchanged | person,account,audit,external,outbox,inbox,setting,permission | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/008_core_comments.sql` | manual + full-text scan | unchanged | person,account,audit,external,claims,outbox,inbox,setting,permission,schema | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/009_core_seed.sql` | manual + full-text scan | unchanged | permission | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/010_core_international_security.sql` | manual + full-text scan | unchanged | account | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/011_core_auth_runtime.sql` | manual + full-text scan | unchanged | person,account,permission | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/012_core_configuration_runtime.sql` | manual + full-text scan | unchanged | setting,permission | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/013_external_identity_mfa_runtime.sql` | manual + full-text scan | unchanged | account,external | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/014_passkey_runtime.sql` | manual + full-text scan | unchanged | migration | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/015_core_data_hygiene.sql` | manual + full-text scan | unchanged | person,account,setting,permission,schema | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/016_platform_runtime.sql` | manual + full-text scan | unchanged | schema | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/017_core_account_settings.sql` | manual + full-text scan | unchanged | person,account,setting,schema | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/018_core_consent_runtime.sql` | manual + full-text scan | unchanged | consent,account | Historical migration preserved; Phase 4.1 is append-only. |
| `apps/backend/migrations/sql/019_core_privacy_foundation.sql` | manual + full-text scan | new | consent,person,account,privacy,retention,erasure,migration | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/migrations/sql/020_core_privacy_invariants.sql` | manual + full-text scan | new | consent,person,account,privacy,erasure,permission | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Api/HealthAction.php` | manual + full-text scan | changed | consent,account,privacy,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Api/IndexAction.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Api/Shared/ApiMessage.php` | full-text scan | unchanged | person,account,external,setting,permission | Existing endpoint or command contract retained. |
| `apps/backend/src/Api/Shared/ExceptionResponderFactory.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Api/Shared/LocalizedResponseMiddleware.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Api/Shared/NotFoundMiddleware.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Api/Shared/OffsetPagination.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Api/Shared/Presenter/AsIsPresenter.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Api/Shared/Presenter/CollectionPresenter.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Api/Shared/Presenter/OffsetPaginatorPresenter.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Api/Shared/Presenter/PresenterInterface.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Api/Shared/Presenter/SuccessPresenter.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Api/Shared/RequestSizeLimitMiddleware.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Api/Shared/ResponseFactory.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Api/Shared/SecurityHeadersMiddleware.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Api/Shared/Uuid.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Console/AuthCleanupCommand.php` | full-text scan | unchanged | indirect | Existing endpoint or command contract retained. |
| `apps/backend/src/Console/BootstrapSuperadminCommand.php` | full-text scan | unchanged | person,account,audit | Existing endpoint or command contract retained. |
| `apps/backend/src/Console/DbCheckCommand.php` | manual + full-text scan | changed | consent,account,privacy,external,migration,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Console/ProcessEmailOutboxCommand.php` | full-text scan | unchanged | outbox | Existing endpoint or command contract retained. |
| `apps/backend/src/Core/Account/Api/AccountInput.php` | full-text scan | unchanged | person,account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Api/AccountLifecycleAction.php` | full-text scan | unchanged | account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Api/CreateAccountAction.php` | full-text scan | unchanged | person,account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Api/GetAccountAction.php` | full-text scan | unchanged | account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Api/ListAccountsAction.php` | full-text scan | unchanged | account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/AccountInternationalPreferences.php` | full-text scan | unchanged | account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/AccountLifecycleChange.php` | full-text scan | unchanged | account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/AccountLifecycleRepository.php` | full-text scan | unchanged | account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/AccountPage.php` | full-text scan | unchanged | account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/AccountPreferencesRepository.php` | full-text scan | unchanged | account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/AccountRepository.php` | full-text scan | unchanged | account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/AccountSecurityRepository.php` | full-text scan | unchanged | account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/AccountSecurityState.php` | full-text scan | unchanged | person,account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/AccountView.php` | full-text scan | unchanged | person,account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/CreateAccount.php` | full-text scan | unchanged | person,account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/CreateAccountCommand.php` | full-text scan | unchanged | person,account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/GetAccount.php` | full-text scan | unchanged | account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/ListAccounts.php` | full-text scan | unchanged | account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/ManageAccountLifecycle.php` | full-text scan | unchanged | account,audit | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Application/UpdateAccountPreferences.php` | full-text scan | unchanged | account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Infrastructure/PostgresAccountLifecycleRepository.php` | full-text scan | unchanged | person,account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Infrastructure/PostgresAccountPreferencesRepository.php` | full-text scan | unchanged | account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Infrastructure/PostgresAccountRepository.php` | full-text scan | unchanged | person,account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Account/Infrastructure/PostgresAccountSecurityRepository.php` | full-text scan | unchanged | person,account | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/AccountSettings/Api/GetAccountSettingsAction.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/AccountSettings/Api/PatchAccountSettingsAction.php` | full-text scan | unchanged | account,setting,schema | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/AccountSettings/Application/AccountSettingDefinition.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/AccountSettings/Application/AccountSettings.php` | full-text scan | unchanged | account,setting,schema | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/AccountSettings/Application/AccountSettingsRepository.php` | full-text scan | unchanged | account,setting,schema | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/AccountSettings/Application/AccountSettingsService.php` | full-text scan | unchanged | account,audit,setting,schema | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/AccountSettings/Application/SettingsSchemaRegistry.php` | manual + full-text scan | changed | account,privacy,setting,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/AccountSettings/Application/SettingsValidationException.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/AccountSettings/Application/StoredAccountSettings.php` | full-text scan | unchanged | account,setting,schema | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/AccountSettings/Infrastructure/PostgresAccountSettingsRepository.php` | full-text scan | unchanged | account,setting,schema | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Audit/Application/AuditEventReceipt.php` | manual + full-text scan | unchanged | audit | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Audit/Application/AuditPayloadPolicy.php` | manual + full-text scan | new | audit,privacy | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Audit/Application/AuditService.php` | manual + full-text scan | changed | audit | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Audit/Application/AuditWriter.php` | manual + full-text scan | unchanged | audit | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Audit/Application/WriteAuditEventCommand.php` | manual + full-text scan | unchanged | account,audit | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Audit/Infrastructure/PostgresAuditWriter.php` | manual + full-text scan | changed | account,audit | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Auth/Api/ActivationAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/AuthInput.php` | full-text scan | unchanged | external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/ChangeOwnPasswordAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/CompleteRequiredPasswordChangeAction.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/ConfirmPasswordResetAction.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/ExternalIdentitiesAction.php` | full-text scan | unchanged | account,external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/ExternalIdentityLinkCallbackAction.php` | full-text scan | unchanged | account,external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/ExternalIdentityLinkStartAction.php` | full-text scan | unchanged | account,external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/ExternalIdentityUnlinkAction.php` | full-text scan | unchanged | account,external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/ExternalLoginCallbackAction.php` | full-text scan | unchanged | external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/ExternalLoginStartAction.php` | full-text scan | unchanged | external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/InviteAccountAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/ListSessionsAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/LoginAction.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/LogoutAction.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/MeAction.php` | full-text scan | unchanged | person,account,permission | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/MfaRecoveryConfirmAction.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/MfaRecoveryExternalCallbackAction.php` | full-text scan | unchanged | external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/MfaRecoveryExternalStartAction.php` | full-text scan | unchanged | external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/MfaRecoveryRequestAction.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/MfaStatusAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/MfaVerifyAction.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/PasskeyAuthenticationCompleteAction.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/PasskeyAuthenticationStartAction.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/PasskeyListAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/PasskeyRegistrationCompleteAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/PasskeyRegistrationStartAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/PasskeyRenameAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/PasskeyRevokeAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/ProvidersAction.php` | full-text scan | unchanged | external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/RecoveryCodesRegenerateAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/RefreshAction.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/RequestPasswordResetAction.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/RevokeOtherSessionsAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/RevokeSessionAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/SESSIONS.md` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/TotpConfirmAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/TotpDisableAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/TotpSetupAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Api/UpdatePreferencesAction.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AccountAuthenticationMethodsPolicy.php` | full-text scan | unchanged | account,external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AccountSecurityNotifier.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthChallenge.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthChallengeRepository.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthCleanupPolicy.php` | full-text scan | unchanged | retention | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthCleanupReport.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthCleanupRepository.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthIdentifier.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthIdentifierNormalizer.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthLinkBuilder.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthProvider.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthProviderRepository.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthRateLimitPolicy.php` | full-text scan | unchanged | external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthRuntimePolicy.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthSession.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthenticatedIdentity.php` | full-text scan | unchanged | person,account,permission | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthenticationFlowResult.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthenticationFlowService.php` | full-text scan | unchanged | account,audit | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthenticationMethod.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthenticationPayloadCipher.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthenticationResult.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/AuthenticationService.php` | full-text scan | unchanged | person,account,permission | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/Base32.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/ChangePassword.php` | full-text scan | unchanged | account,audit | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/CompleteAccountActivation.php` | full-text scan | unchanged | account,audit | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/CompletePasswordReset.php` | full-text scan | unchanged | account,audit | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/CreateAuthChallengeCommand.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/CreateSessionCommand.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/CreateTotpFactorCommand.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/CredentialLifecycleRepository.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/CredentialMutationGuard.php` | full-text scan | unchanged | account,external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/CredentialTarget.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/EncryptedAuthenticationPayload.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/EncryptedTotpSecret.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/ExternalAuthService.php` | full-text scan | unchanged | account,audit,external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/ExternalAuthenticationException.php` | full-text scan | unchanged | external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/ExternalAuthorizationStart.php` | full-text scan | unchanged | external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/ExternalIdentity.php` | full-text scan | unchanged | account,external,claims | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/ExternalIdentityLastMethodException.php` | full-text scan | unchanged | external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/ExternalIdentityProfile.php` | full-text scan | unchanged | external,claims | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/ExternalIdentityRepository.php` | full-text scan | unchanged | account,external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/IdentifierRepository.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/InvalidCredentialsException.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/InviteAccount.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/IssuedTokenPair.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/LastAuthenticationMethodException.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/ListOwnSessions.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/Login.php` | full-text scan | unchanged | account,audit | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/LoginCommand.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/LoginRateLimitedException.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/Logout.php` | full-text scan | unchanged | account,audit,presence | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/MfaManagementException.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/MfaRecoveryConfirmationResult.php` | full-text scan | unchanged | external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/MfaRecoveryFinalizer.php` | full-text scan | unchanged | account,audit | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/MfaRecoveryService.php` | full-text scan | unchanged | account,audit,external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/MfaVerificationException.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/MfaVerificationService.php` | full-text scan | unchanged | account,audit | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/OidcClientInterface.php` | full-text scan | unchanged | external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/OidcClientRegistry.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/OidcClientRegistryInterface.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/OidcProviderConfiguration.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/OpaqueToken.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/OpaqueTokenService.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/OwnSessionView.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/Passkey.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/PasskeyRateLimitedException.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/PasskeyRepository.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/PasskeyService.php` | full-text scan | unchanged | account,audit,presence | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/PasswordChangeRejectedException.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/PasswordCredential.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/PasswordCredentialRepository.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/PasswordHasherInterface.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/RecentAuthenticationPolicy.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/RecentAuthenticationRequiredException.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/RecoveryCode.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/RecoveryCodeRepository.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/RecoveryCodeService.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/RefreshSession.php` | full-text scan | unchanged | account,audit | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/RequestPasswordReset.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/RevokeOtherSessions.php` | full-text scan | unchanged | account,audit | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/RevokeOwnSession.php` | full-text scan | unchanged | account,audit | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/RotateSessionTokensCommand.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/SessionActivityResult.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/SessionActivityService.php` | full-text scan | unchanged | account,audit | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/SessionIssuer.php` | full-text scan | unchanged | account,audit | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/SessionRepository.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/TotpFactor.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/TotpFactorRepository.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/TotpManager.php` | full-text scan | unchanged | account,audit | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/TotpProvisioningPolicy.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/TotpSecretCipher.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/TotpService.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/TouchSessionCommand.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Application/WebAuthnPolicy.php` | full-text scan | unchanged | account,privacy | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Infrastructure/GoogleOidcClient.php` | full-text scan | unchanged | account,external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Infrastructure/NativePasswordHasher.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Infrastructure/PostgresAuthCleanupRepository.php` | full-text scan | unchanged | retention | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Infrastructure/PostgresAuthRepository.php` | full-text scan | unchanged | account,external,claims | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Infrastructure/PostgresCredentialLifecycleRepository.php` | full-text scan | unchanged | account | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Infrastructure/RequireAuthentication.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Infrastructure/SecureOidcClient.php` | full-text scan | unchanged | external,claims | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Infrastructure/SodiumAuthenticationPayloadCipher.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Infrastructure/SodiumTotpSecretCipher.php` | full-text scan | unchanged | indirect | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Auth/Infrastructure/TelegramOidcClient.php` | full-text scan | unchanged | external | Auth lifecycle retained; subject consent is added beside it. |
| `apps/backend/src/Core/Client/Application/ClientContextResolver.php` | full-text scan | unchanged | account | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Client/Application/DeviceDetectorInterface.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Client/Application/FingerprintService.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Client/Application/IpAddressResolver.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Client/Application/RequestIdFactory.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Client/Application/TrustedProxies.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Client/Domain/ClientContext.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Client/Domain/ClientHints.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Client/Domain/DeviceInfo.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Client/Infrastructure/ClientContextMiddleware.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Client/Infrastructure/MatomoDeviceDetector.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Communication/Api/README.md` | full-text scan | unchanged | indirect | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Application/EmailEventPayloadException.php` | full-text scan | unchanged | indirect | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Application/EmailEventType.php` | full-text scan | unchanged | account | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Application/EmailOutboxPolicy.php` | full-text scan | unchanged | outbox | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Application/EmailOutboxProcessor.php` | full-text scan | unchanged | outbox | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Application/EmailProcessingReport.php` | full-text scan | unchanged | indirect | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Application/EmailRequest.php` | full-text scan | unchanged | outbox,schema | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Application/EmailSenderInterface.php` | full-text scan | unchanged | indirect | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Application/EmailService.php` | full-text scan | unchanged | outbox,schema | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Application/QueueEmailCommand.php` | full-text scan | unchanged | indirect | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Application/TemplateRendererInterface.php` | full-text scan | unchanged | indirect | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Application/TemplateRenderingException.php` | full-text scan | unchanged | indirect | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Domain/EmailDeliveryResult.php` | full-text scan | unchanged | indirect | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Domain/EmailMessage.php` | full-text scan | unchanged | indirect | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Domain/EmailTemplate.php` | full-text scan | unchanged | indirect | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Infrastructure/FileEmailTemplateRenderer.php` | full-text scan | unchanged | indirect | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Infrastructure/SmtpConfiguration.php` | full-text scan | unchanged | indirect | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Communication/Infrastructure/SmtpEmailSender.php` | full-text scan | unchanged | indirect | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Consent/Api/GrantOwnConsentAction.php` | manual + full-text scan | changed | consent,account | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Consent/Api/ListOwnConsentsAction.php` | manual + full-text scan | changed | consent,account | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Consent/Api/WithdrawOwnConsentAction.php` | manual + full-text scan | unchanged | consent,account | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Consent/Application/ConsentDefinition.php` | manual + full-text scan | changed | consent,retention | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Consent/Application/ConsentDefinitionRegistry.php` | manual + full-text scan | changed | consent,privacy,retention | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Consent/Application/ConsentService.php` | manual + full-text scan | changed | consent,person,account,audit,privacy | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Consent/Application/UnknownConsentException.php` | manual + full-text scan | new | consent | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Geo/Application/GeoIpProviderInterface.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Geo/Application/GeoIpService.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Geo/Domain/GeoLocation.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Geo/Infrastructure/MaxMindGeoIpProvider.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Geo/Infrastructure/UnknownGeoIpProvider.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Internationalization/Application/LocaleContext.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Internationalization/Application/LocaleNormalizer.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Internationalization/Application/LocaleResolver.php` | full-text scan | unchanged | account | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Internationalization/Application/ResolvedLocale.php` | full-text scan | unchanged | account | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Internationalization/Application/SupportedLocales.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Internationalization/Application/TranslationService.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Internationalization/Domain/Locale.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Messaging/Application/InboxConsumerGuard.php` | full-text scan | unchanged | inbox | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Messaging/Application/InboxEvent.php` | full-text scan | unchanged | inbox | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Messaging/Application/InboxRepository.php` | full-text scan | unchanged | inbox | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Messaging/Application/OutboxEvent.php` | full-text scan | unchanged | outbox | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Messaging/Application/OutboxEventCommand.php` | full-text scan | unchanged | outbox | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Messaging/Application/OutboxPublisher.php` | full-text scan | unchanged | outbox | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Messaging/Application/OutboxRepository.php` | full-text scan | unchanged | outbox | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Messaging/Infrastructure/PostgresInboxRepository.php` | full-text scan | unchanged | inbox | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Messaging/Infrastructure/PostgresOutboxRepository.php` | full-text scan | unchanged | outbox | Delivery contract retained; payload cleanup belongs to the retention pass. |
| `apps/backend/src/Core/Module/Api/GetModuleAction.php` | full-text scan | unchanged | setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Module/Api/ListModulesAction.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Module/Api/ModuleLifecycleAction.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Module/Application/ModuleRepository.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Module/Application/ModuleService.php` | full-text scan | unchanged | account,audit | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Module/Application/ModuleStateChange.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Module/Application/ModuleView.php` | full-text scan | unchanged | setting,schema | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Module/Infrastructure/PostgresModuleRepository.php` | full-text scan | unchanged | setting,schema | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Network/IpRangeMatcher.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Notification/Application/NotificationPreferenceResolver.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Person/Api/ArchivePersonAction.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Api/CreatePersonAction.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Api/CreatePersonContactAction.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Api/GetPersonAction.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Api/ListPersonContactsAction.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Api/ListPersonsAction.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Api/PersonInput.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Api/UpdatePersonAction.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/ArchivePerson.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/ContactNormalizer.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/CreatePerson.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/CreatePersonCommand.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/CreatePersonContact.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/CreatePersonContactCommand.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/GetPerson.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/ListPersonContacts.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/ListPersons.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/PersonContactRepository.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/PersonContactView.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/PersonPage.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/PersonRepository.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/PersonView.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/UpdatePerson.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Application/UpdatePersonCommand.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Infrastructure/CanonicalContactNormalizer.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Infrastructure/PostgresPersonContactRepository.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Person/Infrastructure/PostgresPersonRepository.php` | full-text scan | unchanged | person | Person and Account lifecycle remain separate; no direct change needed. |
| `apps/backend/src/Core/Presence/Api/HeartbeatAction.php` | full-text scan | unchanged | account,presence | Redis TTL and visibility gate retained; shared default changed in Settings registry. |
| `apps/backend/src/Core/Presence/Application/PresenceService.php` | full-text scan | unchanged | account,presence | Redis TTL and visibility gate retained; shared default changed in Settings registry. |
| `apps/backend/src/Core/Presence/Application/PresenceVisibilityPolicy.php` | full-text scan | unchanged | account,privacy,setting,presence | Redis TTL and visibility gate retained; shared default changed in Settings registry. |
| `apps/backend/src/Core/Privacy/Api/ManageRepresentationAction.php` | manual + full-text scan | new | person,privacy | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Privacy/Application/DataCategoryRegistry.php` | manual + full-text scan | new | privacy | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Privacy/Application/LegalRepresentationService.php` | manual + full-text scan | new | person,account,audit,privacy,permission | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Privacy/Application/ProcessingPurposeRegistry.php` | manual + full-text scan | new | privacy,retention | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Privacy/Application/RepresentativeAuthorityPolicy.php` | manual + full-text scan | new | consent,person,privacy | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Privacy/Application/RetentionPolicyRegistry.php` | manual + full-text scan | new | privacy,retention | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Privacy/Domain/DataCategoryDefinition.php` | manual + full-text scan | new | privacy | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Privacy/Domain/MachineCode.php` | manual + full-text scan | new | privacy | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Privacy/Domain/ProcessingPurposeDefinition.php` | manual + full-text scan | new | consent,privacy,retention | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Privacy/Domain/RetentionPolicyDefinition.php` | manual + full-text scan | new | privacy,retention | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Privacy/Infrastructure/PostgresRepresentativeAuthorityPolicy.php` | manual + full-text scan | new | consent,person,privacy | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/src/Core/Rbac/Api/AccountRolesAction.php` | full-text scan | unchanged | account | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Api/CustomRoleAction.php` | full-text scan | unchanged | account | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Api/ListPermissionsAction.php` | full-text scan | unchanged | permission | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Api/ListRolesAction.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Api/RolePermissionsAction.php` | full-text scan | unchanged | account,permission | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Application/ListPermissions.php` | full-text scan | unchanged | permission | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Application/ListRoles.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Application/ManageAccountRoles.php` | full-text scan | unchanged | account,audit | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Application/ManageCustomRoles.php` | full-text scan | unchanged | account,audit,permission | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Application/PermissionPage.php` | full-text scan | unchanged | permission | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Application/PermissionRepository.php` | full-text scan | unchanged | account,permission | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Application/PermissionService.php` | full-text scan | unchanged | account,permission | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Application/PermissionView.php` | full-text scan | unchanged | permission | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Application/RolePage.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Application/RoleRepository.php` | full-text scan | unchanged | account | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Application/RoleView.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Infrastructure/PostgresPermissionRepository.php` | full-text scan | unchanged | account,permission | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Infrastructure/PostgresRoleRepository.php` | full-text scan | unchanged | account | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Rbac/Infrastructure/RequirePermission.php` | full-text scan | unchanged | account,permission | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Security/Application/IdentifierDigestService.php` | full-text scan | unchanged | account,privacy | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Security/Application/RateLimiterInterface.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Security/Application/RateLimiterUnavailableException.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Security/Application/SecurityAction.php` | full-text scan | unchanged | account,external,setting,permission | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Security/Application/SecurityAuditCommand.php` | full-text scan | unchanged | account,audit | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Security/Application/SecurityAuditService.php` | full-text scan | unchanged | account,audit | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Security/Application/SessionRiskEvaluator.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Security/Domain/SessionRiskAssessment.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Security/Domain/SessionRiskInput.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Security/Domain/SessionRiskLevel.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Security/Infrastructure/RedisRateLimiter.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Api/ConfigurationInput.php` | full-text scan | unchanged | setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Api/FeatureFlagsAction.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Api/ModuleSettingsAction.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Api/PublicConfigAction.php` | full-text scan | unchanged | setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Api/SystemSettingsAction.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Application/FeatureEvaluation.php` | full-text scan | unchanged | setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Application/FeatureFlag.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Application/FeatureFlagManager.php` | full-text scan | unchanged | account,audit,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Application/FeatureFlagRepository.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Application/FeatureFlagService.php` | full-text scan | unchanged | setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Application/ModuleSetting.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Application/ModuleSettingsRepository.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Application/SettingSecurityPolicy.php` | full-text scan | unchanged | setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Application/SettingsService.php` | full-text scan | unchanged | account,audit,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Application/SystemSetting.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Application/SystemSettingsRepository.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Application/UnsafeSettingException.php` | full-text scan | unchanged | setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Infrastructure/PostgresFeatureFlagRepository.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Infrastructure/PostgresJsonValue.php` | full-text scan | unchanged | setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Infrastructure/PostgresModuleSettingsRepository.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Settings/Infrastructure/PostgresSystemSettingsRepository.php` | full-text scan | unchanged | account,setting | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Shared/Application/ConflictException.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Shared/Application/NotFoundException.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Shared/Domain/ValueNormalizer.php` | full-text scan | unchanged | person,setting,permission | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Shared/Infrastructure/DestructiveOperationBlockedException.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Shared/Infrastructure/TestRuntimeGuard.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Time/Application/ClockInterface.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Time/Application/DateTimeFormatter.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Time/Application/ResolvedTimeZone.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Time/Application/TimeService.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Time/Application/TimeZoneResolver.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Time/Domain/TimeZone.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/src/Core/Time/Infrastructure/SystemClock.php` | full-text scan | unchanged | indirect | Existing Core boundary reviewed; no Phase 4.1 foundation edit required. |
| `apps/backend/tests/Api/AccountSettingsCest.php` | full-text scan | changed | account,audit,privacy,setting,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/tests/Api/AccountsCest.php` | full-text scan | unchanged | person,account | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/AuthSecurityCest.php` | full-text scan | unchanged | person | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/AuthenticationRuntimeCest.php` | full-text scan | unchanged | person,account | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/ConfigurationAuthorizationCest.php` | full-text scan | unchanged | setting,permission | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/ConfigurationRuntimeCest.php` | full-text scan | unchanged | account,audit,setting,schema | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/ConsentCest.php` | manual + full-text scan | changed | consent,person,account,privacy | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/tests/Api/CredentialLifecycleCest.php` | full-text scan | unchanged | account | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/CustomRolesCest.php` | full-text scan | unchanged | account,permission | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/HealthCest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/IndexCest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/InternationalizationCest.php` | full-text scan | unchanged | account | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/MfaAuthenticationCest.php` | full-text scan | unchanged | account | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/NotFoundCest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/PasskeyApiCest.php` | full-text scan | unchanged | account,external | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/PasswordChangeCest.php` | full-text scan | unchanged | account,audit,outbox | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/PersonsCest.php` | full-text scan | unchanged | person | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Api/PrivacyRepresentationCest.php` | manual + full-text scan | new | person,account,privacy,permission | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/tests/Api/RbacCest.php` | full-text scan | unchanged | account,permission | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/AccountSettingsCest.php` | full-text scan | changed | account,audit,privacy,setting,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/tests/Functional/AuditMessagingPersistenceCest.php` | full-text scan | unchanged | account,audit,outbox,inbox | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/AuthCleanupCest.php` | full-text scan | unchanged | account,retention | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/AuthPersistenceCest.php` | full-text scan | unchanged | account | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/AuthorizationLifecycleCest.php` | full-text scan | unchanged | account | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/CommunicationOutboxCest.php` | full-text scan | unchanged | account,outbox,schema | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/ConsentCest.php` | manual + full-text scan | changed | consent,person,account,privacy | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/tests/Functional/ExternalIdentityMfaPersistenceCest.php` | full-text scan | unchanged | account,external,claims | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/ExternalOidcFlowCest.php` | full-text scan | unchanged | account,audit,external,claims | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/HomePageCest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/InternationalPreferencesCest.php` | full-text scan | unchanged | account | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/MfaRecoveryHardeningCest.php` | full-text scan | unchanged | account,audit,external,claims,outbox | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/NotificationPolicyCest.php` | full-text scan | unchanged | account,outbox,setting,schema | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/PasskeyConcurrencyCest.php` | full-text scan | unchanged | account,external,claims,outbox | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/PasskeyHardeningCest.php` | full-text scan | unchanged | account,audit,outbox | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/PasskeyRuntimeCest.php` | full-text scan | unchanged | account,audit,outbox | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/PresenceCest.php` | full-text scan | changed | account,privacy,setting,presence,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/tests/Functional/PrivacyFoundationCest.php` | manual + full-text scan | new | consent,person,account,privacy | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/tests/Functional/SecurityAuditCest.php` | full-text scan | unchanged | account,audit | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/SessionLifecycleCest.php` | full-text scan | unchanged | account,audit | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/SettingsPersistenceCest.php` | full-text scan | unchanged | account,setting | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Functional/TotpProvisioningCest.php` | full-text scan | unchanged | account | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Support/TestReset.php` | manual + full-text scan | changed | consent,person,account,audit,privacy,outbox,inbox,setting,permission | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/tests/Unit/Core/Account/AccountSecurityStateTest.php` | full-text scan | unchanged | person,account | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/AccountSettings/SettingsSchemaRegistryTest.php` | full-text scan | changed | account,privacy,setting,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/tests/Unit/Core/Auth/MfaSecurityPrimitivesTest.php` | full-text scan | unchanged | external,claims | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Auth/OpaqueTokenServiceTest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Auth/PasswordAndIdentifierTest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Client/ClientContextResolverTest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Client/DeviceDetectorTest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Client/FingerprintServiceTest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Client/IpAddressResolverTest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Communication/EmailRequestTest.php` | full-text scan | unchanged | outbox,schema | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Communication/FileEmailTemplateRendererTest.php` | full-text scan | unchanged | account | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Communication/SmtpConfigurationTest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Geo/GeoIpServiceTest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Internationalization/CatalogArchitectureTest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Internationalization/LocaleNormalizerTest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Internationalization/LocaleResolverTest.php` | full-text scan | unchanged | account | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Internationalization/TranslationServiceTest.php` | full-text scan | unchanged | account | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Person/Api/PersonInputTest.php` | full-text scan | unchanged | person | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Rbac/Application/PermissionServiceTest.php` | full-text scan | unchanged | account,permission | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Security/AuditPayloadPolicyTest.php` | manual + full-text scan | new | consent,person,account,audit,privacy | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/tests/Unit/Core/Security/PrivacyRegistryTest.php` | manual + full-text scan | new | consent,person,privacy,retention | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/tests/Unit/Core/Security/SecurityAuditServiceTest.php` | full-text scan | changed | account,audit | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `apps/backend/tests/Unit/Core/Security/SessionRiskEvaluatorTest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Settings/FeatureFlagServiceTest.php` | full-text scan | unchanged | account,setting,schema | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Settings/SettingSecurityPolicyTest.php` | full-text scan | unchanged | setting | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Shared/Api/CanonicalInputContractTest.php` | full-text scan | unchanged | account,setting | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Shared/Domain/ValueNormalizerTest.php` | full-text scan | unchanged | person,setting | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Shared/Infrastructure/TestRuntimeGuardTest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `apps/backend/tests/Unit/Core/Time/TimeFoundationTest.php` | full-text scan | unchanged | indirect | Existing regression remains applicable; no assertion changed for this file. |
| `compose.yaml` | full-text scan | unchanged | audit,retention,external,outbox,presence,migration | Existing repository or deployment contract retained. |
| `contracts/events/.gitkeep` | full-text scan | unchanged | indirect | Existing versioned contract retained; privacy events belong to a later pass. |
| `contracts/events/control_user.email_verification_requested.v1.schema.json` | full-text scan | unchanged | schema | Existing versioned contract retained; privacy events belong to a later pass. |
| `contracts/events/deployment.provision_requested.v1.schema.json` | full-text scan | unchanged | schema | Existing versioned contract retained; privacy events belong to a later pass. |
| `contracts/events/entitlement.issued.v1.schema.json` | full-text scan | unchanged | schema | Existing versioned contract retained; privacy events belong to a later pass. |
| `contracts/events/envelope.v1.schema.json` | full-text scan | unchanged | schema | Existing versioned contract retained; privacy events belong to a later pass. |
| `contracts/events/fixtures/control_user.email_verification_requested.v1.json` | full-text scan | unchanged | schema | Existing versioned contract retained; privacy events belong to a later pass. |
| `contracts/events/fixtures/deployment.provision_requested.v1.json` | full-text scan | unchanged | schema | Existing versioned contract retained; privacy events belong to a later pass. |
| `contracts/events/fixtures/entitlement.issued.v1.json` | full-text scan | unchanged | schema | Existing versioned contract retained; privacy events belong to a later pass. |
| `docs/architecture/AUTHENTICATION_AUTHORIZATION.md` | full-text scan | unchanged | person,account,audit,external,outbox,permission,migration | Existing scope or historical guidance retained. |
| `docs/architecture/COMMERCIAL_CONTROL_PLANE.md` | full-text scan | unchanged | account,audit,claims,outbox,inbox,permission,migration,schema | Existing scope or historical guidance retained. |
| `docs/architecture/COMMERCIAL_SECURITY.md` | full-text scan | unchanged | account,audit,outbox,presence,permission,schema | Existing scope or historical guidance retained. |
| `docs/architecture/COMMUNICATION.md` | full-text scan | unchanged | account,claims,outbox,migration,schema | Existing scope or historical guidance retained. |
| `docs/architecture/CONSENT.md` | manual + full-text scan | changed | consent,person,account,audit,privacy,retention,erasure,migration | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `docs/architecture/CORE_BACKEND.md` | full-text scan | unchanged | consent,person,account,audit,retention,external,outbox,inbox,setting,presence,permission,migration | Existing scope or historical guidance retained. |
| `docs/architecture/DATABASE.md` | full-text scan | changed | consent,account,audit,privacy,inbox,setting,migration | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `docs/architecture/EVENT_CONTRACTS.md` | full-text scan | unchanged | audit,external,outbox,inbox,migration,schema | Existing scope or historical guidance retained. |
| `docs/architecture/INTERNATIONALIZATION.md` | full-text scan | unchanged | person,account,external,outbox | Existing scope or historical guidance retained. |
| `docs/architecture/INTERNATIONAL_SECURITY_CORE.md` | full-text scan | unchanged | account,audit,privacy,retention,external,outbox,setting,permission,migration | Existing scope or historical guidance retained. |
| `docs/architecture/LEGAL_REPRESENTATION.md` | manual + full-text scan | new | consent,person,privacy,permission | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `docs/architecture/MODULARITY.md` | full-text scan | unchanged | indirect | Existing scope or historical guidance retained. |
| `docs/architecture/NOTIFICATIONS.md` | full-text scan | unchanged | account,outbox,setting | Existing scope or historical guidance retained. |
| `docs/architecture/PRESENCE.md` | full-text scan | unchanged | account,audit,privacy,outbox,presence | Existing scope or historical guidance retained. |
| `docs/architecture/PRIVACY_COMPLIANCE_CORE.md` | manual + full-text scan | new | consent,person,account,audit,privacy,retention,erasure,external,setting,presence,permission | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `docs/architecture/PRIVACY_INCIDENTS.md` | manual + full-text scan | new | audit | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `docs/architecture/PROJECT_TREE.md` | full-text scan | unchanged | account | Existing scope or historical guidance retained. |
| `docs/architecture/RETENTION_ERASURE.md` | manual + full-text scan | new | consent,person,account,audit,privacy,retention,erasure | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `docs/architecture/SECURITY_HARDENING.md` | full-text scan | unchanged | account | Existing scope or historical guidance retained. |
| `docs/architecture/USER_SETTINGS_PRIVACY.md` | full-text scan | changed | consent,account,audit,privacy,setting,presence,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `docs/database/core/Core_v1_FINAL.dbdiagram` | full-text scan | unchanged | indirect | Historical schema artifact preserved. |
| `docs/database/core/Core_v1_FINAL.dbml` | full-text scan | unchanged | person,account,audit,privacy,retention,external,claims,outbox,inbox,setting,permission,migration,schema | Historical schema artifact preserved. |
| `docs/database/core/Core_v1_FINAL_RU.md` | full-text scan | unchanged | person,account,audit,retention,external,claims,outbox,inbox,setting,permission,migration,schema | Historical schema artifact preserved. |
| `docs/database/core/Core_v2.dbml` | manual + full-text scan | changed | consent,person,account,audit,privacy,retention,external,claims,outbox,inbox,setting,permission,migration,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `docs/database/core/README.md` | full-text scan | changed | privacy,migration | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `infra/postgres/migrate.sh` | manual + full-text scan | changed | consent,account,privacy,migration,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `scripts/backup-db.ps1` | full-text scan | unchanged | indirect | Existing operational path retained; no new runtime step here. |
| `scripts/change-control-db-password.ps1` | full-text scan | unchanged | outbox,migration | Existing operational path retained; no new runtime step here. |
| `scripts/change-platform-db-password.ps1` | full-text scan | unchanged | indirect | Existing operational path retained; no new runtime step here. |
| `scripts/change-postgres-password.ps1` | full-text scan | unchanged | indirect | Existing operational path retained; no new runtime step here. |
| `scripts/common.ps1` | full-text scan | unchanged | setting | Existing operational path retained; no new runtime step here. |
| `scripts/control-test-reset.ps1` | full-text scan | unchanged | migration | Existing operational path retained; no new runtime step here. |
| `scripts/control-test-reset.sh` | full-text scan | unchanged | migration | Existing operational path retained; no new runtime step here. |
| `scripts/control-test-run.ps1` | full-text scan | unchanged | indirect | Existing operational path retained; no new runtime step here. |
| `scripts/control-test-run.sh` | full-text scan | unchanged | indirect | Existing operational path retained; no new runtime step here. |
| `scripts/control-test-start.ps1` | full-text scan | unchanged | migration | Existing operational path retained; no new runtime step here. |
| `scripts/control-test-start.sh` | full-text scan | unchanged | migration | Existing operational path retained; no new runtime step here. |
| `scripts/control-test-stop.ps1` | full-text scan | unchanged | indirect | Existing operational path retained; no new runtime step here. |
| `scripts/control-test-stop.sh` | full-text scan | unchanged | indirect | Existing operational path retained; no new runtime step here. |
| `scripts/create-project-zip.ps1` | full-text scan | unchanged | indirect | Existing operational path retained; no new runtime step here. |
| `scripts/db-status.ps1` | full-text scan | changed | consent,account,privacy,migration,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `scripts/first-start.ps1` | full-text scan | changed | consent,account,privacy,migration,schema | Phase 4.1 implementation, contract, documentation, or verification; inspect diff. |
| `scripts/init-env.ps1` | full-text scan | unchanged | external | Existing operational path retained; no new runtime step here. |
| `scripts/reset-dev-db.ps1` | full-text scan | unchanged | indirect | Existing operational path retained; no new runtime step here. |
| `scripts/restore-db.ps1` | full-text scan | unchanged | indirect | Existing operational path retained; no new runtime step here. |
| `scripts/rotate-db-password.ps1` | full-text scan | unchanged | setting | Existing operational path retained; no new runtime step here. |
| `scripts/test-project-zip.ps1` | full-text scan | unchanged | migration | Existing operational path retained; no new runtime step here. |
| `scripts/test-reset.ps1` | full-text scan | unchanged | setting | Existing operational path retained; no new runtime step here. |
| `scripts/test-reset.sh` | full-text scan | unchanged | setting | Existing operational path retained; no new runtime step here. |
| `scripts/test-run.ps1` | full-text scan | unchanged | indirect | Existing operational path retained; no new runtime step here. |
| `scripts/test-run.sh` | full-text scan | unchanged | indirect | Existing operational path retained; no new runtime step here. |
| `scripts/test-start.ps1` | full-text scan | unchanged | migration | Existing operational path retained; no new runtime step here. |
| `scripts/test-start.sh` | full-text scan | unchanged | migration | Existing operational path retained; no new runtime step here. |
| `scripts/test-stop.ps1` | full-text scan | unchanged | indirect | Existing operational path retained; no new runtime step here. |
| `scripts/test-stop.sh` | full-text scan | unchanged | indirect | Existing operational path retained; no new runtime step here. |
| `docs/privacy/CORE_DATA_INVENTORY.md` | manual + full-text scan | new | person,account,audit,privacy,retention,consent | Current table and significant-field classification, including migration 020 delta. |
| `docs/audit/PHASE_4_1_CORE_PRIVACY_FILE_AUDIT.md` | manual + full-text scan | new | audit,privacy | This evidence manifest was reviewed after generation and updated at the final pass. |
