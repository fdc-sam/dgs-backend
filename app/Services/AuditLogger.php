<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function log(string $action, Model|string $entity, ?array $old = null, ?array $new = null, ?string $entityId = null): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'user_id' => auth()->id() ?? auth('sanctum')->id(),
            'action' => $action,
            'entity_type' => $entity instanceof Model ? $entity->getMorphClass() : $entity,
            'entity_id' => $entity instanceof Model ? (string) $entity->getKey() : $entityId,
            'old_values' => $old ? $this->clean($old) : null,
            'new_values' => $new ? $this->clean($new) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 500) ?: null,
        ]);
    }

    /** Record the dirty attributes of a model that is about to be / was just saved. */
    public function changes(string $action, Model $model, array $original): AuditLog
    {
        $changed = array_keys($model->getChanges());
        $changed = array_diff($changed, ['updated_at']);

        return $this->log(
            $action,
            $model,
            array_intersect_key($original, array_flip($changed)),
            array_intersect_key($model->getAttributes(), array_flip($changed)),
        );
    }

    private function clean(array $values): array
    {
        return array_diff_key($values, array_flip(['password', 'remember_token', 'token']));
    }
}
