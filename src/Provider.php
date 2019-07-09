<?php

namespace SocialiteProviders\Wework;

use Laravel\Socialite\Two\ProviderInterface;
use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;
use function GuzzleHttp\json_decode;

class Provider extends AbstractProvider implements ProviderInterface
{
    const IDENTIFIER = 'WEWORK';

    protected $scopes = [];

    protected function getAuthUrl($state)
    {
        if (!empty($this->scopes)) {
            return $this->getOAuthUrl($state);
        }
        return $this->getQrConnectUrl($state);
    }

    protected function getOAuthUrl($state)
    {
        $queries = [
            'appid' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'response_type' => 'code',
            'scope' => $this->formatScopes($this->scopes, $this->scopeSeparator),
            'agentid' => $this->getAgentId(),
            'state' => $state,
        ];
        return sprintf('https://open.weixin.qq.com/connect/oauth2/authorize?%s#wechat_redirect', http_build_query($queries));
    }

    protected function getQrConnectUrl($state)
    {
        $queries = [
            'appid' => $this->clientId,
            'agentid' => $this->getAgentId(),
            'redirect_uri' => $this->redirectUrl,
            'state' => $state,
        ];
        return 'https://open.work.weixin.qq.com/wwopen/sso/qrConnect?'.http_build_query($queries);
    }

    protected function getTokenUrl()
    {
        return 'https://qyapi.weixin.qq.com/cgi-bin/gettoken';
    }

    protected function getUserByToken($token)
    {
        $userInfo = $this->getUserInfo($token);
        if (!isset($userInfo['UserId'])) {
            abort(401);
        }
        return $this->getUserDetail($token, $userInfo['UserId']);
    }

    protected function getUserInfo($token)
    {
        $response = $this->getHttpClient()->get('https://qyapi.weixin.qq.com/cgi-bin/user/getuserinfo', [
            'query' => array_filter([
                'access_token' => $token,
                'code' => $this->getCode(),
            ]),
        ]);
        return json_decode($response->getBody(), true);
    }

    protected function getUserDetail($token, $userId)
    {
        $response = $this->getHttpClient()->post('https://qyapi.weixin.qq.com/cgi-bin/user/get', [
            'query' => [
                'access_token' => $token,
                'userid' => $userId
            ]
        ]);

        $user = json_decode($response->getBody(), true);
        $department = $this->getUserDepartment($token, $user);
        $user['department'] = $department;
        return $user;
    }

    protected function getUserDepartment($token, $user) {
        $departmentIds = $user['department'];
        $_department = [];
        $response = $this->getHttpClient()->get('https://qyapi.weixin.qq.com/cgi-bin/department/list', [
            'query' => [
                'access_token' => $token
            ]
        ]);
        $departments = json_decode($response->getBody(), true)['department'];
        foreach ($departments as $departInfo) {
            if (in_array($departInfo['id'], $departmentIds)) {
                array_push($_department, $departInfo['name']);
            }
        }
        return $_department;
    }

    protected function mapUserToObject(array $user)
    {
        return (new User())->setRaw($user)->map([
            'id'       => $user['userid'],
            'name'     => $user['name'],
            'nickname' => $user['alias'],
            'email'    => $user['email'],
            'avatar'   => $user['avatar'],
        ]);
    }

    protected function getTokenFields($code)
    {
        return [
            'corpid' => $this->clientId,
            'corpsecret' => $this->clientSecret
        ];
    }

    public function getAccessTokenResponse($code)
    {
        $response = $this->getHttpClient()->get($this->getTokenUrl(), [
            'query' => $this->getTokenFields($code),
        ]);

        $this->credentialsResponseBody = json_decode($response->getBody(), true);

        return $this->credentialsResponseBody;
    }
    
    protected function getAgentId()
    {
        return $this->getConfig('agent_id', '');
    }
    
    public static function additionalConfigKeys()
    {
        return ['agent_id'];
    }
}
