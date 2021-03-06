<?php
/**
 * Module class
 *
 * Copyright 2013 Oleg Lobach <oleg@lobach.info>
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 *
 * @copyright  Copyright (c) 2013 Oleg Lobach <oleg@lobach.info>
 * @license    Apache License V2 <http://www.apache.org/licenses/LICENSE-2.0.html>
 * @author     Oleg Lobach <oleg@lobach.info>
 * @version    0.3.0
 * @since      0.1.0
 */

namespace Yassa\Rollbar;

use ProspectOne\UserModule\Exception\LogicException;
use Rollbar\Payload\Level;
use Yassa\Rollbar\Options\ModuleOptions;
use Zend\ModuleManager\Feature\AutoloaderProviderInterface;
use Zend\ModuleManager\Feature\ConfigProviderInterface;
use Zend\Mvc\MvcEvent;
use Zend\Http\Response;
use ZF\ApiProblem\ApiProblemResponse;
use Throwable;

/**
 * Class Module
 *
 * @package Yassa\Rollbar
 */
class Module implements AutoloaderProviderInterface, ConfigProviderInterface
{
    /**
     * @var RollbarNotifier
     */
    private $rollbar;

    /**
     * @var ModuleOptions
     */
    private $options;

    /**
     * @param MvcEvent $event
     */
    public function onBootstrap(MvcEvent $event)
    {
        /** @var \Zend\Mvc\ApplicationInterface $application */
        $application = $event->getApplication();

        /** @var ModuleOptions $options */
        $options = $application->getServiceManager()->get('Yassa\Rollbar\Options\ModuleOptions');

        if ($options->enabled) {
            /** @var RollbarNotifier $rollbar */
            $rollbar = $application->getServiceManager()->get('RollbarNotifier');
            $this->rollbar = $rollbar;
            $this->options = $options;

            if ($options->exceptionhandler) {
                set_exception_handler(array($this, "report_exception"));

                $eventManager = $application->getEventManager();
                $eventManager->attach(MvcEvent::EVENT_DISPATCH_ERROR, function(MvcEvent $event) use ($rollbar) {
                    $exception = $event->getResult()->exception ?? $event->getParam("exception");
                    if ($exception) {
                        $rollbar->report_exception($exception);

                        $content = json_encode(['Error' => 'Fatal error. Please try again later.']);
                        $response = new Response();
                        $response->setStatusCode(Response::STATUS_CODE_500);
                        $response->getHeaders()->addHeaders(['Content-type:application/json']);
                        $response->setContent($content);
                        $event->setResult($response);
                    }
                });
            }
            if ($options->errorhandler) {
                set_error_handler(array($rollbar, "report_php_error"));
            }
            if ($options->shutdownfunction) {
                register_shutdown_function($this->shutdownHandler($rollbar));
            }
            if ($options->catch_apigility_errors) {
                $eventManager = $application->getEventManager();
                $eventManager->attach(MvcEvent::EVENT_FINISH, function (MvcEvent $event) use ($rollbar) {
                    $result = $event->getResult();
                    if ($result instanceof ApiProblemResponse) {
                        $problem = $result->getApiProblem();
                        $problem->setDetailIncludesStackTrace(true);
                        $message = $problem->toArray();
                        if (end($message) == LogicException::MESSAGE) {
                            $response = new Response();
                            $response->setStatusCode(Response::STATUS_CODE_401);
                            $response->getHeaders()->addHeaders(['Content-type:application/json']);
                            $content = "Unauthorized";
                            $response->setContent($content);
                            $event->setResponse($response);
                            return;
                        }
                        if (isset($message['trace'])) {
                            $message['trace'] = json_encode($message['trace']);
                        } else {
                            $message['trace'] = "";
                        }

                        $notLoggedStatuses = [400, 401, 404];
                        if (!in_array($message['status'], $notLoggedStatuses)) {
                            $rollbar->report_message($message['title'] . " : " . $message['detail'], Level::error(), $message['trace']);
                            $message['status'] = Response::STATUS_CODE_500;
                        }
                        $problem->setDetailIncludesStackTrace(false);
                        $message = $problem->toArray();
                        $detail = (!is_array($message['detail'])) ? $message['detail'] : $message['detail']['Error'];
                        $error = $message['title'] . "\n\n" . $detail ?? "";

                        $content = json_encode(['Error' => $error]);
                        $response = new Response();
                        $response->setStatusCode($message['status']);
                        $response->getHeaders()->addHeaders(['Content-type:application/json']);
                        $response->setContent($content);
                        $event->setResponse($response);
                    }
                });
            }
        }
    }

    /**
     * Returns configuration to merge with application configuration
     *
     * @return array|\Traversable
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Return an array for passing to Zend\Loader\AutoloaderFactory.
     *
     * @return array
     */
    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\ClassMapAutoloader' => array(
                __DIR__ . '/autoload_classmap.php',
            ),
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }

    /**
     * @param  RollbarNotifier $rollbar
     * @return callable
     */
    protected function shutdownHandler($rollbar)
    {
        return function () use ($rollbar) {
            // Catch any fatal errors that are causing the shutdown
            $last_error = error_get_last();
            if (!is_null($last_error)) {
                switch ($last_error['type']) {
                    case E_ERROR:
                        $rollbar->report_php_error(
                            $last_error['type'],
                            $last_error['message'],
                            $last_error['file'],
                            $last_error['line']
                        );
                    break;
                }
            }
        };
    }

    /**
     * @param $exc
     * @param null $extra_data
     * @param null $payload_data
     * @return string
     */
    public function report_exception(Throwable $exc, $extra_data = null, $payload_data = null)
    {
        if (in_array(get_class($exc), $this->options->ignored_exceptions)) {
            return "";
        }

        if (!empty($exc->getPrevious()) && in_array(get_class($exc->getPrevious()), $this->options->ignored_exceptions)) {
            return "";
        }

        return $this->rollbar->report_exception($exc, $extra_data = null, $payload_data = null);
    }
}
