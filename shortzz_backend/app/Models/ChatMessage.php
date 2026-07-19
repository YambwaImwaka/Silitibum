<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use HasFactory;
    public $table = 'chat_messages';

    const TYPES = ['text', 'image', 'video', 'audio', 'post', 'story_reply', 'gift', 'gif'];

    public function thread()
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    // Preview line for the thread list (mirrors what the app used to write
    // into the Firestore thread doc).
    public function previewText()
    {
        if ($this->is_unsent) {
            return null;
        }
        switch ($this->message_type) {
            case 'text':
                return mb_substr($this->text_message ?? '', 0, 255);
            case 'image':
                return '📷';
            case 'video':
                return '🎥';
            case 'audio':
                return '🎤';
            case 'post':
                return '📤';
            case 'story_reply':
                return '💬';
            case 'gift':
                return '🎁';
            case 'gif':
                return 'GIF';
            default:
                return null;
        }
    }

    public function serialize()
    {
        return [
            'id' => $this->id,
            'thread_id' => $this->thread_id,
            'sender_id' => $this->sender_id,
            'message_type' => $this->message_type,
            'text_message' => $this->is_unsent ? null : $this->text_message,
            'image_message' => $this->is_unsent ? null : $this->image_message,
            'video_message' => $this->is_unsent ? null : $this->video_message,
            'audio_message' => $this->is_unsent ? null : $this->audio_message,
            'wave_data' => $this->is_unsent ? null : $this->wave_data,
            'post_message' => $this->is_unsent ? null : $this->post_message,
            'story_reply_message' => $this->is_unsent ? null : $this->story_reply_message,
            'is_unsent' => (int) $this->is_unsent,
            // Epoch milliseconds — the app sorts and formats from this.
            'created_at' => $this->created_at ? $this->created_at->valueOf() : null,
        ];
    }
}
