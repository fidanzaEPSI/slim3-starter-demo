<?php 

namespace App;

use DI\ContainerBuilder;
use Interop\Container\ContainerInterface as Container;

use League\Fractal\Manager;
use Predis\Client;
use Slim\Flash\Messages;
use Monolog\{Handler\FingersCrossedHandler, Handler\StreamHandler, Logger};
use App\Support\{NotFound, Storage\Cache, Storage\Session, Extensions\VarDump};
use Slim\Views\{Twig, TwigExtension};
use Slim\Http\{Request, Response};
use App\Database\Eloquent;
use Illuminate\Database\Capsule\Manager as Capsule;
use Slim\Csrf\Guard;
use App\Validation\Validator;
use App\Support\Email\Mailer;
use Twig_Extension_Debug;
use Swift_SmtpTransport;
use Swift_Mailer;


class App extends \DI\Bridge\Slim\App
{

    protected function configureContainer(ContainerBuilder $builder)
    {
        $config = require_once __DIR__ . '/Settings.php';

        $dependencies = [
            Twig::class => function (Container $container) use ($config) {
                $view = new Twig(['../resources/views', '../resources/assets'], $config['twig_config']);
                $view->addExtension(new TwigExtension(
                    $container->get('router'),
                    $container->get('request')->getUri()
                ));
                $view->addExtension(new Twig_Extension_Debug());
                $view->addExtension(new VarDump());
                $view->getEnvironment()->addGlobal('APP_NAME', getenv('APP_NAME'));
                $view->getEnvironment()->addGlobal('flash', $container->get(Messages::class));
                return $view;
            },

            Eloquent::class => function () use ($config)  {
                 $capsule = new Capsule;
                 $capsule->addConnection($config['db']);
                 $capsule->setAsGlobal();
                 $capsule->bootEloquent();
                 return $capsule;
             },

            Guard::class => function() {
                $guard = new Guard;
                $guard->setFailureCallable(function (Request $request, Response $response, callable $next) {
                    $request = $request->withAttribute('csrf_status', false);
                    return $next($request, $response);
                });
                return $guard;
            },

            Messages::class => function() {
                return new Messages;
            },

            Session::class => function() {
                return new Session;
            },

            Validator::class => function(Container $container) {
                return new Validator($container->get(Session::class));
            },

            Logger::class => function() {
                $logger = new Logger('logger');
                $filename = __DIR__ . '/../logs/error.log';
                $stream = new StreamHandler($filename, Logger::DEBUG);
                $fingersCrossed = new FingersCrossedHandler(
                    $stream, Logger::ERROR);
                $logger->pushHandler($fingersCrossed);
                return $logger;
            },

            'notFoundHandler' => function(Container $container){
                return new NotFound($container->get(Twig::class));
            },

            'errorHandler' => function(Container $container) use ($config) {
                return new Support\Error($config['settings']['settings.displayErrorDetails'],$container->get(Logger::class));
            },

            'mail' => function (Container $container) use ($config) {

                $transport = (Swift_SmtpTransport::newInstance($config['mail']['host'], $config['mail']['port']))
                    ->setUsername($config['mail']['username'])
                    ->setPassword($config['mail']['password']);

                $swift = Swift_Mailer::newInstance($transport);

                return (new Mailer($swift, $container->get(Twig::class)))
                    ->alwaysFrom($config['mail']['from']['address'], $config['mail']['from']['name']);
            },

            'fractal' => function () {
                return new Manager();
            },

            'cache' => function () use ($config) {
                $client = new Client([
                    'scheme' => 'tcp',
                    'host' => $config['redis']['host'],
                    'port' => $config['redis']['port'],
                    'password' => $config['redis']['password'],
                ]);

                return new Cache($client);
            },


        ];

        $builder->addDefinitions($config['settings']);
        $builder->addDefinitions($dependencies);
    }
}