<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\ErrorHandler\Exception\UserException;
use Yiisoft\ErrorHandler\Middleware\ExceptionResponder;
use Yiisoft\Injector\Injector;
use Yiisoft\Input\Http\InputValidationException;

final readonly class ExceptionResponderFactory
{
    public function __construct(
        private ResponseFactoryInterface $psrResponseFactory,
        private ResponseFactory $apiResponseFactory,
        private Injector $injector,
        private LoggerInterface $logger,
    ) {}

    public function create(): ExceptionResponder
    {
        return new ExceptionResponder(
            [
                InputValidationException::class => $this->inputValidationException(...),
                Throwable::class => $this->throwable(...),
            ],
            $this->psrResponseFactory,
            $this->injector,
        );
    }

    private function inputValidationException(InputValidationException $exception): ResponseInterface
    {
        return $this->apiResponseFactory->failValidation($exception->getResult());
    }

    private function throwable(Throwable $exception): ResponseInterface
    {
        if (UserException::isUserException($exception)) {
            $code = $exception->getCode();
            $status = is_int($code) && in_array($code, [400, 401, 403, 404, 405, 409, 413, 415, 422, 429, 503], true)
                ? $code
                : 400;
            return $this->apiResponseFactory->forStatus($status);
        }

        $this->logger->error('Unhandled API exception.', [
            'errorCode' => ApiMessage::HTTP_INTERNAL_ERROR,
            'exceptionType' => $exception::class,
        ]);
        return $this->apiResponseFactory->forStatus(500);
    }
}
