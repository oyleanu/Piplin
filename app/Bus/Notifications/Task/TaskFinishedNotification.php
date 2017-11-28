<?php

/*
 * This file is part of Piplin.
 *
 * Copyright (C) 2016-2017 piplin.com
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Piplin\Bus\Notifications\Task;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackAttachment;
use Illuminate\Notifications\Messages\SlackMessage;
use NotificationChannels\Webhook\WebhookMessage;
use Piplin\Bus\Notifications\Notification;
use Piplin\Models\Task;
use Piplin\Models\Hook;
use Piplin\Models\Project;

/**
 * Base class for Task notifications.
 */
abstract class TaskFinishedNotification extends Notification
{
    /**
     * @var Project
     */
    protected $project;

    /**
     * @var Task
     */
    protected $deployment;

    /**
     * Create a new notification instance.
     *
     * @param Project    $project
     * @param Task $deployment
     */
    public function __construct(Project $project, Task $deployment)
    {
        $this->project    = $project;
        $this->deployment = $deployment;
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param string $subject
     * @param string $translation
     * @param Hook   $notification
     *
     * @return MailMessage
     */
    protected function buildMailMessage($subject, $translation, Hook $notification)
    {
        $message = trans($translation);

        $table = [
            trans('hooks.project_name')    => $this->project->name,
            trans('hooks.deployed_branch') => $this->deployment->branch,
            trans('hooks.started_at')      => $this->deployment->started_at,
            trans('hooks.finished_at')     => $this->deployment->finished_at,
            trans('hooks.last_committer')  => $this->deployment->committer,
            trans('hooks.last_commit')     => $this->deployment->short_commit,
        ];

        $action = route('deployments', ['id' => $this->deployment->id]);

        $email = (new MailMessage())
            ->view(['notifications.email', 'notifications.email-plain'], [
                'name'  => $notification->name,
                'table' => $table,
            ])
            ->subject(trans($subject))
            ->line($message)
            ->action(trans('hooks.deployment_details'), $action);

        if (!empty($this->deployment->reason)) {
            $email->line(trans('hooks.deployment_reason', ['reason' => $this->deployment->reason]));
        }

        return $email;
    }

    /**
     * Get the slack version of the notification.
     *
     * @param string $translation
     * @param Hook   $notification
     *
     * @return SlackMessage
     */
    protected function buildSlackMessage($translation, Hook $notification)
    {
        $message = trans($translation);

        $fields = [
            trans('hooks.project') => sprintf(
                '<%s|%s>',
                route('projects', ['id' => $this->project->id]),
                $this->project->name
            ),
            trans('hooks.commit') => $this->deployment->commit_url ? sprintf(
                '<%s|%s>',
                $this->deployment->commit_url,
                $this->deployment->short_commit
            ) : $this->deployment->short_commit,
            trans('hooks.committer') => $this->deployment->committer,
            trans('hooks.branch')    => $this->deployment->branch,
        ];

        return (new SlackMessage())
            ->from(null, $notification->config->icon)
            ->to($notification->config->channel)
            ->attachment(function (SlackAttachment $attachment) use ($message, $fields) {
                $attachment
                    ->content(sprintf($message, sprintf(
                        '<%s|#%u>',
                        route('deployments', ['id' => $this->deployment->id]),
                        $this->deployment->id
                    )))
                    ->fallback(sprintf($message, '#' . $this->deployment->id))
                    ->fields($fields)
                    ->footer(trans('app.name'))
                    ->timestamp($this->deployment->finished_at);
            });
    }

    /**
     * Get the dingtalk version of the notification.
     *
     * @param string $translation
     * @param Hook   $notification
     *
     * @return WebhookMessage
     */
    protected function buildDingtalkMessage($translation, Hook $notification)
    {
        $message = trans($translation);
        $subject = sprintf($message, '#' . $this->deployment->id);
        $commit  = $this->deployment->commit_url ? sprintf(
            '[%s](%s)',
            $this->deployment->short_commit,
            $this->deployment->commit_url
        ) : $this->deployment->short_commit;
        $deployment_url = route('deployments', ['id' => $this->deployment->id]);

        $content = trans('hooks.project') . ': ' . sprintf(
            '[%s](%s)',
            $this->project->name,
            route('projects', ['id' => $this->project->id])
        ) . ' ';
        $content .= trans('hooks.commit') . ': ' . $commit . "\n\n";

        $content .= trans('hooks.committer') . ':' . $this->deployment->committer . ' ';
        $content .= trans('hooks.branch') . ':' . $this->deployment->branch . "\n\n";

        if (!empty($this->deployment->reason)) {
            $content .= '> ' . trans('hooks.deployment_reason', ['reason' => $this->deployment->reason]) . "\n\n";
        }

        $text = '#### ' . $subject . "\n"
                 . $content
                 . '##### [' . trans('hooks.deployment_details') . '](' . $deployment_url . ")\n\n";
        $atMobiles = !empty($notification->config->at_mobiles) ? explode(',', $notification->config->at_mobiles) : [];

        return (new WebhookMessage())
            ->data([
                'msgtype'  => 'markdown',
                'markdown' => [
                    'title' => $subject,
                    'text'  => $text,
                ],
                'at' => [
                    'atMobiles' => $atMobiles,
                    'isAtAll'   => !!$notification->config->is_at_all,
                ],
            ])->header('Content-Type', 'application/json;charset=utf-8');
    }

    /**
     * Get the webhook version of the notification.
     *
     * @param string $event
     * @param Hook   $notification
     *
     * @return WebhookMessage
     */
    protected function buildWebhookMessage($event, Hook $notification)
    {
        return (new WebhookMessage())
            ->data(array_merge(array_only(
                $this->deployment->attributesToArray(),
                ['id', 'branch', 'started_at', 'finished_at', 'commit', 'source', 'reason']
            ), [
                'project'      => $this->deployment->project_name,
                'committed_by' => $this->deployment->committer,
                'started_by'   => $this->deployment->deployer_name,
                'status'       => ($event === 'deployment_succeeded') ? 'success' : 'failure',
                'url'          => route('deployments', ['id' => $this->deployment->id]),
            ]))
            ->header('X-Piplin-Project-Id', $notification->project_id)
            ->header('X-Piplin-Notification-Id', $notification->id)
            ->header('X-Piplin-Event', $event);
    }
}