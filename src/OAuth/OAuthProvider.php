<?php

namespace WikiMedia\OAuth;

use Application;
use Silex\ControllerProviderInterface;
use Silex\ServiceProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Silex\ControllerCollection;
use WikiMedia\OAuth\Exception\RuntimeException;

class OAuthProvider implements ControllerProviderInterface, ServiceProviderInterface
{
    /** @var Application */
    private $app;

    public function connect(\Silex\Application $app)
    {
        /** @var ControllerCollection $controllers */
        $controllers = $app['controllers_factory'];

        $controllers->get('/authorize', [$this, 'authorize'])
            ->bind('oauth_authorize');
        $controllers->get('/callback', [$this, 'callback']);

        return $controllers;
    }

    public function register(\Silex\Application $app)
    {
        $app['oauth'] = function () use ($app) {
            return new MediaWiki([
                'identifier' => $app['oauth.identifier'],
                'secret' => $app['oauth.secret'],
                'callback_uri' => $app['oauth.callback_uri'],
                'baseUrl' => $app['oauth.base_url'],
            ]);
        };
    }

    public function boot(\Silex\Application $app)
    {
        if ($app instanceof Application) {
            $this->app = $app;
        } else {
            throw new RuntimeException('Expected custom Application class');
        }
    }

    public function authorize()
    {
        $server = $this->app['oauth'];

        $temp = $server->getTemporaryCredentials();
        $this->app['session']->set('oauth.credentials.temp', $temp);

        return $this->app->redirect($server->getAuthorizationUrl($temp));
    }

    public function callback(Request $request)
    {
        $token = $request->query->get('oauth_token');
        $verifier = $request->query->get('oauth_verifier');
        if (!$token || !$verifier) {
            throw new RuntimeException('Invalid OAuth callback');
        }

        $session = $this->app['session'];
        $temporaryCredentials = $session->get('oauth.credentials.temp');
        if (!$temporaryCredentials) {
            throw new RuntimeException('No credentials in session');
        }

        $tokenCredentials = $this->app['oauth']->getTokenCredentials(
            $temporaryCredentials,
            $token,
            $verifier
        );
        $session->set('oauth.credentials', $tokenCredentials);

        return $this->app->redirect($this->app->path(
            $this->app['oauth.login_complete_redirect']
        ));
    }
}
