<?php

namespace Realmwatch\Viti\Tripwires;

use Realmwatch\Viti\Contracts\Tripwire;
use Realmwatch\Viti\Decoy;
use Realmwatch\Viti\Severity;
use Realmwatch\Viti\Support\BaitGenerator;
use Realmwatch\Viti\Viti;
use Symfony\Component\HttpFoundation\Response;

/**
 * /.env and /backup.sql answer with a fake, per-site .env and mysqldump
 * instead of a 404. Whoever downloads one has secrets they'll try to use,
 * so it's high on its own. The trip carries the bait's fingerprint, never
 * a value from it.
 *
 * Without a seed (VITI_BAIT_SEED, written by viti:install) there's no bait
 * and nothing is registered: /.env stays a decoy_routes decoy.
 */
final class BaitFiles implements Tripwire
{
    public function register(Viti $viti, array $options): void
    {
        $seed = (string) ($options['seed'] ?? '');
        if ($seed === '') {
            return;
        }
        $bait = new BaitGenerator($seed, (string) config('app.name', 'Laravel'),
            (string) config('app.url', 'http://localhost'), $options['db_host'] ?? null);
        $context = ['bait_fingerprint' => $bait->fingerprint()];

        foreach (['/.env' => 'env', '/backup.sql' => 'sql'] as $path => $file) {
            $viti->decoy(new Decoy($path, 'bait_files', Severity::High, context: $context,
                respond: fn () => $this->serve($bait, $file)));
        }
    }

    /** What nginx would send for a static file it has no MIME type for. */
    private function serve(BaitGenerator $bait, string $file): Response
    {
        $body = $file === 'env' ? $bait->env() : $bait->sql();
        $mtime = $bait->modifiedAt($file);

        return new Response($body, 200, [
            'Content-Type' => 'application/octet-stream',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime).' GMT',
            'ETag' => sprintf('"%x-%x"', $mtime, strlen($body)),
            'Accept-Ranges' => 'bytes',
        ]);
    }
}
