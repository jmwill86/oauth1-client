<?php

namespace League\OAuth1\Client\Server;

use Guzzle\Service\Client as GuzzleClient;
use Guzzle\Http\Exception\BadResponseException;
use League\OAuth1\Client\Credentials\ClientCredentialsInterface;
use League\OAuth1\Client\Credentials\ClientCredentials;
use League\OAuth1\Client\Credentials\CredentialsInterface;
use League\OAuth1\Client\Credentials\CredentialsException;
use League\OAuth1\Client\Credentials\TemporaryCredentials;
use League\OAuth1\Client\Credentials\TokenCredentials;
use League\OAuth1\Client\Signature\HmacSha1Signature;
use League\OAuth1\Client\Signature\SignatureInterface;

abstract class Server
{
    /**
     * Client credentials.
     *
     * @var ClientCredentials
     */
    protected $clientCredentials;

    /**
     * Signature
     *
     * @var SignatureInterface
     */
    protected $signature;

    /**
     * The response type for data returned from API calls.
     *
     * @var string
     */
    protected $responseType = 'json';

    /**
     * Create a new server instance.
     *
     * @param  ClientCredentialsInterface|array  $clientCredentials
     * @param  SignatureInterface  $signature
     */
    public function __construct($clientCredentials, SignatureInterface $signature = null)
    {
        // If our options are in fact an instance of client credentials,
        // our options are actually our second argument.
        if (is_array($clientCredentials)) {
            $clientCredentials = $this->createClientCredentials($clientCredentials);
        } elseif ( ! $clientCredentials instanceof ClientCredentialsInterface) {
            throw new \InvalidArgumentException("Client credentials must be an array or valid object.");
        }

        $this->clientCredentials = $clientCredentials;
        $this->signature = $signature ?: new HmacSha1Signature($clientCredentials);
    }

    /**
     * Gets temporary credentials by performing a request to
     * the server.
     *
     * @return  TemporaryCredentials
     */
    public function getTemporaryCredentials()
    {
        $uri = $this->urlTemporaryCredentials();

        $client = $this->createHttpClient();

        try {
            $response = $client->post($uri, array(
                'Authorization' => $this->temporaryCredentialsProtocolHeader($uri),
            ))->send();
        } catch (BadResponseException $e) {
            return $this->handleTemporaryCredentialsBadResponse($e);
        }

        return $this->createTemporaryCredentials($response->getBody());
    }

    /**
     * Get the authorization URL by passing in the temporary credentials
     * identifier or an object instance.
     *
     * @param  TemporaryCredentials|string  $temporaryIdentifier
     * @return string
     */
    public function getAuthorizationUrl($temporaryIdentifier)
    {
        // Somebody can pass through an instance of temporary
        // credentials and we'll extract the identifier from there.
        if ($temporaryIdentifier instanceof TemporaryCredentials) {
            $temporaryIdentifier = $temporaryIdentifier->getIdentifier();
        }

        $parameters = array('oauth_token' => $temporaryIdentifier);

        return $this->urlAuthorization().'?'.http_build_query($parameters);
    }

    /**
     * Redirect the client to the authorization URL.
     *
     * @param  TemporaryCredentials|string  $temporaryIdentifier
     * @return void
     */
    public function authorize($temporaryIdentifier)
    {
        $url = $this->getAuthorizationUrl($temporaryIdentifier);

        header('Location: '.$url);
        exit;
    }

    /**
     * Retrieves token credentials by passing in the temporary credentials,
     * the temporary credentials identifier as passed back by the server
     * and finally the verifier code.
     *
     * @param  TemporaryCredentials  $temporaryCredentials
     * @param  string  $temporaryIdentifier
     * @param  string  $verifier
     * @return TokenCredentials
     */
    public function getTokenCredentials(TemporaryCredentials $temporaryCredentials, $temporaryIdentifier, $verifier)
    {
        if ($temporaryIdentifier !== $temporaryCredentials->getIdentifier()) {
            throw new \InvalidArgumentException("Temporary identifier passed back by server does not match that of stored temporary credentials. Potential man-in-the-middle.");
        }

        $uri = $this->urlTokenCredentials();
        $bodyParameters = array('oauth_verifier' => $verifier);

        $client = $this->createHttpClient();

        $header = $this->protocolHeader('POST', $uri, $temporaryCredentials, $bodyParameters);

        try {
            $response = $client->post($uri, array(
                'Authorization' => $header,
            ), $bodyParameters)->send();
        } catch (BadResponseException $e) {
            return $this->handleTokenCredentialsBadResponse($e);
        }

        return $this->createTokenCredentials($response->getBody());
    }

