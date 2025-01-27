<?php

namespace App\Notifications\Notifications\Application;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Team;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\DiscordChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class DeployedWithErrorNotification extends Notification implements ShouldQueue
{
    use Queueable;
    public Application $application;
    public string $deployment_uuid;
    public ApplicationPreview|null $preview;

    public string $application_name;
    public string|null $deployment_url = null;
    public string $project_uuid;
    public string $environment_name;
    public string $fqdn;

    public function __construct(Application $application, string $deployment_uuid, ApplicationPreview|null $preview)
    {
        $this->application = $application;
        $this->deployment_uuid = $deployment_uuid;
        $this->preview = $preview;

        $this->application_name = data_get($application, 'name');
        $this->project_uuid = data_get($application, 'environment.project.uuid');
        $this->environment_name = data_get($application, 'environment.name');
        $this->fqdn = data_get($application, 'fqdn');
        if (Str::of($this->fqdn)->explode(',')->count() > 1) {
            $this->fqdn = Str::of($this->fqdn)->explode(',')->first();
        }
        $this->deployment_url =  base_url() . "/project/{$this->project_uuid}/{$this->environment_name}/application/{$this->application->uuid}/deployment/{$this->deployment_uuid}";
    }
    public function via(object $notifiable): array
    {
        $channels = [];
        $isEmailEnabled = data_get($notifiable, 'smtp.enabled');
        $isDiscordEnabled = data_get($notifiable, 'discord.enabled');
        $isSubscribedToEmailDeployments = data_get($notifiable, 'smtp_notifications.deployments');
        $isSubscribedToDiscordDeployments = data_get($notifiable, 'discord_notifications.deployments');

        if ($isEmailEnabled && $isSubscribedToEmailDeployments) {
            $channels[] = EmailChannel::class;
        }
        if ($isDiscordEnabled && $isSubscribedToDiscordDeployments) {
            $channels[] = DiscordChannel::class;
        }
        return $channels;
    }
    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        $pull_request_id = data_get($this->preview, 'pull_request_id', 0);
        $fqdn = $this->fqdn;
        if ($pull_request_id === 0) {
            $mail->subject('❌ Deployment failed of ' . $this->application_name . '.');
        } else {
            $fqdn = $this->preview->fqdn;
            $mail->subject('❌ Pull request #' . $this->preview->pull_request_id . ' of ' . $this->application_name . ' deployment failed.');
        }

        $mail->view('emails.application-deployed-with-error', [
            'name' => $this->application_name,
            'fqdn' => $fqdn,
            'deployment_url' => $this->deployment_url,
            'pull_request_id' => data_get($this->preview, 'pull_request_id', 0),
        ]);
        return $mail;
    }

    public function toDiscord(): string
    {
        if ($this->preview) {
            $message = '❌ Pull request #' . $this->preview->pull_request_id . ' of **' . $this->application_name . '** (' . $this->preview->fqdn . ') deployment failed: ';
            $message .= '[View Deployment Logs](' . $this->deployment_url . ')';
        } else {
            $message = '❌ Deployment failed of **' . $this->application_name . '** (' . $this->fqdn . '): ';
            $message .= '[View Deployment Logs](' . $this->deployment_url . ')';
        }
        return $message;
    }
}
