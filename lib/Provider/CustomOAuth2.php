<?php

namespace OCA\SocialLogin\Provider;

use Hybridauth\Adapter\OAuth2;
use Hybridauth\Data;
use Hybridauth\Exception\AuthorizationDeniedException;
use Hybridauth\Exception\UnexpectedApiResponseException;
use Hybridauth\HttpClient\HttpClientInterface;
use Hybridauth\Logger\LoggerInterface;
use Hybridauth\Storage\StorageInterface;
use Hybridauth\User;

class CustomOAuth2 extends OAuth2
{

    public function __construct(
        $config = [],
        HttpClientInterface $httpClient = null,
        StorageInterface $storage = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct($config, $httpClient, $storage, $logger);
        $this->providerId = $this->clientId;
    }

    /**
     * @return User\Profile
     * @throws UnexpectedApiResponseException
     * @throws \Hybridauth\Exception\HttpClientFailureException
     * @throws \Hybridauth\Exception\HttpRequestFailedException
     * @throws \Hybridauth\Exception\InvalidAccessTokenException
     */
    public function getUserProfile()
    {
        $profileUrl = $this->config->get('endpoints')['profile_url'];

        $profileHeaders = ['X-Scope' => $this->config->get('scope')];
        $response = $this->apiRequest($profileUrl, 'GET', [], $profileHeaders);

        $userProfile = new User\Profile();

        $userProfile->identifier = $response->id;
        $userProfile->email = $response->email;
        $userProfile->displayName = $response->first_name . ' ' . $response->last_name . ' / ' . $response->nickname;
        $userProfile->firstName = $response->first_name;
        $userProfile->lastName = $response->last_name;
        $userProfile->language = $response->correspondence_language;

        $data = new Data\Collection($response);
        $groups = $this->getGroups($data);
        if (null !== $groups) {
            $userProfile->data['groups'] = $groups;
        }
        $groupMapping = $this->config->get('group_mapping');
        if ($groupMapping) {
            $userProfile->data['group_mapping'] = $groupMapping;
        }

        return $userProfile;
    }

    protected function getGroups(Data\Collection $data)
    {
        $groups = [];
        $roles = $data->get($this->config->get('groups_claim'));

        if ($data->get('kantonalverband_id') != $this->config->get('kantonalverband_id')) {
            throw new AuthorizationDeniedException('Zugriff nicht erlaubt! Bei Problemen melde dich bei webmaster@pfadi.org');
        } else {
            $groups[] = $data->get('kantonalverband_id');
        }

        foreach ($roles as $role) {
            if (preg_match('/^(Biber|Wolf|Leitwolf|Pfadi|Leitpfadi|Pio)$/', $role->role_name)) {
                throw new AuthorizationDeniedException('Zugriff nicht erlaubt! Bei Problemen melde dich bei webmaster@pfadi.org');
            } else {
                $groupInfo = $this->getGroup($role->group_id);
                if ($groupInfo && $this->isPks($groupInfo)) {
                    if ($role->role_name === 'Coach') {
                        $groups[] = 'coach';
                        // when in child of Abteilung set Abteilung id
                    } else if (sizeof($groupInfo->links->hierarchies) > 3) {
                        $linkedGroup = $this->getGroup($groupInfo->links->hierarchies[2]);
                        if ($linkedGroup->group_type == 'Abteilung') {
                            $groups[] = $linkedGroup->id;
                        } else {
                            $groups[] = $role->group_id;
                        }
                    } else {
                        $groups[] = $role->group_id;
                    }
                }
            }
        }
        return $groups;
    }

    private function getGroup($id)
    {
        $url = $this->config->get('groups_url') . '/' . $id . '.json?token=' . $this->config->get('json_token');
        $res = $this->httpClient->request($url);
        $parsed = (new Data\Parser())->parse($res);

        if(property_exists($parsed,'groups')) {
            return $parsed->groups[0];
        }
        return false;
    }

    private function isPks($group)
    {
        return in_array($this->config->get('kantonalverband_id'), $group->links->hierarchies);
    }
}
