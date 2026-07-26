<?php

namespace PelicanDev\PelicanPangolin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Services\PublicationReconciler;

final class RemoveServerPublication implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
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

    public function handle(PublicationReconciler $reconciler): void
    {
        $publication = ServerPublication::query()->find($this->publicationId);
        if ($publication !== null
            && ! $publication->opted_in
            && $publication->deletion_pending
            && $publication->desired_revision === $this->revision) {
            $reconciler->remove($publication, $this->revision);
        }
    }
}
