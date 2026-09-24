<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use ControlPlane\Identity\Application\AuthFailure;
use ControlPlane\Identity\Security\RateLimitExceeded;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        $exception = $event->getThrowable();
        [$status, $code, $message] = match (true) {
            $exception instanceof RateLimitExceeded => [Response::HTTP_TOO_MANY_REQUESTS, 'rate_limited', 'Too many requests.'],
            $exception instanceof AuthFailure => [in_array($exception->errorCode, ['invalid_credentials', 'invalid_access_token'], true) || str_starts_with($exception->errorCode, 'refresh_') ? Response::HTTP_UNAUTHORIZED : Response::HTTP_BAD_REQUEST, $exception->errorCode, $exception->getMessage()],
            $exception instanceof AccessDeniedException && ApiRequest::bearer($request) === '' => [Response::HTTP_UNAUTHORIZED, 'authentication_required', 'Authentication is required.'],
            $exception instanceof AccessDeniedException => [Response::HTTP_NOT_FOUND, 'resource_not_found', 'The requested resource was not found.'],
            $exception instanceof UniqueConstraintViolationException => [Response::HTTP_CONFLICT, 'conflict', 'The requested resource conflicts with existing data.'],
            $exception instanceof \JsonException => [Response::HTTP_BAD_REQUEST, 'invalid_json', 'The request body is not valid JSON.'],
            $exception instanceof \InvalidArgumentException => [Response::HTTP_UNPROCESSABLE_ENTITY, 'validation_failed', 'The request data is invalid.'],
            $exception instanceof \DomainException => [Response::HTTP_CONFLICT, 'operation_rejected', 'The requested operation is not allowed.'],
            default => [Response::HTTP_INTERNAL_SERVER_ERROR, 'internal_error', 'The request could not be completed.'],
        };
        $response = new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
        if ($exception instanceof RateLimitExceeded) {
            $response->headers->set('Retry-After', (string) $exception->retryAfter);
        }
        $event->setResponse($response);
    }

    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 100]];
    }
}
