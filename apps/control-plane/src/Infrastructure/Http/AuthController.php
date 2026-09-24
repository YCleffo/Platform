<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Identity\Application\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/control/auth', name: 'control_auth_')]
final readonly class AuthController
{
    public function __construct(private AuthService $auth)
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
        $tokens = $this->auth->login(ApiRequest::string($data, 'email'), ApiRequest::string($data, 'password'), $this->ip($request), (string) $request->headers->get('User-Agent', ''), ApiRequest::correlationId($request));

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

    private function ip(Request $request): string
    {
        return $request->getClientIp() ?? 'unknown';
    }
}
