<?php

namespace App\Services;

use App\Models\ChatAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    /**
     * Log a chat-related action.
     *
     * @param string $action
     * @param Model $entity
     * @param array $metadata
     * @return ChatAuditLog
     */
    public function logChatAction(string $action, Model $entity, array $metadata = []): ChatAuditLog
    {
        return ChatAuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'metadata' => $metadata,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}

