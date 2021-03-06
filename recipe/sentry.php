<?php
/* (c) Viacheslav Ostrovskiy <chelout@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Deployer;

use Closure;
use DateTime;
use Deployer\Utility\Httpie;

desc('Notifying Sentry of deployment');
task(
    'deploy:sentry',
    function () {
        $now = date('c');

        $defaultConfig = [
            'version' => getReleaseGitRef(),
            'refs' => [],
            'ref' => null,
            'commits' => getGitCommitsRefs(),
            'url' => null,
            'date_released' => $now,
            'date_deploy_started' => $now,
            'date_deploy_finished' => $now,
            'sentry_server' => 'https://sentry.io',
            'previous_commit' => null,
            'environment' => get('symfony_env', 'prod'),
            'deploy_name' => null,
        ];

        $config = array_merge($defaultConfig, (array) get('sentry'));
        array_walk(
            $config,
            static function (&$value) {
                if (is_callable($value)) {
                    $value = $value();
                }
            }
        );

        if (
            !isset($config['organization'], $config['token'], $config['version'])
            || (empty($config['projects']) || !is_array($config['projects']))
        ) {
            throw new \RuntimeException(
                <<<EXAMPLE
Required data missing. Please configure sentry: 
set(
    'sentry', 
    [
        'organization' => 'exampleorg', 
        'projects' => [
            'exampleproj', 
            'exampleproje2'
        ], 
        'token' => 'd47828...', 
    ]
);"
EXAMPLE
            );
        }

        $releaseData = array_filter(
            [
                'version' => $config['version'],
                'refs' => $config['refs'],
                'ref' => $config['ref'],
                'url' => $config['url'],
                'commits' => array_slice($config['commits'], 0), // reset keys to serialize as array in json
                'dateReleased' => $config['date_released'],
                'projects' => $config['projects'],
                'previousCommit' => $config['previous_commit'],
            ]
        );

        $releasesApiUrl = $config['sentry_server'] . '/api/0/organizations/' . $config['organization'] . '/releases/';
        $response = Httpie::post(
            $releasesApiUrl
        )
            ->header(sprintf('Authorization: Bearer %s', $config['token']))
            ->header('Content-Type: application/json')
            ->body($releaseData)
            ->getJson();

        if (!isset($response['version'], $response['projects'])) {
            throw new \RuntimeException(sprintf('Unable to create a release: %s', print_r($response, true)));
        }

        writeln(
            sprintf(
                '<info>Sentry:</info> Release of version <comment>%s</comment> ' .
                'for projects: <comment>%s</comment> created successfully.',
                $response['version'],
                implode(', ', array_column($response['projects'], 'slug'))
            )
        );

        $deployData = array_filter(
            [
                'environment' => $config['environment'],
                'name' => $config['deploy_name'],
                'url' => $config['url'],
                'dateStarted' => $config['date_deploy_started'],
                'dateFinished' => $config['date_deploy_finished'],
            ]
        );

        $response = Httpie::post(
            $releasesApiUrl . $response['version'] . '/deploys/'
        )
            ->header(sprintf('Authorization: Bearer %s', $config['token']))
            ->header('Content-Type: application/json')
            ->body($deployData)
            ->getJson();

        if (!isset($response['id'], $response['environment'])) {
            throw new \RuntimeException(sprintf('Unable to create a deployment: %s', print_r($response, true)));
        }

        writeln(
            sprintf(
                '<info>Sentry:</info> Deployment <comment>%s</comment> ' .
                'for environment <comment>%s</comment> created successfully',
                $response['id'],
                $response['environment']
            )
        );
    }
);

function getReleaseGitRef(): Closure
{
    return static function (): string {
        cd('{{release_path}}');

        return trim(run('git log -n 1 --format="%h"'));
    };
}

function getGitCommitsRefs(): Closure
{
    return static function (): array {
        $previousReleaseRevision = null;

        if (has('previous_release')) {
            cd('{{previous_release}}');
            $previousReleaseRevision = trim(run('git rev-parse HEAD'));
        }

        if ($previousReleaseRevision === null) {
            $commitRange = 'HEAD';
        } else {
            $commitRange = $previousReleaseRevision . '..HEAD';
        }

        cd('{{release_path}}');
        $result = run(sprintf('git rev-list --pretty="%s" %s', 'format:%H#%an#%ae#%at', $commitRange));
        $lines = array_filter(
        // limit number of commits for first release with many commits
            array_map('trim', array_slice(explode("\n", $result), 0, 200)),
            static function (string $line): bool {
                return !empty($line) && strpos($line, 'commit') !== 0;
            }
        );


        return array_map(
            static function (string $line): array {
                [$ref, $authorName, $authorEmail, $timestamp] = explode('#', $line);

                return [
                    'id' => $ref,
                    'author_name' => $authorName,
                    'author_email' => $authorEmail,
                    'timestamp' => date(DateTime::ATOM, (int) $timestamp),
                ];
            },
            $lines
        );
    };
}

