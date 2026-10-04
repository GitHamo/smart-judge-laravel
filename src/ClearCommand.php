<?php

declare(strict_types=1);

namespace Potato\SmartJudge\Laravel;

use Illuminate\Console\Command;

/**
 * Forces fresh answers, e.g. after a consumer changed its questions.
 */
final class ClearCommand extends Command
{
    /** @var string */
    protected $signature = 'smart-judge:clear {scope? : the scope to clear; every scope without it}';

    /** @var string */
    protected $description = 'Clear the cached answers of one SmartJudge scope, or of every scope';

    public function handle(ScopeVersions $versions): int
    {
        $scope = $this->argument('scope');
        $scope = \is_string($scope) ? $scope : null;

        $versions->clear($scope);
        $this->components->info(null === $scope ? 'Cleared the answers of every scope.' : \sprintf('Cleared the answers of scope "%s".', $scope));

        return self::SUCCESS;
    }
}