    /**
     * Get user details by providing valid token credentials.
     *
     * @param  TokenCredentials  $tokenCredentials
     * @return User
     */
    public function getUserDetails(TokenCredentials $tokenCredentials)
    {
        $uri = $this->urlUserDetails();

        $client = $this->createHttpClient();

        try {
            $response = $client->get($uri, array(
                'Authorization' => $this->protocolHeader('GET', $uri, $tokenCredentials),
            ))->send();
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $body = $response->getBody();
            $statusCode = $response->getStatusCode();

            throw new \Exception("Received error [$body] with status code [$statusCode] when retrieving token credentials.");
        }

        switch ($this->responseType) {
            case 'json':
                $data = $response->json();
                break;

            case 'xml':
                $data = $response->xml();
                break;

            case 'string':
                parse_str($response->getBody(), $data);
                break;

            default:
                throw new \InvalidArgumentException("Invalid response type [{$this->responseType}].");
        }

        return $this->userDetails($data, $tokenCredentials);
    }

    /**
     * Get the client credentials associated with the server.
     *
     * @return  ClientCredentialsInterface
     */
    public function getClientCredentials()
    {
        return $this->clientCredentials;
    }

    /**
     * Get the signature associated with the server.
     *
     * @return  SignatureInterface
     */
    public function getSignature()
    {
        return $this->signature;
    }

    /**
     * Creates a Guzzle HTTP client for the given URL.
     *
     * @return GuzzleClient
     */
    public function createHttpClient()
    {
        return new GuzzleClient();
    }

    /**
     * Creates a client credentials instance from an array of credentials.
     *
     * @param  array  $clientCredentials
     * @return ClientCredentials
     */
    protected function createClientCredentials(array $clientCredentials)
    {
        $keys = array('identifier', 'secret', 'callback_uri');

        foreach ($keys as $key) {
            if ( ! isset($clientCredentials[$key])) {
                throw new \InvalidArgumentException("Missing client credentials key [$key] from options.");
            }
        }

        $_clientCredentials = new ClientCredentials;
        $_clientCredentials->setIdentifier($clientCredentials['identifier']);
        $_clientCredentials->setSecret($clientCredentials['secret']);
        $_clientCredentials->setCallbackUri($clientCredentials['callback_uri']);

        return $_clientCredentials;
    }

    /**
     * Handle a bad response coming back when getting temporary credentials.
     *
     * @param  BadResponseException
     * @return void
     * @throws CredentialsException
     */
    protected function handleTemporaryCredentialsBadResponse(BadResponseException $e)
    {
        $response = $e->getResponse();
        $body = $response->getBody();
        $statusCode = $response->getStatusCode();

        throw new CredentialsException("Received HTTP status code [$statusCode] with message \"$body\" when getting temporary credentials.");
    }

    /**
     * Creates temporary credentials from the body response.
     *
     * @param  string  $body
     * @return TemporaryCredentials
     */
    protected function createTemporaryCredentials($body)
    {
        parse_str($body, $data);

        if ( ! $data || ! is_array($data)) {
            throw new CredentialsException("Unable to parse temporary credentials response.");
        }

        if ( ! isset($data['oauth_callback_confirmed']) || $data['oauth_callback_confirmed'] != 'true') {
            throw new CredentialsException("Error in retrieving temporary credentials.");
        }

        $temporaryCredentials = new TemporaryCredentials;
        $temporaryCredentials->setIdentifier($data['oauth_token']);
        $temporaryCredentials->setSecret($data['oauth_token_secret']);
        return $temporaryCredentials;
    }

    /**
     * Handle a bad response coming back when getting token credentials.
     *
     * @param  BadResponseException
     * @return void
     * @throws CredentialsException
     */
    protected function handleTokenCredentialsBadResponse(BadResponseException $e)
    {
        $response = $e->getResponse();
        $body = $response->getBody();
        $statusCode = $response->getStatusCode();

        throw new CredentialsException("Received HTTP status code [$statusCode] with message \"$body\" when getting token credentials.");
    }

