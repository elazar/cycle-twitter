<?php

namespace Elazar\CycleTwitter;

use Abraham\TwitterOAuth\TwitterOAuth;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DefaultCommand extends Command
{
    protected static $defaultName = 'cycle-twitter';

    protected function configure()
    {
        $this
            ->setDescription('Updates a Twitter list based on interactions')
            ->addArgument('config', InputArgument::REQUIRED, 'Path to configuration file');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = require $input->getArgument('config');
        if (!$this->validateConfig($config, $output)) {
            $output->writeln('<error>Configuration error, aborting</error>');
            return 1;
        }

        $client = $this->getClient($config);

        $userId = $this->getUserId($config['screen_name'], $client);
        if (!$userId) {
            $output->writeln('<error>User not found: ' . $config['screen_name'] . '</error>');
            return 1;
        }

        $listId = $this->getListId($config, $client, $userId);
        if (!$listId) {
            $output->writeln('<error>List not found: ' . $config['list_name'] . '</error>');
            return 1;
        }

        if (!$this->updateList($config, $client, $userId, $listId, $output)) {
            $output->writeln('<error>Failed to update list</error>');
            return 1;
        }

        $output->writeln('<info>List updated successfully</info>');
        return 0;
    }

    private function validateConfig($config, OutputInterface $output)
    {
        if (!is_array($config)) {
            $output->writeln('<error>Configuration file must return an array</error>');
            return false;
        }

        $required = [
            'consumer_key',
            'consumer_secret',
            'access_token',
            'access_token_secret',
            'screen_name',
            'list_name',
        ];

        foreach ($required as $setting) {
            if (empty($config[$setting])) {
                $output->writeln('<error>Required setting missing: ' . $setting);
                return false;
            }
        }

        return true;
    }

    private function getClient(array $config)
    {
        return new TwitterOAuth(
            $config['consumer_key'],
            $config['consumer_secret'],
            $config['access_token'],
            $config['access_token_secret']
        );
    }

    private function getUserId($screenName, TwitterOAuth $client)
    {
        $user = $client->get(
            'users/show',
            [ 'screen_name' => $screenName ]
        );
        if ($user) {
            return $user->id;
        }
        return null;
    }

    private function getListId(array $config, TwitterOAuth $client)
    {
        $lists = $client->get(
            'lists/list',
            [ 'screen_name' => $config['screen_name'] ]
        );
        $lists = array_filter($lists, function ($list) use ($config) {
            return $list->name === $config['list_name'];
        });
        if (empty($lists)) {
            return null;
        }
        return $lists[0]->id;
    }

    private function updateList(
        array $config,
        TwitterOAuth $client,
        $userId,
        $listId,
        OutputInterface $output
    ) {
        $newUsers = [];
        $rawStatuses = [];
        $finished = false;
        $days = $config['days'] ?? 30;
        $excludeUsers = isset($config['exclude_users']) ? (array) $config['exclude_users'] : [];
        $cutoffDate = strtotime("$days days ago");
        $maxId = 0;
        $conditions = [
            'user_id' => $userId,
            'include_rts' => true,
            'exclude_replies' => false,
            'count' => 3200
        ];

        $output->writeln('Getting timeline tweets from last <info>' . $days . '</info> days');
        while (!$finished) {
            $statuses = $client->get(
                'statuses/user_timeline',
                $conditions
            );

            foreach ($statuses as $status) {
                if (strtotime($status->created_at) >= $cutoffDate) {
                    $rawStatuses[] = $status;
                    $maxId = $status->id;
                } else {
                    $finished = true;
                    break;
                }
            }

            if (isset($conditions['max_id']) && $conditions['max_id'] == $maxId) {
                $finished = true;
            }

            $conditions['max_id'] = $maxId;
        }
        $output->writeln('Got <info>' . count($statuses) . '</info> timeline tweets');

        $statuses = array_filter($rawStatuses, function ($status) {
            return $status->in_reply_to_user_id
                || isset($status->retweeted_status)
                || isset($status->quoted_status);
        });

        foreach ($statuses as $status) {
            if (isset($status->in_reply_to_user_id, $new_users)) {
                $newUsers[] = $status->in_reply_to_user_id;
            } elseif (isset($status->retweeted_status)) {
                $newUsers[] = $status->retweeted_status->user->id;
            } elseif (isset($status->quoted_status)) {
                $newUsers[] = $status->quoted_status->user->id;
            } elseif (isset($status->entities)) {
                foreach ($status->entities->user_mentions as $mention) {
                    if (isset($mention->id)) {
                        $newUsers[] = $mention->id;
                    }
                }
            }
        }

        $output->writeln('Getting favorites');
        $favorites = [];
        $finished = false;
        $maxId = 0;
        $conditions = [
            'user_id' => $userId,
            'count' => 200,
        ];

        while (!$finished) {
            $data = $client->get(
                'favorites/list',
                $conditions
            );

            foreach ($data as $favorite) {
                if (strtotime($favorite->created_at) >= $cutoffDate) {
                    $favorites[] = $favorite;
                    $maxId = $favorite->id;
                } else {
                    $finished = true;
                    break;
                }
            }

            if (isset($conditions['max_id']) && $conditions['max_id'] == $maxId) {
                $finished = true;
            }

            $conditions['max_id'] = $maxId;
        }

        foreach ($favorites as $favorite) {
            $newUsers[] = $favorite->user->id;
        }
        $output->writeln('Got <info>' . count($favorites) . '</info> favorites');

        $newUsers = array_unique($newUsers);
        $output->writeln('Found interactions with <info>' . count($newUsers) . '</info> unique users');

        if (!empty($excludeUsers)) {
            $output->writeln('Getting <info>' . count($excludeUsers) . '</info> users to exclude');

            $excludeUserIds = [];
            foreach ($excludeUsers as $username) {
                $userId = $this->getUserId($username, $client);
                if ($userId !== null) {
                    $excludeUserIds[] = $userId;
                } else {
                    $output->writeln('<comment>Unable to find user to exclude: ' . $username . '</comment>');
                }
            }

            $foundUsers = array_intersect($excludeUserIds, $newUsers);
            if (!empty($foundUsers)) {
                $newUsers = array_diff($newUsers, $excludeUserIds);
                $output->writeln('Removed <info>' . count($excludeUserIds) . '</info> excluded users, <info>' . count($newUsers) . '</info> users remaining');
            }
        }

        $output->writeln('Getting current users in list');
        $members = $client->get(
            'lists/members',
            [
                'list_id' => $listId,
                'count' => 5000,
            ]
        );
        $currentUsers = array_map(
            function ($user) {
                return $user->id;
            },
            $members->users ?? []
        );
        $output->writeln('Got <info>' . count($currentUsers) . '</info> current users');

        $usersToRemove = array_diff($currentUsers, $newUsers);
        if (!empty($usersToRemove)) {
            $output->writeln('Removing old users');

            $offset = 0;
            $finished = false;
            $limit = 100;

            while (!$finished) {
                $userSlice = array_slice($usersToRemove, $offset, $limit);
                $response = $client->post(
                    'lists/members/destroy_all',
                    [
                        'list_id' => $listId,
                        'user_id' => implode(',', $userSlice),
                    ]
                );

                if ($client->getLastHttpCode() !== 200) {
                    $error = $response->errors[0];
                    $output->writeln('<error>Error while removing users: ' . $error->code . ' ' . $error->message . '</error>');
                    return false;
                }

                if (count($usersToRemove) > ($offset + $limit)) {
                    $offset += $limit;
                } else {
                    $finished = true;
                }
            }
            $output->writeln('Removed <info>' . count($usersToRemove) . '</info> users from list');
        }

        $usersToAdd = array_diff($newUsers, $currentUsers);
        if (!empty($usersToAdd))  {
            $output->writeln('Adding new users');

            $offset = 0;
            $finished = false;
            $limit = 100;

            while (!$finished) {
                $userSlice = array_slice($usersToAdd, $offset, $limit);
                $response = $client->post(
                    'lists/members/create_all',
                    [
                        'list_id' => $listId,
                        'user_id' => implode(',', $userSlice),
                    ]
                );

                if ($client->getLastHttpCode() !== 200) {
                    $error = $response->errors[0];
                    $output->writeln('<error>Error while adding users: ' . $error->code . ' ' . $error->message . '</error>');
                    return false;
                }

                if (count($usersToAdd) > ($offset + $limit)) {
                    $offset += $limit;
                } else {
                    $finished = true;
                }
            }
            $output->writeln('Adding <info>' . count($usersToAdd) . '</info> users to list');
        }

        return true;
    }
}
