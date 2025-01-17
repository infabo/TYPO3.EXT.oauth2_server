<?php

declare(strict_types=1);

namespace R3H6\Oauth2Server\Middleware;

use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use R3H6\Oauth2Server\ExceptionHandlingTrait;
use R3H6\Oauth2Server\RequestAttributes;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Http\DispatcherInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/***
 *
 * This file is part of the "OAuth2 Server" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2020
 *
 ***/

class Dispatcher implements MiddlewareInterface, LoggerAwareInterface
{
    use ExceptionHandlingTrait;
    use LoggerAwareTrait;

    public function __construct(
        private readonly DispatcherInterface $dispatcher,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute(RequestAttributes::OAUTH2_ROUTE);
        if ($route === null) {
            return $handler->handle($request);
        }

        $this->logger->debug('Dispatching oauth2 server');

        $expressions = (array)($route->getOptions()['oauth2_constraints'] ?? 'oauth.authorized');
        try {
            $this->checkConstraints($request, $expressions);
        } catch (\Exception $exception) {
            $this->logger->debug('Constraint check failed', ['exception' => $exception]);
            return $this->handleException($exception);
        }

        $controller = $route->getDefaults()['_controller'] ?? null;
        if ($controller === null) {
            $this->logger->error('No controller found in route');
            return $handler->handle($request);
        }

        $request = $request->withAttribute('target', $controller);
        try {
            return $this->dispatcher->dispatch($request);
        } catch (\Exception $exception) {
            $this->logger->debug('Dispatching failed', ['exception' => $exception]);
            return $this->handleException($exception);
        }
    }

    private function checkConstraints(ServerRequestInterface $request, array $expressions): void
    {
        $defaultProvider = GeneralUtility::makeInstance(\TYPO3\CMS\Core\ExpressionLanguage\DefaultProvider::class);
        $variables = $defaultProvider->getExpressionLanguageVariables();

        $frontendUserAspect = new UserAspect($request->getAttribute('frontend.user'));
        $frontend = new \stdClass();
        $frontend->user = new \stdClass();
        $frontend->user->isLoggedIn = $frontendUserAspect->get('isLoggedIn');
        $frontend->user->userId = $frontendUserAspect->get('id');
        $frontend->user->userGroupList = implode(',', $frontendUserAspect->get('groupIds'));
        $frontend->user->userGroupIds = $frontendUserAspect->get('groupIds');
        $variables['frontend'] = $frontend;

        $oauth = new \stdClass();
        $oauth->authorized = $request->getAttribute(RequestAttributes::OAUTH_ACCESS_TOKEN_ID) !== null;
        $oauth->grant = $request->getAttribute(RequestAttributes::OAUTH2_GRANT)?->value;
        $oauth->scopes = $request->getAttribute(RequestAttributes::OAUTH_SCOPES);
        $variables['oauth'] = $oauth;

        $variables['request'] = $request;

        $language = new ExpressionLanguage();
        foreach ($defaultProvider->getExpressionLanguageProviders() as $providerClass) {
            $provider = GeneralUtility::makeInstance($providerClass);
            assert($provider instanceof ExpressionFunctionProviderInterface);
            $language->registerProvider($provider);
        }

        foreach ($expressions as $expression) {
            $result = $language->evaluate((string)$expression, $variables);
            if ($result === false) {
                $this->logger->debug('Evaluation for expression failed: ' . $expression, $variables);
                throw OAuthServerException::accessDenied("Evaluation for expression failed: $expression");
            }
        }
    }
}
