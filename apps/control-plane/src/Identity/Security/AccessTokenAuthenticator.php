<?php

declare(strict_types=1);

namespace ControlPlane\Identity\Security;

use ControlPlane\Identity\Application\AuthService;
use ControlPlane\Infrastructure\Http\ApiRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class AccessTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private AuthService $auth)
    {
    }

    public function supports(Request $request): bool
    {
        return ApiRequest::bearer($request) !== '';
    }

    public function authenticate(Request $request): Passport
    {
        $token = ApiRequest::bearer($request);

        return new SelfValidatingPassport(new UserBadge($token, fn (): ControlUserPrincipal => $this->auth->authenticateAccessToken($token)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return self::unauthorized();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized();
    }

    private static function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => 'authentication_required', 'message' => 'Authentication is required.']], Response::HTTP_UNAUTHORIZED);
    }
}
