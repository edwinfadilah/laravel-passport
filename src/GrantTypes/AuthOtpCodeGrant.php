<?php
/**
 * Created by PhpStorm.
 * User: bahasolaptop2
 * Date: 11/09/19
 * Time: 10:14
 */

namespace EdwinFadilah\Passport\GrantTypes;

use DateInterval;
use DateTime;
use EdwinFadilah\Passport\ResponseTypes\CodeResponse;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractAuthorizeGrant;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

class AuthOtpCodeGrant extends AbstractAuthorizeGrant
{
    use GetRequestAttribute;
    /**
     * @var DateInterval
     */
    private $authCodeTTL;

    /**
     * @var bool
     */
    private $enableCodeExchangeProof = false;

    /**
     * @param UserRepositoryInterface $userRepository
     * @param AuthCodeRepositoryInterface $authCodeRepository
     * @param RefreshTokenRepositoryInterface $refreshTokenRepository
     * @param DateInterval $authCodeTTL
     *
     */
    public function __construct(
        UserRepositoryInterface $userRepository,
        AuthCodeRepositoryInterface $authCodeRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        DateInterval $authCodeTTL
    ) {
        $this->setAuthCodeRepository($authCodeRepository);
        $this->setRefreshTokenRepository($refreshTokenRepository);
        $this->userRepository = $userRepository;
        $this->authCodeTTL = $authCodeTTL;
        $this->refreshTokenTTL = new DateInterval('P1M');
    }

    public function enableCodeExchangeProof()
    {
        $this->enableCodeExchangeProof = true;
    }

    /**
     * Respond to an access token request.
     *
     * @param ServerRequestInterface $request
     * @param ResponseTypeInterface  $responseType
     * @param DateInterval           $accessTokenTTL
     *
     * @throws OAuthServerException
     *
     * @return ResponseTypeInterface
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL
    ) {
        // Validate request
        $client = $this->validateClient($request);
        $encryptedAuthCode = $this->getRequestParameter('otp_code', $request, null);

        if ($encryptedAuthCode === null) {
            throw OAuthServerException::invalidRequest('otp_code');
        }

        try {
            $authCodePayload = json_decode($this->decrypt($encryptedAuthCode));

            $this->validateAuthorizationCode($authCodePayload, $client, $request);

            $scopes = $this->scopeRepository->finalizeScopes(
                $this->validateScopes($authCodePayload->scopes),
                $this->getIdentifier(),
                $client,
                $authCodePayload->user_id
            );
        } catch (LogicException $e) {
            throw OAuthServerException::invalidRequest('otp_code', 'Cannot decrypt the authorization otp code', $e);
        }

        // Validate code challenge
        if ($this->enableCodeExchangeProof === true) {
            $codeVerifier = $this->getRequestParameter('code_verifier', $request, null);

            if ($codeVerifier === null) {
                throw OAuthServerException::invalidRequest('code_verifier');
            }

            // Validate code_verifier according to RFC-7636
            // @see: https://tools.ietf.org/html/rfc7636#section-4.1
            if (preg_match('/^[A-Za-z0-9-._~]{43,128}$/', $codeVerifier) !== 1) {
                throw OAuthServerException::invalidRequest(
                    'code_verifier',
                    'Code Verifier must follow the specifications of RFC-7636.'
                );
            }

            switch ($authCodePayload->code_challenge_method) {
                case 'plain':
                    if (hash_equals($codeVerifier, $authCodePayload->code_challenge) === false) {
                        throw OAuthServerException::invalidGrant('Failed to verify `code_verifier`.');
                    }

                    break;
                case 'S256':
                    if (
                        hash_equals(
                            strtr(rtrim(base64_encode(hash('sha256', $codeVerifier, true)), '='), '+/', '-_'),
                            $authCodePayload->code_challenge
                        ) === false
                    ) {
                        throw OAuthServerException::invalidGrant('Failed to verify `code_verifier`.');
                    }
                    // @codeCoverageIgnoreStart
                    break;
                default:
                    throw OAuthServerException::serverError(
                        sprintf(
                            'Unsupported code challenge method `%s`',
                            $authCodePayload->code_challenge_method
                        )
                    );
                // @codeCoverageIgnoreEnd
            }
        }

        $user_id = $this->getRequestAttribute('user_id', $request);
        if ($user_id != $authCodePayload->user_id)
            throw OAuthServerException::invalidGrant('Failed to verify `code_verifier`.');

        // Issue and persist new access token
        $accessToken = $this->issueAccessToken($accessTokenTTL, $client, $authCodePayload->user_id, $scopes);
        $this->getEmitter()->emit(new RequestEvent(RequestEvent::ACCESS_TOKEN_ISSUED, $request));
        $responseType->setAccessToken($accessToken);

        // Issue and persist new refresh token if given
        $refreshToken = $this->issueRefreshToken($accessToken);

        if ($refreshToken !== null) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::REFRESH_TOKEN_ISSUED, $request));
            $responseType->setRefreshToken($refreshToken);
        }

        // Revoke used auth code
        $this->authCodeRepository->revokeAuthCode($authCodePayload->auth_code_id);

        return $responseType;
    }

    /**
     * Validate the authorization code.
     *
     * @param stdClass $authCodePayload
     * @param ClientEntityInterface $client
     * @param ServerRequestInterface $request
     * @throws OAuthServerException
     */
    private function validateAuthorizationCode(
        $authCodePayload,
        ClientEntityInterface $client,
        ServerRequestInterface $request
    ) {
        if (time() > $authCodePayload->expire_time) {
            throw OAuthServerException::invalidRequest('otp_code', 'Authorization code has expired');
        }

        if ($this->authCodeRepository->isAuthCodeRevoked($authCodePayload->auth_code_id) === true) {
            throw OAuthServerException::invalidRequest('otp_code', 'Authorization code has been revoked');
        }

        if ($authCodePayload->client_id !== $client->getIdentifier()) {
            throw OAuthServerException::invalidRequest('otp_code', 'Authorization code was not issued to this client');
        }

        // The redirect URI is required in this request
        $redirectUri = $this->getRequestParameter('redirect_uri', $request, null);
        if (empty($authCodePayload->redirect_uri) === false && $redirectUri === null) {
            throw OAuthServerException::invalidRequest('redirect_uri');
        }

        if ($authCodePayload->redirect_uri !== $redirectUri) {
            throw OAuthServerException::invalidRequest('redirect_uri', 'Invalid redirect URI');
        }
    }

