<?php

namespace App\Notifications;

use App\TenantRole;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantInvitationNotification extends Notification
{
    public function __construct(
        public string $workspaceName,
        public TenantRole $role,
        public string $acceptUrl,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Invitation to :workspace', ['workspace' => $this->workspaceName]))
            ->line(__('You have been invited to join :workspace with the :role role.', [
                'workspace' => $this->workspaceName,
                'role' => $this->role->value,
            ]))
            ->action(__('Review invitation'), $this->acceptUrl)
            ->line(__('This invitation expires in seven days. Sign in or register using the email address that received this invitation.'));
    }
}
