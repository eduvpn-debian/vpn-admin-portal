<?php

/**
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2017, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace SURFnet\VPN\Admin;

use SURFnet\VPN\Common\Http\Exception\HttpException;
use SURFnet\VPN\Common\Http\HtmlResponse;
use SURFnet\VPN\Common\Http\InputValidation;
use SURFnet\VPN\Common\Http\RedirectResponse;
use SURFnet\VPN\Common\Http\Request;
use SURFnet\VPN\Common\Http\Response;
use SURFnet\VPN\Common\Http\Service;
use SURFnet\VPN\Common\Http\ServiceModuleInterface;
use SURFnet\VPN\Common\HttpClient\ServerClient;
use SURFnet\VPN\Common\TplInterface;

class AdminPortalModule implements ServiceModuleInterface
{
    /** @var \SURFnet\VPN\Common\TplInterface */
    private $tpl;

    /** @var \SURFnet\VPN\Common\HttpClient\ServerClient */
    private $serverClient;

    /** @var Graph */
    private $graph;

    public function __construct(TplInterface $tpl, ServerClient $serverClient, Graph $graph)
    {
        $this->tpl = $tpl;
        $this->serverClient = $serverClient;
        $this->graph = $graph;
    }

    public function init(Service $service)
    {
        $service->get(
            '/',
            function (Request $request) {
                return new RedirectResponse($request->getRootUri().'connections', 302);
            }
        );

        $service->get(
            '/connections',
            function () {
                // get the fancy profile name
                $profileList = $this->serverClient->get('profile_list');

                $idNameMapping = [];
                foreach ($profileList as $profileId => $profileData) {
                    $idNameMapping[$profileId] = $profileData['displayName'];
                }

                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnConnections',
                        [
                            'idNameMapping' => $idNameMapping,
                            'connections' => $this->serverClient->get('client_connections'),
                        ]
                    )
                );
            }
        );

        $service->get(
            '/info',
            function () {
                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnInfo',
                        [
                            'profileList' => $this->serverClient->get('profile_list'),
                        ]
                    )
                );
            }
        );

        $service->get(
            '/users',
            function () {
                $userList = $this->serverClient->get('user_list');

                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnUserList',
                        [
                            'userList' => $userList,
                        ]
                    )
                );
            }
        );

        $service->get(
            '/user',
            function (Request $request) {
                $userId = $request->getQueryParameter('user_id');
                InputValidation::userId($userId);

                $clientCertificateList = $this->serverClient->get('client_certificate_list', ['user_id' => $userId]);
                $userMessages = $this->serverClient->get('user_messages', ['user_id' => $userId]);

                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnUserConfigList',
                        [
                            'userId' => $userId,
                            'userMessages' => $userMessages,
                            'clientCertificateList' => $clientCertificateList,
                            'hasTotpSecret' => $this->serverClient->get('has_totp_secret', ['user_id' => $userId]),
                            'hasYubiKeyId' => $this->serverClient->get('has_yubi_key_id', ['user_id' => $userId]),
                            'isDisabled' => $this->serverClient->get('is_disabled_user', ['user_id' => $userId]),
                        ]
                    )
                );
            }
        );

        $service->post(
            '/user',
            function (Request $request) {
                $userId = $request->getPostParameter('user_id');
                InputValidation::userId($userId);
                $userAction = $request->getPostParameter('user_action');
                // no need to explicitly validate userAction, as we will have
                // switch below with whitelisted acceptable values

                switch ($userAction) {
                    case 'disableUser':
                        $this->serverClient->post('disable_user', ['user_id' => $userId]);
                        // kill all active connections for this user
                        $clientConnections = $this->serverClient->get('client_connections');
                        foreach ($clientConnections as $profile) {
                            foreach ($profile['connections'] as $connection) {
                                if ($connection['user_id'] === $userId) {
                                    $this->serverClient->post('kill_client', ['common_name' => $connection['common_name']]);
                                }
                            }
                        }
                        break;

                    case 'enableUser':
                        $this->serverClient->post('enable_user', ['user_id' => $userId]);
                        break;

                    case 'deleteTotpSecret':
                        $this->serverClient->post('delete_totp_secret', ['user_id' => $userId]);
                        break;

                    case 'deleteYubiId':
                        $this->serverClient->post('delete_yubi_key_id', ['user_id' => $userId]);
                        break;

                    default:
                        throw new HttpException('unsupported "user_action"', 400);
                }

                $returnUrl = sprintf('%susers', $request->getRootUri());

                return new RedirectResponse($returnUrl);
            }
        );

        $service->post(
            '/setCertificateStatus',
            function (Request $request, array $hookData) {
                $commonName = $request->getPostParameter('commonName');
                InputValidation::commonName($commonName);

                $newState = $request->getPostParameter('newState');
                if ('enable' === $newState) {
                    $this->serverClient->post('enable_client_certificate', ['common_name' => $commonName]);
                } else {
                    $this->serverClient->post('disable_client_certificate', ['common_name' => $commonName]);
                    $this->serverClient->post('kill_client', ['common_name' => $commonName]);
                }

                return new RedirectResponse($request->getHeader('HTTP_REFERER'), 302);
            }
        );

        $service->get(
            '/log',
            function () {
                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnLog',
                        [
                            'date_time' => null,
                            'ip_address' => null,
                        ]
                    )
                );
            }
        );

        $service->get(
            '/stats',
            function () {
                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnStats',
                        [
                            'stats' => $this->serverClient->get('stats'),
                        ]
                    )
                );
            }
        );

        $service->get(
            '/stats/traffic',
            function () {
                $response = new Response(
                    200,
                    'image/png'
                );

                $stats = $this->serverClient->get('stats');
                $dateByteList = [];
                foreach ($stats['days'] as $v) {
                    $dateByteList[$v['date']] = $v['bytes_transferred'];
                }

                $imageData = $this->graph->draw(
                    $dateByteList,
                    function ($v) {
                        $suffix = 'B';
                        if ($v > 1024) {
                            $v /= 1024;
                            $suffix = 'kiB';
                        }
                        if ($v > 1024) {
                            $v /= 1024;
                            $suffix = 'MiB';
                        }
                        if ($v > 1024) {
                            $v /= 1024;
                            $suffix = 'GiB';
                        }
                        if ($v > 1024) {
                            $v /= 1024;
                            $suffix = 'TiB';
                        }

                        return sprintf('%d %s ', $v, $suffix);
                    }
                );
                $response->setBody($imageData);

                return $response;
            }
        );

        $service->get(
            '/stats/users',
            function () {
                $response = new Response(
                    200,
                    'image/png'
                );

                $stats = $this->serverClient->get('stats');
                $dateUsersList = [];
                foreach ($stats['days'] as $v) {
                    $dateUsersList[$v['date']] = $v['unique_user_count'];
                }

                $imageData = $this->graph->draw(
                    $dateUsersList
                );
                $response->setBody($imageData);

                return $response;
            }
        );

        $service->get(
            '/messages',
            function () {
                $motdMessages = $this->serverClient->get('system_messages', ['message_type' => 'motd']);

                // we only want the first one
                if (0 === count($motdMessages)) {
                    $motdMessage = false;
                } else {
                    $motdMessage = $motdMessages[0];
                }

                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnMessages',
                        [
                            'motdMessage' => $motdMessage,
                        ]
                    )
                );
            }
        );

        $service->post(
            '/messages',
            function (Request $request) {
                $messageAction = $request->getPostParameter('message_action');
                switch ($messageAction) {
                    case 'set':
                        // we can only have one "motd", so remove the ones that
                        // already exist
                        $motdMessages = $this->serverClient->get('system_messages', ['message_type' => 'motd']);
                        foreach ($motdMessages as $motdMessage) {
                            $this->serverClient->post('delete_system_message', ['message_id' => $motdMessage['id']]);
                        }

                        // no need to validate, we accept everything
                        $messageBody = $request->getPostParameter('message_body');
                        $this->serverClient->post('add_system_message', ['message_type' => 'motd', 'message_body' => $messageBody]);
                        break;
                    case 'delete':
                        $messageId = InputValidation::messageId($request->getPostParameter('message_id'));

                        $this->serverClient->post('delete_system_message', ['message_id' => $messageId]);
                        break;
                    default:
                        throw new HttpException('unsupported "message_action"', 400);
                }

                $returnUrl = sprintf('%smessages', $request->getRootUri());

                return new RedirectResponse($returnUrl);
            }
        );

        $service->post(
            '/log',
            function (Request $request) {
                $dateTime = $request->getPostParameter('date_time');
                InputValidation::dateTime($dateTime);
                $ipAddress = $request->getPostParameter('ip_address');
                InputValidation::ipAddress($ipAddress);

                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnLog',
                        [
                            'date_time' => $dateTime,
                            'ip_address' => $ipAddress,
                            'result' => $this->serverClient->get('log', ['date_time' => $dateTime, 'ip_address' => $ipAddress]),
                        ]
                    )
                );
            }
        );
    }
}
