<?php

namespace App\Console\Commands;

use App\Modules\Cms\Services\PageEditor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aw:cms-publish-scheduled')]
#[Description('Publică paginile programate ajunse la termen')]
class CmsPublishScheduled extends Command
{
    public function handle(PageEditor $editor): int
    {
        $count = $editor->publishDue();
        if ($count > 0) {
            $this->info("{$count} pagini publicate.");
        }

        return self::SUCCESS;
    }
}
