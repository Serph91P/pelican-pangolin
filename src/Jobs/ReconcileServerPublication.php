<?php

namespace PelicanDev\PelicanPangolin\Jobs;

use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
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

    public function handle(DesiredStateBuilder $builder): void
    {
        $publication = ServerPublication::query()->find($this->publicationId);
        if ($publication === null || ! $publication->opted_in || $publication->desired_revision !== $this->revision) {
            return;
        }
        try {
            // Resolve inside the failure boundary: empty/invalid connection settings must be visible.
            $reconciler = app(PublicationReconciler::class);
            if ($publication->suspended) {
                $reconciler->disable($publication);

                return;
            }
            $state = $publication->desired_state;
            $desired = $builder->build(
                expectedRevision: $this->revision,
                server: ['uuid' => $publication->server_uuid, 'desired_revision' => $publication->desired_revision],
                mapping: is_array($state['mapping'] ?? null) ? $state['mapping'] : [],
                allocations: is_array($state['allocations'] ?? null) ? $state['allocations'] : [],
                profileRules: [],
                overrides: is_array($state['overrides'] ?? null) ? $state['overrides'] : [],
                defaultDomain: is_string($state['default_domain'] ?? null) ? $state['default_domain'] : null,
            );
            $reconciler->reconcile($publication, $desired);
        } catch (DomainException|InvalidArgumentException $exception) {
            if (isset($reconciler)) {
                try {
                    $reconciler->disable($publication);
                } catch (Throwable $cleanupFailure) {
                    $this->failed($cleanupFailure);
                    throw $cleanupFailure;
                }
            }
            ServerPublication::query()->whereKey($publication->id)->where('desired_revision', $this->revision)->update([
                'status' => SyncStatus::Blocked, 'last_error' => $this->sanitizeError($exception->getMessage()),
            ]);
        } catch (Throwable $exception) {
            $this->failed($exception);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            ServerPublication::query()->whereKey($this->publicationId)->where('desired_revision', $this->revision)->update([
                'status' => SyncStatus::Error->value, 'last_error' => $this->sanitizeError($exception->getMessage()),
            ]);
        }
    }
}
