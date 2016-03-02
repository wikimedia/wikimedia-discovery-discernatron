<?php

namespace WikiMedia\OAuth;

use Firebase\JWT\JWT;
use GuzzleHttp\Exception\BadResponseException;
use League\OAuth1\Client\Credentials\CredentialsException;
use League\OAuth1\Client\Credentials\TokenCredentials;
use League\OAuth1\Client\Server\Server;
use League\OAuth1\Client\Signature\SignatureInterface;
use WikiMedia\OAuth\Exception\RuntimeException;

class MediaWiki extends Server
{
    private $baseUrl;

    public function __construct($clientCredentials, SignatureInterface $signature = null)
    {
        if (!is_array($clientCredentials)) {
            throw new \InvalidArgumentException('Client credentials must be an array.');
        }
        if (!isset($clientCredentials['baseUrl'])) {
            throw new \InvalidArgumentException('Client credentials must include a baseUrl.');
        }
        $this->baseUrl = $clientCredentials['baseUrl'];
        parent::__construct($clientCredentials, $signature);
    }

    public function urlTemporaryCredentials()
    {
        return $this->baseUrl.'/initiate&oauth_callback=oob';
    }

    public function urlAuthorization()
    {
        return $this->baseUrl.'/authorize';
    }

    public function urlTokenCredentials()
    {
        return $this->baseUrl.'/token';
    }

    public function urlUserDetails()
    {
        return $this->baseUrl.'/identify';
    }

    public function userDetails($data, TokenCredentials $tokenCredentials)
    {
        $user = new User();
        $user->uid = $data->sub;
        $user->nickname = $data->username;
        $user->name = $data->username;
        $user->extra['editCount'] = $data->editcount;
        $user->extra['issued'] = $data->iat;

        return $user;
    }

    public function userUid($data, TokenCredentials $tokenCredentials)
    {
        return $data->sub;
    }

    public function userEmail($data, TokenCredentials $tokenCredentials)
    {
        return '';
    }

    public function userScreenName($data, TokenCredentials $tokenCredentials)
    {
        return $data->username;
    }

    protected function createTokenCredentials($body)
    {
        parse_str($body, $data);

        if (!isset($data['oauth_token'], $data['oauth_token_secret'])) {
            // mediawiki doesn't set an error key, it's the only key
            $error = reset(array_keys($data));
            throw new CredentialsException("[$error] in retrieving token credentials.");
        }

        return parent::createTokenCredentials($body);
    }

    /**
     * Fetch user details from the remote service.
     *
     * @param TokenCredentials $tokenCredentials
     * @param bool             $force
     *
     * @return array HTTP client response
     *
     * @throws \Exception
     */
    protected function fetchUserDetails(TokenCredentials $tokenCredentials, $force = true)
    {
        if (!$this->cachedUserDetailsResponse || $force) {
            $url = $this->urlUserDetails();

            $client = $this->createHttpClient();

            $headers = $this->getHeaders($tokenCredentials, 'GET', $url);

            try {
                $response = $client->get($url, $headers)->send();
            } catch (BadResponseException $e) {
                $response = $e->getResponse();
                $body = $response->getBody();
                $statusCode = $response->getStatusCode();

                throw new RuntimeException(
                    "Received error [$body] with status code [$statusCode] when retrieving token credentials."
                );
            }

            // Provide a little leeway to account for potential clock skew
            JWT::$leeway = 60;
            $decoded = JWT::decode($response->getBody(true), $this->clientCredentials->getSecret(), array('HS256'));
            // respect expiration timeout
            if (time() > $decoded->exp) {
                throw new RuntimeException('reply has expired');
            }
            if (!isset($decoded->username)) {
                throw new RuntimeException('User is hidden');
            }
            if ($decoded->blocked) {
                throw new RuntimeException('User is blocked');
            }

            // @todo verified returned nonce matches the one we provided to prevent
            // replay attacks
            $this->cachedUserDetailsResponse = $decoded;
        }

        return $this->cachedUserDetailsResponse;
    }
}
