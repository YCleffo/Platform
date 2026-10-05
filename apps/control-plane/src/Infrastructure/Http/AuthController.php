<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Identity\Application\AuthService;
use ControlPlane\Identity\Application\AuthSecurityService;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/control/auth', name: 'control_auth_')]
final class AuthController extends AbstractController
{
    public function __construct(private readonly AuthService $auth, private readonly AuthSecurityService $security)
    {
    }

    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = ApiRequest::json($request);
        $result = $this->auth->register(ApiRequest::string($data, 'email'), ApiRequest::string($data, 'password'), $this->ip($request), ApiRequest::correlationId($request));

        return new JsonResponse(['status' => 'verification_required', 'user_id' => $result['user_id']], Response::HTTP_ACCEPTED);
    }

    #[Route('/verification/resend', name: 'verification_resend', methods: ['POST'])]
    public function resend(Request $request): JsonResponse
    {
        $data = ApiRequest::json($request);
        $this->auth->resendVerification(ApiRequest::string($data, 'email'), $this->ip($request), ApiRequest::correlationId($request));

        return new JsonResponse(['status' => 'accepted'], Response::HTTP_ACCEPTED);
    }

    #[Route('/verify', name: 'verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $data = ApiRequest::json($request);
        $this->auth->verifyEmail(ApiRequest::string($data, 'token'), ApiRequest::correlationId($request));

        return new JsonResponse(['status' => 'verified']);
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = ApiRequest::json($request);
        $tokens = $this->security->login(ApiRequest::string($data, 'email'), ApiRequest::string($data, 'password'), $this->ip($request), (string) $request->headers->get('User-Agent', ''), ApiRequest::correlationId($request));

        return new JsonResponse($tokens);
    }

    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $data = ApiRequest::json($request);
        $tokens = $this->auth->refresh(ApiRequest::string($data, 'refresh_token'), $this->ip($request), (string) $request->headers->get('User-Agent', ''), ApiRequest::correlationId($request));

        return new JsonResponse($tokens);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $this->auth->logout(ApiRequest::bearer($request), ApiRequest::correlationId($request));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/password/reset/request', name: 'password_reset_request', methods: ['POST'])]
    public function passwordResetRequest(Request $request): JsonResponse
    {
        $data = ApiRequest::json($request);
        $this->security->requestPasswordReset(ApiRequest::string($data, 'email'), $this->ip($request), ApiRequest::correlationId($request));
        return new JsonResponse(['status' => 'accepted'], Response::HTTP_ACCEPTED);
    }

    #[Route('/password/reset/confirm', name: 'password_reset_confirm', methods: ['POST'])]
    public function passwordResetConfirm(Request $request): JsonResponse
    {
        $data = ApiRequest::json($request);
        $this->security->confirmPasswordReset(ApiRequest::string($data, 'token'), ApiRequest::string($data, 'password'), ApiRequest::correlationId($request));
        return new JsonResponse(['status' => 'password_changed']);
    }

    #[Route('/password/change', name: 'password_change', methods: ['POST'])]
    public function passwordChange(Request $request): JsonResponse
    { $data=ApiRequest::json($request); $this->security->changePassword($this->user(), ApiRequest::string($data,'current_password'), ApiRequest::string($data,'password'), ApiRequest::correlationId($request)); return new JsonResponse(['status'=>'password_changed']); }

    #[Route('/reauthenticate', name: 'reauthenticate', methods: ['POST'])]
    public function reauthenticate(Request $request): JsonResponse
    { $data=ApiRequest::json($request); $this->security->reauthenticate($this->user(),ApiRequest::string($data,'password'),ApiRequest::nullableString($data,'code'),ApiRequest::nullableString($data,'recovery_code'),ApiRequest::correlationId($request)); return new JsonResponse(['status'=>'reauthenticated']); }

    #[Route('/mfa/login/verify', name: 'mfa_login_verify', methods: ['POST'])]
    public function verifyMfaLogin(Request $request): JsonResponse
    {
        $data = ApiRequest::json($request);
        $result = $this->security->verifyMfaLogin(ApiRequest::string($data, 'challenge_token'), ApiRequest::nullableString($data, 'code'), ApiRequest::nullableString($data, 'recovery_code'), $this->ip($request), (string) $request->headers->get('User-Agent', ''), ApiRequest::correlationId($request));
        return new JsonResponse($result);
    }

    #[Route('/mfa/status', name: 'mfa_status', methods: ['GET'])]
    public function mfaStatus(): JsonResponse { return new JsonResponse($this->security->mfaStatus($this->user())); }

    #[Route('/mfa/totp/setup', name: 'mfa_totp_setup', methods: ['POST'])]
    public function totpSetup(Request $request): JsonResponse
    { ApiRequest::emptyObject($request); return new JsonResponse($this->security->beginTotpSetup($this->user(), ApiRequest::correlationId($request)), Response::HTTP_CREATED); }

    #[Route('/mfa/totp/confirm', name: 'mfa_totp_confirm', methods: ['POST'])]
    public function totpConfirm(Request $request): JsonResponse
    { $data=ApiRequest::json($request); return new JsonResponse(['recovery_codes' => $this->security->confirmTotpSetup($this->user(), ApiRequest::string($data,'factor_id'), ApiRequest::string($data,'code'), ApiRequest::correlationId($request))]); }

    #[Route('/mfa/recovery-codes/regenerate', name: 'mfa_recovery_regenerate', methods: ['POST'])]
    public function recoveryRegenerate(Request $request): JsonResponse
    { ApiRequest::emptyObject($request); return new JsonResponse(['recovery_codes'=>$this->security->regenerateRecoveryCodes($this->user(),ApiRequest::correlationId($request))]); }

    #[Route('/mfa/disable', name: 'mfa_disable', methods: ['POST'])]
    public function mfaDisable(Request $request): Response
    { ApiRequest::emptyObject($request); $this->security->disableMfa($this->user(),ApiRequest::correlationId($request)); return new Response(status:Response::HTTP_NO_CONTENT); }

    #[Route('/sessions', name: 'sessions', methods: ['GET'])]
    public function sessions(): JsonResponse { return new JsonResponse(['items' => $this->security->sessions($this->user())]); }

    #[Route('/sessions/{sessionId}', name: 'session_revoke', methods: ['DELETE'])]
    public function revokeSession(string $sessionId, Request $request): Response
    { $this->security->revokeSession($this->user(), $sessionId, ApiRequest::correlationId($request)); return new Response(status: Response::HTTP_NO_CONTENT); }

    #[Route('/sessions/revoke-others', name: 'sessions_revoke_others', methods: ['POST'])]
    public function revokeOtherSessions(Request $request): Response
    { ApiRequest::emptyObject($request); $this->security->revokeOtherSessions($this->user(), ApiRequest::correlationId($request)); return new Response(status: Response::HTTP_NO_CONTENT); }

    private function ip(Request $request): string
    {
        return $request->getClientIp() ?? 'unknown';
    }

    private function user(): ControlUserPrincipal
    {
        $user = $this->getUser();
        if (!$user instanceof ControlUserPrincipal) { throw $this->createAccessDeniedException(); }
        return $user;
    }
}
