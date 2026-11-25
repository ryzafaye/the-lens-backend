<?php

namespace App\Services;

use App\Models\Feedback;

class FeedbackService
{
    /**
     * Retrieve all reader feedback submissions ordered newest first,
     * formatted for the EIC and Executive Feedback Inbox UI.
     */
    public function getInboxMessages(): array
    {
        return Feedback::orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (Feedback $item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'email' => $item->email,
                    'subject' => $item->subject,
                    'message' => $item->message,
                    'isRead' => (bool) $item->is_read,
                    'is_read' => (bool) $item->is_read,
                    'date' => $item->created_at ? $item->created_at->format('M j, Y') : 'Recent',
                    'fullDate' => $item->created_at ? $item->created_at->format('M j, Y \a\t g:i A') : 'Recent',
                    'timestamp' => $item->created_at ? $item->created_at->getTimestampMs() : 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Mark a specific feedback submission as read.
     */
    public function markAsRead(int $id): Feedback
    {
        $feedback = Feedback::findOrFail($id);
        if (!$feedback->is_read) {
            $feedback->is_read = true;
            $feedback->save();
        }

        return $feedback;
    }
}
