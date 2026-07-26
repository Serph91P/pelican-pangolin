<?php

namespace PelicanDev\PelicanPangolin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use PelicanDev\PelicanPangolin\Enums\SyncStatus;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Services\DesiredStateBuilder;
use PelicanDev\PelicanPangolin\Services\PublicationReconciler;
use PelicanDev\PelicanPangolin\Support\SanitizesErrors;
use Throwable;

final class ReconcileServerPublication implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SanitizesErrors;
    use SerializesModels;

    public int $tries = 25;

    public function __construct(
        public readonly int $publicationId,
        public readonly int $serverId,
        public readonly int $revision,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return "server:{$this->serverId}";
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90, 180];
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->shared()->releaseAfter(15)->expireAfter(300)];
    }

    public function handle(PublicationReconciler $reconciler, DesiredStateBuilder $builder): void
    {
        $publication = ServerPublication::query()->find($this->publicationId);
        if ($publication === null || $publication->desired_revision !== $this->revision) {
            return;
        }

        if ($publication->suspended) {
            $reconciler->disable($publication);

            return;
        }

        $state = $publication->desired_state;
        $desired = $builder->build(
            expectedRevision: $this->revision,
            server: [
                'uuid' => $publication->server_uuid,
                'desired_revision' => $publication->desired_revision,
            ],
            mapping: is_array($state['mapping'] ?? null) ? $state['mapping'] : [],
            allocations: is_array($state['allocations'] ?? null) ? $state['allocations'] : [],
            profileRules: is_array($state['profile_rules'] ?? null) ? $state['profile_rules'] : [],
            overrides: is_array($state['overrides'] ?? null) ? $state['overrides'] : [],
            defaultDomain: is_string($state['default_domain'] ?? null) ? $state['default_domain'] : null,
        );

        $reconciler->reconcile($publication, $desired);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        ServerPublication::query()->whereKey($this->publicationId)->update([
            'status' => SyncStatus::Error->value,
            'last_error' => $this->sanitizeError($exception->getMessage()),
        ]);
    }
}
