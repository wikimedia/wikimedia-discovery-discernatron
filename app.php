<?php

use Symfony\Component\HttpFoundation\Request;

require_once __DIR__.'/vendor/autoload.php';

// @todo traits are convenient, but perhaps things would be a bit
// cleaner to use native Silex App and be explicit about things we
// currently access through the traits.
$app = new WikiMedia\RelevanceScoring\Application();

$app->register(new Silex\Provider\SessionServiceProvider());

$app->register(new Silex\Provider\ServiceControllerServiceProvider());

$oauthProvider = new WikiMedia\OAuth\OAuthProvider();
$app->register($oauthProvider, [
    'oauth.callback_uri' => 'oob',
    'oauth.login_complete_redirect' => 'instructions',
]);
$app->mount('/oauth', $oauthProvider);

$app->register(new Silex\Provider\DoctrineServiceProvider());

$app->register(new Silex\Provider\UrlGeneratorServiceProvider());

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/views',
    'twig.options' => [
        'cache' => __DIR__.'/cache/twig',
    ],
));

$app->register(new Silex\Provider\FormServiceProvider());

$app->register(new Silex\Provider\ValidatorServiceProvider());

$app->register(new Silex\Provider\TranslationServiceProvider(), array(
    'translator.domains' => array(),
));

$app['guzzle.cache'] = true;
$app['guzzle'] = function () use ($app) {
    $stack = new GuzzleHttp\HandlerStack(GuzzleHttp\choose_handler());

    if ($app['guzzle.cache']) {
        // cache requests for 24 hours, makes debugging easier without repeatedly
        // hitting the search engines and getting blocked
        $stack->push(new Kevinrob\GuzzleCache\CacheMiddleware(
            new Kevinrob\GuzzleCache\Strategy\GreedyCacheStrategy(
                new Kevinrob\GuzzleCache\Storage\DoctrineCacheStorage(
                    new Doctrine\Common\Cache\FilesystemCache(__DIR__.'/cache/guzzle')
                ),
                24 * 60 * 60
            )
        ));
    }

    return new GuzzleHttp\Client([
        'timeout' => 2.0,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/48.0.2564.116 Safari/537.36',
        ],
        'handler' => $stack,
    ]);
};

$relevanceScoringProvider = new WikiMedia\RelevanceScoring\RelevanceScoringProvider();
$app->register($relevanceScoringProvider, [
    'search.wikis' => [
        'enwiki' => 'https://en.wikipedia.org/w/api.php',
    ],
]);
$app->mount('/', $relevanceScoringProvider);

$app['console.commands'] = function () {
    return [];
};

$ini = parse_ini_file(__DIR__.'/app.ini');
foreach ($ini as $key => $value) {
    $app[$key] = $value;
}

if ($app['debug']) {
    $app->register(new Silex\Provider\HttpFragmentServiceProvider());
    $app->register(new Silex\Provider\ServiceControllerServiceProvider());
    $app->register(new Silex\Provider\WebProfilerServiceProvider(), [
        'profiler.cache_dir' => __DIR__.'/cache/profiler',
    ]);
    $app->register(new Sorien\Provider\DoctrineProfilerServiceProvider());
}

// bare bones authentication / firewall
$app->before(function (Request $request) use ($app) {
    if (isset($app['canonical_domain'])) {
        if (strtolower($request->getHost()) !== strtolower($app['canonical_domain'])) {
            return $app->redirect('https://'.$app['canonical_domain']);
        }
    }

    $uri = $request->getRequestUri();
    if ($uri === '/login' || substr($uri, 0, 7) === '/oauth/') {
        return;
    }
    $session = $app['session'];
    $cred = $session->get('oauth.credentials');
    if ($cred === null) {
        return $app->redirect($app->path('login'));
    }
    $user = $session->get('user');
    // reauthorize the token every 24 hours
    $timeout = 24 * 60 * 60;
    if ($user === null || $user->extra['issued'] + $timeout < time()) {
        try {
            $user = $app['oauth']->getUserDetails($cred);
        } catch (\Firebase\JWT\BeforeValidException $e) {
            throw new RuntimeException('Bad timestamp on JWT token. Clock out of sync?', 0, $e);
        } catch (\Exception $e) {
            $session->remove('user');
            $session->remove('oauth.credentials');

            return $app->redirect($app->path('oauth_authorize'));
        }
        $user->extra['last_authorized'] = time();
        $app['search.repository.users']->updateUser($user);
        $session->set('user', $user);
    }

    $app['twig']->addGlobal('user', $user);

    return;
});

$app->get('/', function () use ($app) {
    return $app->redirect($app->path('random_query'));
})
->bind('root');

$app->get('/login', function () use ($app) {
    return $app['twig']->render('splash.twig', [
        'domain' => parse_url($app['oauth.base_url'], PHP_URL_HOST),
    ]);
})
->bind('login');

$app->get('/logout', function () use ($app) {
    $session = $app['session'];
    $session->remove('user');
    $session->remove('oauth.credentials');

    return $app->redirect('/');
})
->bind('logout');

// Place for local hacks
if (file_exists(__DIR__.'/app.debug.php')) {
    require __DIR__.'/app.debug.php';
}

return $app;