    /**
     * Creates token credentials from the body response.
     *
     * @param  string  $body
     * @return TokenCredentials
     */
    protected function createTokenCredentials($body)
    {
        parse_str($body, $data);

        if ( ! $data || ! is_array($data)) {
            throw new CredentialsException("Unable to parse token credentials response.");
        }

        if (isset($data['error'])) {
            throw new CredentialsException("Error [{$data['error']}] in retrieving token credentials.");
        }

        $tokenCredentials = new TokenCredentials;
        $tokenCredentials->setIdentifier($data['oauth_token']);
        $tokenCredentials->setSecret($data['oauth_token_secret']);
        return $tokenCredentials;
    }

    /**
     * Get the base protocol parameters for an OAuth request.
     * Each request builds on these parameters.
     *
     * @return array
     * @see    OAuth 1.0 RFC 5849 Section 3.1
     */
    protected function baseProtocolParameters()
    {
        $dateTime = new \DateTime;

        return array(
            'oauth_consumer_key' => $this->clientCredentials->getIdentifier(),
            'oauth_nonce' => $this->nonce(),
            'oauth_signature_method' => $this->signature->method(),
            'oauth_timestamp' => $dateTime->format('U'),
            'oauth_version' => '1.0',
        );
    }

    /**
     * Any additional required protocol parameters for an
     * OAuth request.
     *
     * @return array
     */
    protected function additionalProtocolParameters()
    {
        return array();
    }

    /**
     * Generate the OAuth protocol header for a temporary credentials
     * request, based on the URI.
     *
     * @param  string  $uri
     * @return string
     */
    protected function temporaryCredentialsProtocolHeader($uri)
    {
        $parameters = array_merge($this->baseProtocolParameters(), array(
            'oauth_callback' => $this->clientCredentials->getCallbackUri(),
        ));

        $parameters['oauth_signature'] = $this->signature->sign($uri, $parameters, 'POST');

        return $this->normalizeProtocolParameters($parameters);
    }

    /**
     * Generate the OAuth protocol header for requests other than temporary
     * credentials, based on the URI, method, given credentials & body query
     * string.
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  CredentialsInterface  $credentials
     * @param  array  $bodyCredentials
     * @return string
     */
    protected function protocolHeader($method, $uri, CredentialsInterface $credentials, array $bodyParameters = array())
    {
        $parameters = array_merge($this->baseProtocolParameters(), array(
            'oauth_token' => $credentials->getIdentifier(),
        ));

        $this->signature->setCredentials($credentials);

        $parameters['oauth_signature'] = $this->signature->sign($uri, array_merge($parameters, $bodyParameters), $method);

        return $this->normalizeProtocolParameters($parameters);
    }

    /**
     * Takes an array of protocol parameters and normalizes them
     * to be used as a HTTP header.
     *
     * @param  array  $parameters
     * @return string
     */
    protected function normalizeProtocolParameters(array $parameters)
    {
        array_walk($parameters, function(&$value, $key)
        {
            $value = rawurlencode($key).'="'.rawurlencode($value).'"';
        });

        return 'OAuth '.implode(', ', $parameters);
    }

    /**
     * Generate a random string.
     *
     * @param  int $length
     * @return string
     * @see    OAuth 1.0 RFC 5849 Section 3.3
     */
    protected function nonce($length = 32)
    {
        $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

        return substr(str_shuffle(str_repeat($pool, 5)), 0, $length);
    }

    /**
     * Get the URL for retrieving temporary credentials.
     *
     * @return string
     */
    abstract public function urlTemporaryCredentials();

    /**
     * Get the URL for redirecting the resource owner to authorize the client.
     *
     * @return string
     */
    abstract public function urlAuthorization();

    /**
     * Get the URL retrieving token credentials.
     *
     * @return string
     */
    abstract public function urlTokenCredentials();

    /**
     * Get the URL for retrieving user details.
     *
     * @return string
     */
    abstract public function urlUserDetails();

    /**
     * Take the decoded data from the user details URL and convert
     * it to a User object.
     *
     * @param  mixed  $data
     * @param  TokenCredentials  $tokenCredentials
     * @return User
     */
    abstract public function userDetails($data, TokenCredentials $tokenCredentials);
}