<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/** Ultimele erori din jurnalul aplicației, pentru găzduiri fără Terminal (doar super administrator). */
class ErrorLog extends Page
{
    protected string $view = 'filament.pages.error-log';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bug-ant';

    protected static string|\UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Jurnal erori';

    protected static ?string $title = 'Jurnal erori';

    protected static ?int $navigationSort = 91;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('super_admin');
    }

    /**
     * Ultimele erori (cele mai noi primele), din fișierele de jurnal ale ultimelor zile.
     *
     * @return array<int, array{at: string, level: string, message: string, location: ?string, url: ?string}>
     */
    public function entries(int $limit = 40): array
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];
        rsort($files);
        $entries = [];
        foreach (array_slice($files, 0, 7) as $file) {
            // citim doar finalul fișierelor mari
            $handle = fopen($file, 'r');
            $size = filesize($file);
            fseek($handle, max(0, $size - 2_000_000));
            $content = (string) stream_get_contents($handle);
            fclose($handle);

            preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[ T][\d:.+\-]+)\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*?)(?=^\[\d{4}-\d{2}-\d{2}[ T]|\z)/ms', $content, $matches, PREG_SET_ORDER);
            foreach (array_reverse($matches) as $match) {
                $body = $match[3];
                $firstLine = strtok($body, "\n");
                $message = trim(preg_replace('/ \{".*$/', '', (string) $firstLine));
                preg_match('/ at (\S+?:\d+)\)/', $body, $location);
                preg_match('/"url":"([^"]+)"/', $body, $url);
                $entries[] = [
                    'at' => $match[1],
                    'level' => $match[2],
                    'message' => mb_substr($message, 0, 600),
                    'location' => isset($location[1]) ? str_replace(base_path().'/', '', stripslashes($location[1])) : null,
                    'url' => $url[1] ?? null,
                ];
                if (count($entries) >= $limit) {
                    return $entries;
                }
            }
        }

        return $entries;
    }
}