    /**
     * Return the grant identifier that can be used in matching up requests.
     *
     * @return string
     */
    public function getIdentifier()
    {
        return 'authorization_otp_code';
    }

    /**
     * {@inheritdoc}
     */
    public function canRespondToAuthorizationRequest(ServerRequestInterface $request)
    {
        return (
            array_key_exists('response_type', $request->getParsedBody())
            && $request->getParsedBody()['response_type'] === 'otp_code'
            && isset($request->getParsedBody()['client_id'])
        );
    }

    /**
     * {@inheritdoc}
     */
    public function validateAuthorizationRequest(ServerRequestInterface $request)
    {
        $clientId = $this->getRequestParameter(
            'client_id',
            $request,
            $this->getServerParameter('PHP_AUTH_USER', $request)
        );

        if ($clientId === null) {
            throw OAuthServerException::invalidRequest('client_id');
        }

        $client = $this->clientRepository->getClientEntity(
            $clientId,
            $this->getIdentifier(),
            null,
            false
        );

        if ($client instanceof ClientEntityInterface === false) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::CLIENT_AUTHENTICATION_FAILED, $request));
            throw OAuthServerException::invalidClient();
        }

        /*$redirectUri = $this->getRequestParameter('redirect_uri', $request);

        if ($redirectUri !== null) {
            $this->validateRedirectUri($redirectUri, $client, $request);
        } elseif (empty($client->getRedirectUri()) ||
            (\is_array($client->getRedirectUri()) && \count($client->getRedirectUri()) !== 1)) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::CLIENT_AUTHENTICATION_FAILED, $request));
            throw OAuthServerException::invalidClient();
        } else {
            $redirectUri = \is_array($client->getRedirectUri())
                ? $client->getRedirectUri()[0]
                : $client->getRedirectUri();
        }*/

        $scopes = $this->validateScopes(
            $this->getRequestParameter('scope', $request, $this->defaultScope)/*,
            $redirectUri*/
        );

        $stateParameter = $this->getRequestParameter('state', $request);

        $authorizationRequest = new AuthorizationRequest();
        $authorizationRequest->setGrantTypeId($this->getIdentifier());
        $authorizationRequest->setClient($client);
        $authorizationRequest->setUser(
            $this->userRepository->getUserEntityByUserId(
                $this->getRequestAttribute('user_id', $request)
            )
        );
        $authorizationRequest->setAuthorizationApproved(true);
        /*$authorizationRequest->setRedirectUri($redirectUri);*/

        if ($stateParameter !== null) {
            $authorizationRequest->setState($stateParameter);
        }

        $authorizationRequest->setScopes($scopes);

        if ($this->enableCodeExchangeProof === true) {
            $codeChallenge = $this->getRequestParameter('code_challenge', $request);
            if ($codeChallenge === null) {
                throw OAuthServerException::invalidRequest('code_challenge');
            }

            $codeChallengeMethod = $this->getRequestParameter('code_challenge_method', $request, 'plain');

            if (\in_array($codeChallengeMethod, ['plain', 'S256'], true) === false) {
                throw OAuthServerException::invalidRequest(
                    'code_challenge_method',
                    'Code challenge method must be `plain` or `S256`'
                );
            }

            // Validate code_challenge according to RFC-7636
            // @see: https://tools.ietf.org/html/rfc7636#section-4.2
            if (preg_match('/^[A-Za-z0-9-._~]{43,128}$/', $codeChallenge) !== 1) {
                throw OAuthServerException::invalidRequest(
                    'code_challenged',
                    'Code challenge must follow the specifications of RFC-7636.'
                );
            }

            $authorizationRequest->setCodeChallenge($codeChallenge);
            $authorizationRequest->setCodeChallengeMethod($codeChallengeMethod);
        }

        return $authorizationRequest;
    }

    /**
     * {@inheritdoc}
     */
    public function completeAuthorizationRequest(AuthorizationRequest $authorizationRequest)
    {
        if ($authorizationRequest->getUser() instanceof UserEntityInterface === false) {
            throw new LogicException('An instance of UserEntityInterface should be set on the AuthorizationRequest');
        }

        $finalRedirectUri = $authorizationRequest->getRedirectUri()
            ?? $this->getClientRedirectUri($authorizationRequest);

        // The user approved the client, redirect them back with an auth code
        if ($authorizationRequest->isAuthorizationApproved() === true) {
            $authCode = $this->issueAuthCode(
                $this->authCodeTTL,
                $authorizationRequest->getClient(),
                $authorizationRequest->getUser()->getIdentifier(),
                $authorizationRequest->getRedirectUri(),
                $authorizationRequest->getScopes()
            );

            $payload = [
                'client_id'             => $authCode->getClient()->getIdentifier(),
                'redirect_uri'          => $authCode->getRedirectUri(),
                'auth_code_id'          => $authCode->getIdentifier(),
                'scopes'                => $authCode->getScopes(),
                'user_id'               => $authCode->getUserIdentifier(),
                'expire_time'           => (new DateTime())->add($this->authCodeTTL)->format('U'),
                'code_challenge'        => $authorizationRequest->getCodeChallenge(),
                'code_challenge_method' => $authorizationRequest->getCodeChallengeMethod(),
            ];

            $response = new CodeResponse();
            $response->setCode(
                $this->encrypt(
                    json_encode(
                        $payload
                    )
                )
            );
            $response->setExpireDateTime((new DateTime())->add($this->authCodeTTL));
            $response->setState($authorizationRequest->getState());

            return $response;
        }

        // The user denied the client, redirect them back with an error
        throw OAuthServerException::accessDenied(
            'The user denied the request',
            $this->makeRedirectUri(
                $finalRedirectUri,
                [
                    'state' => $authorizationRequest->getState(),
                ]
            )
        );
    }

    /**
     * Get the client redirect URI if not set in the request.
     *
     * @param AuthorizationRequest $authorizationRequest
     *
     * @return string
     */
    private function getClientRedirectUri(AuthorizationRequest $authorizationRequest)
    {
        return \is_array($authorizationRequest->getClient()->getRedirectUri())
            ? $authorizationRequest->getClient()->getRedirectUri()[0]
            : $authorizationRequest->getClient()->getRedirectUri();
    }
}