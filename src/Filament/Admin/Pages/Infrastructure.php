<?php

namespace PelicanDev\PelicanPangolin\Filament\Admin\Pages;

use Filament\Pages\Page;
use InvalidArgumentException;
use PelicanDev\PelicanPangolin\Services\InfrastructureSnippetGenerator;

final class Infrastructure extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-code';

    protected static ?string $navigationLabel = 'Pangolin infrastructure';

    protected string $view = 'pelican-pangolin::infrastructure';

    public static function canAccess(): bool
    {
        return user()?->isRootAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        abort_unless(self::canAccess(), 403);
        try {
            return ['snippets' => app(InfrastructureSnippetGenerator::class)->generate(
                (string) config('pelican-pangolin.tcp_ports'), (string) config('pelican-pangolin.udp_ports'),
            ), 'validationError' => null];
        } catch (InvalidArgumentException $exception) {
            return ['snippets' => [], 'validationError' => $exception->getMessage()];
        }
    }
}
