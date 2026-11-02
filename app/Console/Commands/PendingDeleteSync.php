<?php

namespace App\Console\Commands;

use App\Jobs\ImportPendingDeleteList;
use Illuminate\Console\Command;

class PendingDeleteSync extends Command
{
    protected $signature = 'pendingdelete:sync
        {--list=all : dropping-today, pending, dropped-yesterday or all}
        {--force : Reimport a dataset version that already succeeded}';

    protected $description = 'Queue imports of the PendingDelete.net datasets';

    public function handle(): int
    {
        $lists = (array) config('pendingdelete.lists', []);
        $requested = (string) $this->option('list');

        if ($requested !== 'all') {
            if (! in_array($requested, $lists, true)) {
                $this->error('Unknown list: '.$requested);
                $this->line('Available lists: '.implode(', ', $lists));

                return self::FAILURE;
            }

            $lists = [$requested];
        }

        foreach ($lists as $list) {
            ImportPendingDeleteList::dispatch((string) $list, (bool) $this->option('force'));
        }

        $this->info('Queued '.implode(', ', $lists).' from PendingDelete.net.');

        return self::SUCCESS;
    }
}
