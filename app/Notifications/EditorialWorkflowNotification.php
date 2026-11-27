<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EditorialWorkflowNotification extends Notification
{
    public function __construct(
        public string $eventType,
        public string $subjectLine,
        public string $headline,
        public string $summary,
        public string $itemLabel,
        public string $itemTitle,
        public string $statusLabel = '',
        public string $actorName = '',
        public string $remarks = '',
        public string $actionText = 'Open Editorial Portal',
        public string $actionUrl = '',
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subjectLine)
            ->view('emails.workflow-notification', [
                'recipientName' => $notifiable->full_name ?? 'Member',
                'eventType' => $this->eventType,
                'subjectLine' => $this->subjectLine,
                'headline' => $this->headline,
                'summary' => $this->summary,
                'itemLabel' => $this->itemLabel,
                'itemTitle' => $this->itemTitle,
                'statusLabel' => $this->statusLabel,
                'actorName' => $this->actorName,
                'remarks' => $this->remarks,
                'actionText' => $this->actionText,
                'actionUrl' => $this->actionUrl,
            ]);
    }
}
